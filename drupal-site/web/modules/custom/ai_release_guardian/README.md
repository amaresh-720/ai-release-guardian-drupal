# AI Release Guardian

LLM-assisted audit of pending Drupal configuration imports. Drush
command for CI/CD, plus a small admin dashboard.

> Top-level project README, ADRs, and quickstart live in the repo root.
> This file is the module-local reference.

## Commands

```bash
drush ai:audit-release          # audit /config/sync vs active config
drush ai:audit-release --json   # machine-readable JSON
drush ai:audit-release --force  # always exit 0 (still logs verdict)
drush ai:audit-release --no-cache
drush ai:audit-list             # show last 20 audit runs
```

Exit codes: `0` = PASS or WARN, `2` = BLOCK, `3` = UNVERIFIED.

## Admin pages

- `/admin/config/system/ai-release-guardian` — provider, key, policy.
- `/admin/reports/release-audit` — audit history list.
- `/admin/reports/release-audit/{id}` — single audit detail.

## State keys

- `ai_release_guardian.groq_api_key` — bearer key (write-only via form).
- `ai_release_guardian.groq_model` — model id.
- `ai_release_guardian.groq_base_url` — provider endpoint.
- `ai_release_guardian.audit_log` — bounded list of past results.

## Configuration

`ai_release_guardian.settings` — provider, model, base_url,
request_timeout, blocking_severities, excluded_config_patterns,
cache_ttl. Schema in `config/schema/`.

## Permissions

- `administer ai release guardian` — manage settings (restricted).
- `view ai release guardian reports` — read-only dashboard access.

## Dependencies

Drupal core: `config`, `system`, `user`. Drush 13+.

## Tests

```bash
vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/ai_release_guardian/tests
```
