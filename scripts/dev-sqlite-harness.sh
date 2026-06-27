#!/usr/bin/env bash
#
# dev-sqlite-harness.sh — stand up a throwaway WordPress (SQLite, no MySQL) with
# this plugin activated, so the agent (or a developer) can run the PHP smoke
# suite and exercise plugin logic — order totals, pricing math, save round-trips
# — without a real database or the live site.
#
# Designed for the Claude Code remote container (Debian, PHP 8.4, outbound HTTPS
# via the agent proxy). wordpress.org is blocked by egress policy, so WordPress
# core + the SQLite drop-in are fetched from their GitHub mirrors instead.
#
# Usage:   bash scripts/dev-sqlite-harness.sh
# Result:  WordPress at /home/user/wp, plugin symlinked + active, wp-cli at
#          /home/user/wp-cli.phar, and a php-allowroot wrapper for the smoke
#          runner (which shells out to wp-cli without --allow-root).
#
# Re-run safe: it removes and recreates /home/user/wp.
#
# Run the smoke suite afterwards:
#   cd /home/user/wp && php <repo>/tests/run-all-smokes.php \
#       /home/user/wp /home/user/php-allowroot.sh /home/user/wp-cli.phar
#
# CAVEAT: SQLite is not 100% MySQL-compatible. A few raw-SQL paths (PRAGMA / DDL
# introspection, one payments-ledger migration query) behave differently than on
# the live MySQL site, so some smoke failures are environmental, not real
# regressions. The pricing/order-total PHP logic does run correctly here.

set -e

REPO="$(cd "$(dirname "$0")/.." && pwd)"
WP=/home/user/wp
WPCLI=/home/user/wp-cli.phar
WPVER=6.8

echo "==> wp-cli"
[ -f "$WPCLI" ] || curl -sS -o "$WPCLI" https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

echo "==> WordPress core $WPVER (GitHub mirror; wordpress.org is egress-blocked)"
rm -rf "$WP"
mkdir -p "$WP"
curl -sS -L -o /home/user/wp.tar.gz "https://codeload.github.com/WordPress/WordPress/tar.gz/refs/tags/$WPVER"
tar -xzf /home/user/wp.tar.gz -C "$WP" --strip-components=1

echo "==> SQLite database integration drop-in"
curl -sS -L -o /home/user/sqlite.zip https://codeload.github.com/WordPress/sqlite-database-integration/zip/refs/heads/main
rm -rf "$WP/wp-content/plugins/sqlite-database-integration"
unzip -q /home/user/sqlite.zip -d "$WP/wp-content/plugins"
mv "$WP/wp-content/plugins/sqlite-database-integration-main" "$WP/wp-content/plugins/sqlite-database-integration"
cp "$WP/wp-content/plugins/sqlite-database-integration/db.copy" "$WP/wp-content/db.php"
php -r '$f="'"$WP"'/wp-content/db.php";$c=file_get_contents($f);$c=str_replace("{SQLITE_IMPLEMENTATION_FOLDER_PATH}","'"$WP"'/wp-content/plugins/sqlite-database-integration",$c);$c=str_replace("{SQLITE_PLUGIN}","sqlite-database-integration/load.php",$c);file_put_contents($f,$c);'

echo "==> wp-config + install"
php "$WPCLI" config create --allow-root --path="$WP" --dbname=wp --dbuser=root --dbpass= --dbhost=localhost --skip-check --force
php "$WPCLI" core install --allow-root --path="$WP" --url=http://localhost:8089 --title="EEM Test" --admin_user=admin --admin_password=admin --admin_email=test@example.com --skip-email

echo "==> activate plugin (symlink from repo)"
ln -sfn "$REPO" "$WP/wp-content/plugins/equine-event-manager"
php "$WPCLI" plugin activate equine-event-manager --allow-root --path="$WP" || true

echo "==> allow-root wrapper for the smoke runner"
printf '#!/bin/sh\nexec /usr/bin/php "$@" --allow-root\n' > /home/user/php-allowroot.sh
chmod +x /home/user/php-allowroot.sh

echo "==> done. plugin version:"
php "$WPCLI" eval 'echo EQUINE_EVENT_MANAGER_VERSION."\n";' --allow-root --path="$WP"
