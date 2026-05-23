<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Audit;

enum Verdict: string {

  case PASS       = 'pass';
  case WARN       = 'warn';
  case BLOCK      = 'block';
  case UNVERIFIED = 'unverified';

  // Drush command returns this as its shell exit code.
  public function exitCode(): int {
    return match ($this) {
      self::PASS       => 0,
      self::WARN       => 0,
      self::BLOCK      => 2,
      self::UNVERIFIED => 3,
    };
  }

  public function label(): string {
    return match ($this) {
      self::PASS       => '✓ APPROVED',
      self::WARN       => '⚠ WARN',
      self::BLOCK      => '✗ BLOCKED',
      self::UNVERIFIED => '? UNVERIFIED',
    };
  }

}
