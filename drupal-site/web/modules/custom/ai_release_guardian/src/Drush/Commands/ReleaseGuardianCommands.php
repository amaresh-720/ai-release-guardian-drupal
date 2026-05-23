<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai_release_guardian\Audit\Severity;
use Drupal\ai_release_guardian\Audit\Verdict;
use Drupal\ai_release_guardian\Service\AuditLogWriter;
use Drupal\ai_release_guardian\Service\ConfigAuditService;
use Drupal\ai_release_guardian\Service\ConfigDiffService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the AI Release Guardian module.
 *
 *   drush ai:audit-release        Run an audit on /config/sync vs active.
 *   drush ai:audit-release --json Output machine-readable JSON.
 *   drush ai:audit-release --force Override BLOCK exit code (still logs).
 */
final class ReleaseGuardianCommands extends DrushCommands {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigDiffService $diff,
    private readonly ConfigAuditService $audit,
    private readonly AuditLogWriter $log,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'ai:audit-release', aliases: ['arg', 'release-audit'])]
  #[CLI\Help(description: 'Audit pending Drupal config import with an LLM.')]
  #[CLI\Option(name: 'json', description: 'Print result as JSON instead of formatted text.')]
  #[CLI\Option(name: 'force', description: 'Always exit 0, even on BLOCK verdict (audit still logged).')]
  #[CLI\Option(name: 'no-cache', description: 'Bypass the audit cache for this run.')]
  #[CLI\Usage(name: 'drush ai:audit-release', description: 'Run a release audit and print the report.')]
  public function audit(array $options = ['json' => FALSE, 'force' => FALSE, 'no-cache' => FALSE]): int {
    $settings = $this->configFactory->get('ai_release_guardian.settings');
    $excludes = $settings->get('excluded_config_patterns') ?? [];
    $blocking = $settings->get('blocking_severities') ?? ['critical', 'high'];
    $timeout  = (int) ($settings->get('request_timeout') ?? 20);
    $cacheTtl = $options['no-cache'] ? 0 : (int) ($settings->get('cache_ttl') ?? 3600);

    $this->io()->section('AI Release Guardian');
    $this->io()->text('Computing config diff (sync vs active)...');

    $diff = $this->diff->diff($excludes);
    $this->io()->text(sprintf('Found %d pending change(s).', count($diff)));

    if ($diff === []) {
      $this->io()->success('No pending config changes. Nothing to audit.');
      return 0;
    }

    $this->io()->text('Sending sanitized diff to provider...');
    $result = $this->audit->audit($diff, [
      'blocking_severities' => $blocking,
      'cache_ttl'           => $cacheTtl,
      'timeout'             => $timeout,
    ]);

    $logId = $this->log->append($result, [
      'triggered_by' => 'drush',
      'force'        => (bool) $options['force'],
    ]);

    if ($options['json']) {
      $this->output()->writeln(json_encode($result->toArray() + ['log_id' => $logId], JSON_PRETTY_PRINT));
      return $options['force'] ? 0 : $result->verdict->exitCode();
    }

    $this->renderReport($result, $logId);

    return $options['force'] ? 0 : $result->verdict->exitCode();
  }

  #[CLI\Command(name: 'ai:audit-list')]
  #[CLI\Help(description: 'List recent audit runs.')]
  public function listAudits(): void {
    $rows = [];
    foreach ($this->log->recent(20) as $row) {
      $rows[] = [
        $row['id'],
        date('Y-m-d H:i', $row['timestamp']),
        Verdict::tryFrom($row['verdict'])?->label() ?? $row['verdict'],
        count($row['findings']),
        $row['meta']['latency_ms'] ?? '-',
      ];
    }
    $this->io()->table(['ID', 'When', 'Verdict', 'Findings', 'Latency (ms)'], $rows);
  }

  private function renderReport(\Drupal\ai_release_guardian\Audit\Result $result, int $logId): void {
    $io = $this->io();

    $meta = $result->meta;
    $io->newLine();
    $io->text(sprintf(
      ' Verdict:   %s   |   Provider: %s   |   Latency: %s ms   |   Cache: %s',
      $result->verdict->label(),
      $meta['provider'] ?? '-',
      $meta['latency_ms'] ?? '-',
      !empty($meta['cache_hit']) ? 'HIT' : 'MISS',
    ));
    $io->newLine();

    if ($result->findings === []) {
      $io->success('No issues found.');
      return;
    }

    foreach ($result->groupedBySeverity() as $sev => $findings) {
      $severity = Severity::from($sev);
      $io->writeln(sprintf(' %s  %s  (%d)', $severity->emoji(), strtoupper($sev), count($findings)));
      foreach ($findings as $f) {
        $io->writeln('   - ' . $f->configName);
        $io->writeln('     ' . $f->title);
        if ($f->detail !== '') {
          $io->writeln('     ' . wordwrap($f->detail, 90, "\n     "));
        }
        if ($f->recommendation !== '') {
          $io->writeln('     Fix: ' . $f->recommendation);
        }
        $io->newLine();
      }
    }

    $io->text(sprintf(' Audit #%d saved. View at /admin/reports/release-audit/%d', $logId, $logId));
  }

}
