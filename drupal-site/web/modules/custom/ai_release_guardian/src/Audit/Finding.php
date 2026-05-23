<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Audit;

final class Finding {

  public function __construct(
    public readonly Severity $severity,
    public readonly string $configName,
    public readonly string $title,
    public readonly string $detail,
    public readonly string $recommendation,
  ) {}

  public static function fromArray(array $row): self {
    return new self(
      severity:       Severity::tryParse((string) ($row['severity'] ?? 'info')),
      configName:     trim((string) ($row['config_name'] ?? 'unknown')),
      title:          trim((string) ($row['title'] ?? 'Unlabeled finding')),
      detail:         trim((string) ($row['detail'] ?? '')),
      recommendation: trim((string) ($row['recommendation'] ?? '')),
    );
  }

  public function toArray(): array {
    return [
      'severity'       => $this->severity->value,
      'config_name'    => $this->configName,
      'title'          => $this->title,
      'detail'         => $this->detail,
      'recommendation' => $this->recommendation,
    ];
  }

}
