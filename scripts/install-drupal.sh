#!/usr/bin/env bash
# One-shot install: Drupal site, contrib modules, our custom module,
# Groq creds, baseline config export, and the deliberately-bad demo
# config injection. Idempotent — safe to re-run.
#
# Run from host:
#   docker compose exec drupal bash /var/www/html/scripts/install-drupal.sh
set -euo pipefail

cd /var/www/html

echo "▶ Ensuring composer deps installed..."
composer install --no-interaction --prefer-dist

# ---- Make sure /usr/local/bin/drush points at the project-local one ----
# Drupal needs project-local Drush, not the global install, so the
# preflight check resolves drupal/core correctly.
if [ -x /var/www/html/vendor/bin/drush ]; then
    if [ ! -L /usr/local/bin/drush ] || [ "$(readlink /usr/local/bin/drush)" != "/var/www/html/vendor/bin/drush" ]; then
        rm -f /usr/local/bin/drush
        ln -s /var/www/html/vendor/bin/drush /usr/local/bin/drush
    fi
fi

# ---- Drupal site install (idempotent) ----------------------------------
if ! drush status --field=bootstrap 2>/dev/null | grep -qi successful; then
    echo "▶ Installing Drupal site..."
    chmod -R u+w web/sites/default || true
    drush site:install standard \
        --account-name="${DRUPAL_ADMIN_USER}" \
        --account-pass="${DRUPAL_ADMIN_PASSWORD}" \
        --account-mail="${DRUPAL_ADMIN_EMAIL}" \
        --site-name="${DRUPAL_SITE_NAME}" \
        --db-url="mysql://${DB_USER}:${DB_PASSWORD}@${DB_HOST}/${DB_NAME}" \
        --yes
else
    echo "✓ Drupal already installed"
fi

# ---- Point Drupal at a stable config sync directory --------------------
if ! grep -q config_sync_directory web/sites/default/settings.php 2>/dev/null; then
    chmod u+w web/sites/default/settings.php
    cat >> web/sites/default/settings.php <<'EOF'

// Mounted from host so config/sync survives container rebuilds.
$settings['config_sync_directory'] = '/var/www/html/config/sync';
EOF
    chmod 444 web/sites/default/settings.php
fi

# ---- Contrib modules ----------------------------------------------------
echo "▶ Requiring contrib modules..."
composer require \
    drupal/admin_toolbar:^3.5 \
    drupal/devel:^5.3 \
    drush/drush:^13 \
    --no-interaction --no-progress

# Project-local drush takes precedence over any global install.
if [ ! -L /usr/local/bin/drush ] || [ "$(readlink /usr/local/bin/drush)" != "/var/www/html/vendor/bin/drush" ]; then
    rm -f /usr/local/bin/drush
    ln -s /var/www/html/vendor/bin/drush /usr/local/bin/drush
fi

echo "▶ Enabling base + admin modules..."
drush en -y admin_toolbar admin_toolbar_tools config

if [ -d web/modules/custom/ai_release_guardian ]; then
    echo "▶ Enabling ai_release_guardian..."
    drush en -y ai_release_guardian
fi

# ---- Seed Groq credentials into State API ------------------------------
if [ -n "${GROQ_API_KEY:-}" ]; then
    echo "▶ Seeding Groq credentials into State API..."
    drush state:set ai_release_guardian.groq_api_key  "${GROQ_API_KEY}"  --input-format=string
    drush state:set ai_release_guardian.groq_model    "${GROQ_MODEL:-llama-3.1-8b-instant}" --input-format=string
    drush state:set ai_release_guardian.groq_base_url "${GROQ_BASE_URL:-https://api.groq.com/openai/v1}" --input-format=string
fi

mkdir -p /var/www/html/config/sync
chown -R www-data:www-data /var/www/html/config 2>/dev/null || true

# ---- Export baseline config and inject demo bad config -----------------
# We export fresh so the demo always shows exactly the changes from a
# clean baseline. Nothing under /config is tracked in git.
echo "▶ Exporting baseline config to /config/sync..."
drush config:export -y --destination=/var/www/html/config/sync

echo "▶ Injecting deliberately-bad demo config..."
bash /scripts/inject-bad-config.sh

drush cr
echo ""
echo "============================================================"
echo "✓ Drupal ready at  http://localhost:${HTTP_PORT:-8080}"
echo "✓ Admin login      ${DRUPAL_ADMIN_USER} / ${DRUPAL_ADMIN_PASSWORD}"
echo "✓ Run audit demo:  docker compose exec drupal drush ai:audit-release"
echo "============================================================"
