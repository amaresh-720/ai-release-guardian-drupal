<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai_release_guardian\Audit\Finding;
use Drupal\ai_release_guardian\Audit\Result;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Sends a sanitized config diff to an LLM and returns an audit Result.
 *
 * Behaviour:
 *  - Caches by content hash so re-running on the same diff is free.
 *  - Strips long strings, emails, and known secret-named fields before send.
 *  - On provider failure, returns an UNVERIFIED Result. Never throws.
 */
final class ConfigAuditService {

  private const STATE_KEY_API   = 'ai_release_guardian.groq_api_key';
  private const STATE_KEY_MODEL = 'ai_release_guardian.groq_model';
  private const STATE_KEY_URL   = 'ai_release_guardian.groq_base_url';

  // Bump the version suffix if prompt or response schema changes.
  private const CACHE_PREFIX = 'ai_release_guardian:audit:v1:';

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {
    $this->logger = $loggerFactory->get('ai_release_guardian');
  }

  /**
   * @param list<array> $diff
   *   Output of ConfigDiffService::diff().
   * @param array{blocking_severities?:list<string>, cache_ttl?:int, timeout?:int} $options
   */
  public function audit(array $diff, array $options = []): Result {
    $hash       = hash('sha256', json_encode($diff, JSON_UNESCAPED_SLASHES));
    $blocking   = $options['blocking_severities'] ?? ['critical', 'high'];
    $cacheTtl   = (int) ($options['cache_ttl'] ?? 3600);

    if ($cacheTtl > 0 && $hit = $this->cache->get(self::CACHE_PREFIX . $hash)) {
      $this->logger->info('Audit cache HIT for @hash', ['@hash' => substr($hash, 0, 8)]);
      /** @var Result $cached */
      $cached = $hit->data;
      return Result::fromFindings(
        $cached->findings,
        $blocking,
        $hash,
        [...$cached->meta, 'cache_hit' => TRUE],
      );
    }

    if ($diff === []) {
      return Result::fromFindings([], $blocking, $hash, [
        'cache_hit'  => FALSE,
        'reason'     => 'No pending config changes.',
        'latency_ms' => 0,
      ]);
    }

    $sanitized = $this->sanitize($diff);
    $started   = $this->time->getCurrentMicroTime();

    try {
      $payload = $this->callProvider($sanitized, (int) ($options['timeout'] ?? 20));
    }
    catch (ConnectException | RequestException | GuzzleException $e) {
      $this->logger->critical('AI provider call failed: @msg', ['@msg' => $e->getMessage()]);
      return Result::unverified($e->getMessage(), $hash, ['provider_exception' => $e::class]);
    }
    catch (\JsonException $e) {
      $this->logger->error('AI returned malformed JSON: @msg', ['@msg' => $e->getMessage()]);
      return Result::unverified('Provider returned malformed JSON', $hash);
    }

    $latencyMs = (int) (($this->time->getCurrentMicroTime() - $started) * 1000);

    $findings = array_map(
      static fn (array $row) => Finding::fromArray($row),
      $payload['findings'] ?? [],
    );

    $result = Result::fromFindings($findings, $blocking, $hash, [
      'cache_hit'   => FALSE,
      'provider'    => 'groq',
      'model'       => (string) $this->state->get(self::STATE_KEY_MODEL, 'llama-3.1-8b-instant'),
      'latency_ms'  => $latencyMs,
      'token_usage' => $payload['_usage'] ?? NULL,
    ]);

    if ($cacheTtl > 0) {
      $this->cache->set(
        self::CACHE_PREFIX . $hash,
        $result,
        $this->time->getRequestTime() + $cacheTtl,
        ['config:ai_release_guardian.settings'],
      );
    }

    return $result;
  }

  /**
   * Strip likely PII or secrets before the diff leaves the server.
   * We keep YAML keys and structure; we redact long strings, emails, and
   * values under any key in the secret-name list.
   */
  private function sanitize(array $diff): array {
    $secretNames = ['password', 'secret', 'api_key', 'token', 'private_key'];

    $walk = function (mixed $node) use (&$walk, $secretNames): mixed {
      if (is_array($node)) {
        $out = [];
        foreach ($node as $k => $v) {
          if (is_string($k) && in_array(strtolower($k), $secretNames, TRUE)) {
            $out[$k] = '<REDACTED:secret-field>';
            continue;
          }
          $out[$k] = $walk($v);
        }
        return $out;
      }
      if (is_string($node)) {
        if (strlen($node) > 256) {
          return '<REDACTED:long-string>';
        }
        if (preg_match('/^[\w.+-]+@[\w-]+\.[\w.-]+$/', $node)) {
          return '<REDACTED:email>';
        }
      }
      return $node;
    };

    return array_map($walk, $diff);
  }

  /**
   * One round-trip to an OpenAI-compatible /chat/completions endpoint.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   * @throws \JsonException
   */
  private function callProvider(array $sanitizedDiff, int $timeoutSec): array {
    $apiKey  = (string) $this->state->get(self::STATE_KEY_API, '');
    $model   = (string) $this->state->get(self::STATE_KEY_MODEL, 'llama-3.1-8b-instant');
    $baseUrl = (string) $this->state->get(self::STATE_KEY_URL, 'https://api.groq.com/openai/v1');

    if ($apiKey === '') {
      throw new RequestException(
        sprintf('AI provider API key not set (State: %s)', self::STATE_KEY_API),
        new \GuzzleHttp\Psr7\Request('POST', $baseUrl),
      );
    }

    $response = $this->httpClient->request('POST', rtrim($baseUrl, '/') . '/chat/completions', [
      'timeout' => $timeoutSec,
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type'  => 'application/json',
      ],
      'json' => [
        'model'           => $model,
        'temperature'     => 0.1,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
          ['role' => 'system', 'content' => $this->systemPrompt()],
          ['role' => 'user',   'content' => $this->userPrompt($sanitizedDiff)],
        ],
      ],
    ]);

    $body = (string) $response->getBody();
    $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);

    $content = $decoded['choices'][0]['message']['content'] ?? '{}';
    $payload = json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
    $payload['_usage'] = $decoded['usage'] ?? NULL;
    return $payload;
  }

  private function systemPrompt(): string {
    return <<<'PROMPT'
You are a senior Drupal release engineer auditing pending configuration
changes for a production deployment. You receive a JSON array of config
diffs (each with change_type, name, active, sync). Reply with a JSON
object of the form:

{
  "findings": [
    {
      "severity": "critical" | "high" | "medium" | "low" | "info",
      "config_name": "<the config name>",
      "title": "<short, concrete problem statement>",
      "detail": "<why this is risky in production>",
      "recommendation": "<concrete next action>"
    }
  ]
}

Severity guide:
- critical: Security risk to anonymous/authenticated users (dangerous
  permission granted to anonymous, debug endpoints exposed).
- high: Deployment hazard (developer module enabled, verbose error_level,
  mail backend pointed at a dev sink).
- medium: Likely incident-causing best-practice violation (cache disabled,
  asset aggregation off, very large items_per_page change).
- low: Style/maintainability concerns.
- info: Notable but benign change worth confirming.

Rules:
- Be terse. Each detail at most 240 characters.
- Only report real risks. If everything is fine, return {"findings": []}.
- Never invent config names; quote the one you saw in the input.
- Do not output anything outside the JSON object.
PROMPT;
  }

  private function userPrompt(array $diff): string {
    return "Audit this pending Drupal config import:\n\n"
      . json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

}
