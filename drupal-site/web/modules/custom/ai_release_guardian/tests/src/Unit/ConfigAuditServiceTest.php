<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_release_guardian\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\ai_release_guardian\Audit\Severity;
use Drupal\ai_release_guardian\Audit\Verdict;
use Drupal\ai_release_guardian\Service\ConfigAuditService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * @coversDefaultClass \Drupal\ai_release_guardian\Service\ConfigAuditService
 * @group ai_release_guardian
 */
final class ConfigAuditServiceTest extends UnitTestCase {

  /** @test */
  public function it_returns_pass_when_diff_is_empty(): void {
    $service = $this->makeService(new MockHandler([]));
    $result = $service->audit([]);

    $this->assertSame(Verdict::PASS, $result->verdict);
    $this->assertSame([], $result->findings);
  }

  /** @test */
  public function it_parses_critical_findings_and_blocks(): void {
    $body = json_encode([
      'choices' => [
        ['message' => ['content' => json_encode([
          'findings' => [
            [
              'severity' => 'critical',
              'config_name' => 'user.role.anonymous',
              'title' => 'Dangerous permission granted to anonymous',
              'detail' => 'administer site configuration permission',
              'recommendation' => 'Remove the permission before deploying.',
            ],
          ],
        ])]],
      ],
      'usage' => ['total_tokens' => 120],
    ]);

    $service = $this->makeService(new MockHandler([
      new Response(200, [], $body),
    ]));

    $result = $service->audit([
      ['change_type' => 'update', 'name' => 'user.role.anonymous', 'active' => [], 'sync' => []],
    ], ['cache_ttl' => 0]);

    $this->assertSame(Verdict::BLOCK, $result->verdict);
    $this->assertCount(1, $result->findings);
    $this->assertSame(Severity::CRITICAL, $result->findings[0]->severity);
    $this->assertSame(120, $result->meta['token_usage']['total_tokens']);
  }

  /** @test */
  public function provider_failure_returns_unverified_not_throws(): void {
    $service = $this->makeService(new MockHandler([
      new ConnectException('Connection refused', new Request('POST', 'http://x')),
    ]));

    $result = $service->audit([
      ['change_type' => 'update', 'name' => 'something', 'active' => [], 'sync' => []],
    ], ['cache_ttl' => 0]);

    $this->assertSame(Verdict::UNVERIFIED, $result->verdict);
    $this->assertNotEmpty($result->findings);
  }

  /** @test */
  public function sanitizer_redacts_secret_fields_and_long_strings(): void {
    // Reflection cheap-and-cheerful peek into sanitize().
    $service = $this->makeService(new MockHandler([new Response(200, [], '{"choices":[{"message":{"content":"{\"findings\":[]}"}}]}')]));
    $ref = new \ReflectionClass($service);
    $method = $ref->getMethod('sanitize');
    $method->setAccessible(TRUE);

    $cleaned = $method->invoke($service, [[
      'change_type' => 'update',
      'name' => 'mailer.settings',
      'active' => ['password' => 'super-secret', 'note' => str_repeat('x', 300)],
      'sync'   => ['contact' => 'admin@example.com'],
    ]]);

    $this->assertSame('<REDACTED:secret-field>', $cleaned[0]['active']['password']);
    $this->assertSame('<REDACTED:long-string>', $cleaned[0]['active']['note']);
    $this->assertSame('<REDACTED:email>',       $cleaned[0]['sync']['contact']);
  }

  // ---------------------------------------------------------------------------

  private function makeService(MockHandler $handler): ConfigAuditService {
    $http = new Client(['handler' => HandlerStack::create($handler)]);

    $state = $this->createMock(StateInterface::class);
    $state->method('get')->willReturnCallback(static function (string $key, $default = NULL) {
      return match ($key) {
        'ai_release_guardian.groq_api_key'  => 'test-key',
        'ai_release_guardian.groq_model'    => 'test-model',
        'ai_release_guardian.groq_base_url' => 'https://example.test/v1',
        default => $default,
      };
    });

    $logger = $this->createMock(LoggerChannelInterface::class);
    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);

    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);

    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentMicroTime')->willReturn(0.0);
    $time->method('getRequestTime')->willReturn(time());

    return new ConfigAuditService($state, $factory, $http, $cache, $time);
  }

}
