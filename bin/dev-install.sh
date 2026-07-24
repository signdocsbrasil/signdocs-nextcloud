#!/usr/bin/env bash
# Dev-install helper: symlink this app into a Nextcloud install, enable it,
# and run migrations. Idempotent.
#
# Usage:
#     bin/dev-install.sh                         # uses NC_PATH or auto-detect
#     bin/dev-install.sh /path/to/nextcloud      # explicit NC root
#     NC_USER=www-data bin/dev-install.sh        # override webserver user
#
# Defaults:
#     NC_PATH = $NEXTCLOUD_PATH or /var/www/nextcloud
#     NC_USER = $NEXTCLOUD_USER or www-data
#
# Exits non-zero on any failure (set -e). Safe to re-run.

set -euo pipefail

cd "$(dirname "$0")/.."
APP_DIR="$PWD"
APP_ID="signdocs_brasil"

NC_PATH="${1:-${NEXTCLOUD_PATH:-/var/www/nextcloud}}"
NC_USER="${NEXTCLOUD_USER:-www-data}"

if [ ! -d "$NC_PATH" ]; then
    echo "Nextcloud not found at: $NC_PATH" >&2
    echo "Pass the path as the first arg, or set NEXTCLOUD_PATH." >&2
    exit 1
fi
if [ ! -f "$NC_PATH/occ" ]; then
    echo "Not a Nextcloud install (no occ): $NC_PATH" >&2
    exit 1
fi

echo "→ Linking $APP_DIR into $NC_PATH/apps/$APP_ID"
sudo rm -f "$NC_PATH/apps/$APP_ID"
sudo ln -s "$APP_DIR" "$NC_PATH/apps/$APP_ID"

echo "→ Ensuring composer deps are present"
if [ ! -d composer ]; then
    composer install --no-interaction --quiet
fi

echo "→ Enabling app"
sudo -u "$NC_USER" php "$NC_PATH/occ" app:enable "$APP_ID"

echo "→ Running migrations"
sudo -u "$NC_USER" php "$NC_PATH/occ" migrations:migrate "$APP_ID"

echo "→ Listing app state"
sudo -u "$NC_USER" php "$NC_PATH/occ" app:list 2>/dev/null | awk "/^[[:space:]]*$APP_ID:/{p=1} p" | head -3 || true

echo ""
echo "Done. Visit your Nextcloud Files app and right-click any PDF/DOCX/ODT."
echo "Configure tenant credentials at:  $NC_PATH/index.php/settings/admin/$APP_ID"
