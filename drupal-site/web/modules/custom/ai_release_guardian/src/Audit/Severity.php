<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Audit;

enum Severity: string {

  case CRITICAL = 'critical';
  case HIGH     = 'high';
  case MEDIUM   = 'medium';
  case LOW      = 'low';
  case INFO     = 'info';

  public function rank(): int {
    return match ($this) {
      self::CRITICAL => 0,
      self::HIGH     => 1,
      self::MEDIUM   => 2,
      self::LOW      => 3,
      self::INFO     => 4,
    };
  }

  public function emoji(): string {
    return match ($this) {
      self::CRITICAL => '🔴',
      self::HIGH     => '🟠',
      self::MEDIUM   => '🟡',
      self::LOW      => '🔵',
      self::INFO     => '⚪',
    };
  }

  // Models sometimes return synonyms (warn, err, severe). Map them in.
  public static function tryParse(string $raw): self {
    return match (strtolower(trim($raw))) {
      'critical', 'crit', 'severe' => self::CRITICAL,
      'high', 'error', 'err'       => self::HIGH,
      'medium', 'med', 'warning', 'warn' => self::MEDIUM,
      'low', 'minor'               => self::LOW,
      default                      => self::INFO,
    };
  }

}
