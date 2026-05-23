<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai_release_guardian\Audit\Result;

/**
 * Append-only ring buffer of audit runs, persisted in State.
 * Bounded so State payloads don't grow unbounded over time.
 */
final class AuditLogWriter {

  private const STATE_KEY  = 'ai_release_guardian.audit_log';
  private const MAX_ENTRIES = 50;

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  public function append(Result $result, array $context = []): int {
    $log = $this->state->get(self::STATE_KEY, []);
    $id  = $this->nextId($log);

    $log[] = [
      'id'          => $id,
      'timestamp'   => $this->time->getCurrentTime(),
      'verdict'     => $result->verdict->value,
      'findings'    => array_map(static fn($f) => $f->toArray(), $result->findings),
      'meta'        => $result->meta,
      'context'     => $context,
      'config_hash' => $result->configHash,
    ];

    if (count($log) > self::MAX_ENTRIES) {
      $log = array_slice($log, -self::MAX_ENTRIES);
    }

    $this->state->set(self::STATE_KEY, $log);
    return $id;
  }

  /** @return list<array> Most-recent first. */
  public function recent(int $limit = 50): array {
    $log = $this->state->get(self::STATE_KEY, []);
    return array_slice(array_reverse($log), 0, $limit);
  }

  public function get(int $id): ?array {
    foreach ($this->state->get(self::STATE_KEY, []) as $row) {
      if ((int) $row['id'] === $id) {
        return $row;
      }
    }
    return NULL;
  }

  private function nextId(array $log): int {
    $max = 0;
    foreach ($log as $row) {
      $max = max($max, (int) ($row['id'] ?? 0));
    }
    return $max + 1;
  }

}
