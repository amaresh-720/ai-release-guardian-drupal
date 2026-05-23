<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Audit;

final class Result {

  /**
   * @param list<Finding>          $findings
   * @param array<string,mixed>    $meta
   */
  private function __construct(
    public readonly Verdict $verdict,
    public readonly array $findings,
    public readonly array $meta,
    public readonly string $configHash,
  ) {}

  /**
   * @param list<Finding> $findings
   * @param list<string>  $blockingSeverities  e.g. ['critical', 'high']
   */
  public static function fromFindings(
    array $findings,
    array $blockingSeverities,
    string $configHash,
    array $meta = [],
  ): self {
    if ($findings === []) {
      return new self(Verdict::PASS, [], $meta, $configHash);
    }
    $blocking = array_flip($blockingSeverities);
    foreach ($findings as $f) {
      if (isset($blocking[$f->severity->value])) {
        return new self(Verdict::BLOCK, $findings, $meta, $configHash);
      }
    }
    return new self(Verdict::WARN, $findings, $meta, $configHash);
  }

  // Circuit-breaker result when the LLM call fails. Never silently PASS.
  public static function unverified(string $reason, string $configHash, array $meta = []): self {
    return new self(
      verdict: Verdict::UNVERIFIED,
      findings: [
        new Finding(
          severity: Severity::HIGH,
          configName: '',
          title: 'AI audit could not complete',
          detail: $reason,
          recommendation: 'Review pending config manually before deploying.',
        ),
      ],
      meta: $meta + ['unverified_reason' => $reason],
      configHash: $configHash,
    );
  }

  /** @return array<string, list<Finding>> */
  public function groupedBySeverity(): array {
    $by = [];
    foreach ($this->findings as $f) {
      $by[$f->severity->value][] = $f;
    }
    uksort($by, fn($a, $b) => Severity::from($a)->rank() <=> Severity::from($b)->rank());
    return $by;
  }

  public function toArray(): array {
    return [
      'verdict'     => $this->verdict->value,
      'findings'    => array_map(static fn(Finding $f) => $f->toArray(), $this->findings),
      'meta'        => $this->meta,
      'config_hash' => $this->configHash,
    ];
  }

}
