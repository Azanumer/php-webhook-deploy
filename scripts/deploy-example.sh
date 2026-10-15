#!/bin/bash
# Example deploy script — reference it from config.php's commands list.
# Make it executable: chmod +x deploy-example.sh
# The web server user (www-data) needs: read access to this script,
# write access to the site dir, and git configured for the repo.
set -euo pipefail

SITE_DIR="/var/www/my-site"
PHP_BIN="/usr/bin/php"

cd "$SITE_DIR"

# Pull the branch the webhook watches (fast-forward only — never force-push)
git fetch --quiet origin
git reset --hard "origin/$(git rev-parse --abbrev-ref HEAD)"

# PHP dependencies, production flags
if [ -f composer.json ]; then
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Clear caches (adjust to your app)
if [ -f wp-config.php ] && command -v wp >/dev/null 2>&1; then
    wp cache flush --allow-root
fi

# Reset OPcache so new PHP files take effect immediately
"$PHP_BIN" -r 'if (function_exists("opcache_reset")) { opcache_reset(); }'

echo "Deploy finished: $(date -u +%FT%TZ) commit $(git rev-parse --short HEAD)"
