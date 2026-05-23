#!/usr/bin/env bash
# Inject two deliberately-bad changes into the exported config so the
# audit demo has something to flag.
#
# Run after `drush config:export` (the install script does this automatically).
set -euo pipefail

SYNC_DIR="/var/www/html/config/sync"

if [ ! -d "$SYNC_DIR" ]; then
    echo "ERROR: $SYNC_DIR does not exist. Run drush config:export first." >&2
    exit 1
fi

ANON="$SYNC_DIR/user.role.anonymous.yml"
EXT="$SYNC_DIR/core.extension.yml"

# Change 1 — grant the anonymous role two dangerous permissions.
if [ -f "$ANON" ] && ! grep -q "administer site configuration" "$ANON"; then
    # Insert the two bad permissions just before 'search content'.
    sed -i "/^  - 'search content'/i\\
  - 'administer site configuration'\\
  - 'administer users'" "$ANON"
    echo "  + dangerous perms added to user.role.anonymous.yml"
fi

# Change 2 — enable the devel module (developer-only, must not ship to prod).
if [ -f "$EXT" ] && ! grep -q "^  devel:" "$EXT"; then
    sed -i "/^  dblog: 0$/a\\
  devel: 0\\
  devel_generate: 0" "$EXT"
    echo "  + devel + devel_generate added to core.extension.yml"
fi

echo "✓ Demo bad-config injected. Run 'drush ai:audit-release' to see it caught."
