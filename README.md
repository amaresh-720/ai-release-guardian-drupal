# AI Release Guardian for Drupal 11

A simple Drupal module that checks pending config changes before they are imported.

It runs as a Drush command and warns you if the pending config looks risky.

---

## What it does

- compares the config files in `drupal-site/config/sync/` with the active site settings
- sends only the pending changes to an AI service
- flags risky changes such as dev modules left enabled or bad permissions
- saves recent audit results for later review

This is a tool for release checks, not a regular end-user feature.

---

## Requirements

- Docker
- Docker Compose
- A Groq API key stored in `.env`

---

## Setup

```bash
git clone <repo-url> ai-release-guardian
cd ai-release-guardian
cp .env.example .env
# Add your GROQ_API_KEY to .env

docker compose up -d --build
docker compose exec drupal bash /scripts/install-drupal.sh
```

Then open `http://localhost:8080` and log in as `admin / admin`.

---

## Run the audit

```bash
docker compose exec drupal drush ai:audit-release
```

This does:
- find the pending config changes
- remove sensitive values before sending them
- ask the AI for a safety check
- print the result in the terminal

---

## Other useful commands

```bash
docker compose exec drupal drush ai:audit-release --json     # JSON output
docker compose exec drupal drush ai:audit-release --force    # always return success
docker compose exec drupal drush ai:audit-release --no-cache # skip cache
docker compose exec drupal drush ai:audit-list               # show recent audits
```

---

## Where to view results

- Settings: `/admin/config/system/ai-release-guardian`
- Audit list: `/admin/reports/release-audit`
- Audit detail: `/admin/reports/release-audit/{id}`

---

## Main files to know

- `src/Drush/Commands/ReleaseGuardianCommands.php` — command entry point
- `src/Service/ConfigDiffService.php` — finds pending config changes
- `src/Service/ConfigAuditService.php` — sends the audit to AI
- `src/Service/AuditLogWriter.php` — saves audit history
- `src/Form/SettingsForm.php` — admin configuration form

---

## Important notes

- The API key is stored in Drupal State, not in exported config.
- The command is the main feature. The dashboard is just for viewing results.
- If the AI provider fails, the audit returns `UNVERIFIED` instead of pretending everything is fine.

---

## License

GPL-2.0-or-later
