<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Service;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Diff between active config (database) and the sync directory (filesystem).
 * Same source `drush config:import` uses, so we audit exactly what would deploy.
 */
final class ConfigDiffService {

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly StorageInterface $activeStorage,
    private readonly StorageInterface $syncStorage,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_release_guardian');
  }

  /**
   * @param list<string> $excludePatterns fnmatch() patterns to skip.
   * @return list<array{change_type:string, name:string, active:?array, sync:?array}>
   */
  public function diff(array $excludePatterns = []): array {
    $active = $this->activeStorage->listAll();
    $sync   = $this->syncStorage->listAll();
    $names  = array_unique(array_merge($active, $sync));
    sort($names);

    $diff = [];
    foreach ($names as $name) {
      foreach ($excludePatterns as $pattern) {
        if (fnmatch($pattern, $name)) {
          continue 2;
        }
      }

      $inActive = in_array($name, $active, TRUE);
      $inSync   = in_array($name, $sync, TRUE);

      $activeVal = $inActive ? $this->activeStorage->read($name) : NULL;
      $syncVal   = $inSync   ? $this->syncStorage->read($name)   : NULL;

      if ($inActive && $inSync && $activeVal === $syncVal) {
        continue;
      }

      $diff[] = [
        'change_type' => match (TRUE) {
          !$inActive && $inSync => 'create',
          $inActive && !$inSync => 'delete',
          default               => 'update',
        },
        'name'   => $name,
        'active' => $activeVal === FALSE ? NULL : $activeVal,
        'sync'   => $syncVal === FALSE ? NULL : $syncVal,
      ];
    }

    $this->logger->info('Computed config diff: @n entries', ['@n' => count($diff)]);
    return $diff;
  }

  public function hash(array $diff): string {
    return hash('sha256', json_encode($diff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

}
