<?php

declare(strict_types=1);

namespace Drupal\ai_release_guardian\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin settings form for AI Release Guardian.
 *
 * Non-sensitive options live in Config (exported with site config).
 * The API key lives in State so it doesn't get checked into git via
 * config:export.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    \Drupal\Core\Config\ConfigFactoryInterface $configFactory,
    \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager,
    private readonly StateInterface $state,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('state'),
    );
  }

  public function getFormId(): string {
    return 'ai_release_guardian_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['ai_release_guardian.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('ai_release_guardian.settings');

    $form['provider_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Provider'),
      '#open' => TRUE,
    ];
    $form['provider_section']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('AI provider'),
      '#options' => ['groq' => 'Groq', 'openai' => 'OpenAI'],
      '#default_value' => $config->get('provider') ?? 'groq',
    ];
    $form['provider_section']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $config->get('model') ?? 'llama-3.1-8b-instant',
      '#description' => $this->t('Examples: llama-3.1-8b-instant, openai/gpt-oss-20b'),
    ];
    $form['provider_section']['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Provider base URL'),
      '#default_value' => $config->get('base_url') ?? 'https://api.groq.com/openai/v1',
    ];

    $currentKey = (string) $this->state->get('ai_release_guardian.groq_api_key', '');
    $masked = $currentKey === '' ? '(not set)' : '...' . substr($currentKey, -4);
    $form['provider_section']['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('API key'),
      '#description' => $this->t('Current: @masked. Leave blank to keep existing.', ['@masked' => $masked]),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];

    $form['policy_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Audit policy'),
      '#open' => TRUE,
    ];
    $form['policy_section']['blocking_severities'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Severities that block deployment'),
      '#options' => [
        'critical' => 'Critical',
        'high'     => 'High',
        'medium'   => 'Medium',
        'low'      => 'Low',
        'info'     => 'Info',
      ],
      '#default_value' => $config->get('blocking_severities') ?? ['critical', 'high'],
    ];
    $form['policy_section']['excluded_config_patterns'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded config patterns'),
      '#description' => $this->t('One fnmatch() pattern per line. Matching config names are skipped.'),
      '#default_value' => implode("\n", $config->get('excluded_config_patterns') ?? []),
      '#rows' => 4,
    ];

    $form['perf_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance'),
      '#open' => FALSE,
    ];
    $form['perf_section']['request_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('HTTP timeout (seconds)'),
      '#default_value' => $config->get('request_timeout') ?? 20,
      '#min' => 1,
      '#max' => 120,
    ];
    $form['perf_section']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#description' => $this->t('Same diff hash returns cached audit within this window. 0 disables.'),
      '#default_value' => $config->get('cache_ttl') ?? 3600,
      '#min' => 0,
      '#max' => 86400,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    $excluded = array_filter(array_map('trim', explode("\n", (string) $values['excluded_config_patterns'])));
    $blocking = array_values(array_filter($values['blocking_severities']));

    $this->config('ai_release_guardian.settings')
      ->set('provider', $values['provider'])
      ->set('model', $values['model'])
      ->set('base_url', $values['base_url'])
      ->set('request_timeout', (int) $values['request_timeout'])
      ->set('cache_ttl', (int) $values['cache_ttl'])
      ->set('blocking_severities', $blocking)
      ->set('excluded_config_patterns', $excluded)
      ->save();

    if (!empty($values['api_key'])) {
      $this->state->set('ai_release_guardian.groq_api_key', $values['api_key']);
      $this->state->set('ai_release_guardian.groq_model', $values['model']);
      $this->state->set('ai_release_guardian.groq_base_url', $values['base_url']);
    }

    parent::submitForm($form, $form_state);
  }

}
