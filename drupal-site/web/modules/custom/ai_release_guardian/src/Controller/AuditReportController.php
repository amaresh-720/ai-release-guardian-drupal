<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\ai_release_guardian\Audit\Severity;
use Drupal\ai_release_guardian\Audit\Verdict;
use Drupal\ai_release_guardian\Service\AuditLogWriter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Read-only dashboard pages for the audit log.
 */
final class AuditReportController extends ControllerBase {

  public function __construct(
    private readonly AuditLogWriter $log,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('ai_release_guardian.log_writer'),
      $container->get('date.formatter'),
    );
  }

  public function list(): array {
    $rows = [];
    foreach ($this->log->recent(50) as $entry) {
      $verdict = Verdict::tryFrom($entry['verdict']) ?? Verdict::UNVERIFIED;
      $rows[] = [
        'data' => [
          ['data' => '#' . $entry['id']],
          ['data' => $this->dateFormatter->format($entry['timestamp'], 'short')],
          ['data' => $verdict->label()],
          ['data' => count($entry['findings'])],
          ['data' => $entry['meta']['model'] ?? '-'],
          [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('View'),
              '#url' => \Drupal\Core\Url::fromRoute('ai_release_guardian.report_view', ['audit_id' => $entry['id']]),
            ],
          ],
        ],
      ];
    }

    return [
      '#type' => 'container',
      'help' => [
        '#markup' => '<p>' . $this->t('Run <code>drush ai:audit-release</code> to create a new audit.') . '</p>',
      ],
      'table' => [
        '#theme' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('When'),
          $this->t('Verdict'),
          $this->t('Findings'),
          $this->t('Model'),
          $this->t('Detail'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No audits yet.'),
        '#attributes' => ['class' => ['ai-release-guardian-list']],
      ],
      '#cache' => [
        'tags' => ['state:ai_release_guardian.audit_log'],
        'max-age' => 60,
      ],
    ];
  }

  public function view(int $audit_id): array {
    $entry = $this->log->get($audit_id);
    if ($entry === NULL) {
      throw new NotFoundHttpException();
    }

    $verdict = Verdict::tryFrom($entry['verdict']) ?? Verdict::UNVERIFIED;
    $grouped = [];
    foreach ($entry['findings'] as $row) {
      $sev = Severity::tryParse((string) $row['severity']);
      $grouped[$sev->value][] = $row + ['_severity_emoji' => $sev->emoji()];
    }
    uksort($grouped, fn($a, $b) => Severity::from($a)->rank() <=> Severity::from($b)->rank());

    return [
      '#theme' => 'ai_release_guardian_report',
      '#audit' => [
        'id'        => $entry['id'],
        'timestamp' => $this->dateFormatter->format($entry['timestamp'], 'long'),
        'verdict'   => $verdict->value,
        'verdict_label' => $verdict->label(),
        'grouped'   => $grouped,
        'finding_count' => count($entry['findings']),
      ],
      '#meta' => $entry['meta'],
      '#attached' => ['library' => ['ai_release_guardian/report']],
      '#cache' => [
        'tags' => ['state:ai_release_guardian.audit_log'],
        'max-age' => 60,
      ],
    ];
  }

}
