#!/usr/bin/env bash
# Build a Nextcloud-App-Store-shaped distribution zip for the SignDocs Brasil
# Nextcloud app. Same shape every release uses on the GitHub Releases page
# and (post-approval) on apps.nextcloud.com.
#
# The App Store requires:
#   - exactly one top-level directory whose name matches the app id
#   - that directory contains appinfo/info.xml at its top
#   - vendor/ is included (no Composer runs on end-user NC servers)
#   - dev artefacts (tests/, .github/, CLAUDE.md, *.cache, etc.) excluded
#
# Usage:
#     bin/build-zip.sh                       # build the working copy
#     bin/build-zip.sh --version 0.1.0       # override version label
#     bin/build-zip.sh --ref v0.1.0          # build the source at <ref>
#
# Output: signdocs_brasil-<version>.tar.gz in the repo root.
#         (Yes, .tar.gz — apps.nextcloud.com accepts both .zip and .tar.gz;
#          tar.gz is the canonical format and many official apps use it.)
#
# Environment:
#   COMPOSER_BIN  override the composer binary (defaults to `composer`)

set -euo pipefail

cd "$(dirname "$0")/.."

APP_ID="signdocs_brasil"
VERSION=""
REF=""
while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="$2"; shift 2 ;;
        --ref) REF="$2"; shift 2 ;;
        *) echo "unknown arg: $1" >&2; exit 2 ;;
    esac
done

COMPOSER_BIN="${COMPOSER_BIN:-composer}"

STAGE="$(mktemp -d)"
trap "rm -rf '$STAGE'" EXIT

DEST="$STAGE/$APP_ID"
mkdir -p "$DEST"

if [ -n "$REF" ]; then
    # Materialize the tagged tree directly — bypasses the working copy
    # entirely so the build is reproducible regardless of local state.
    git archive --format=tar "$REF" | tar -xf - -C "$DEST"
else
    # Stage tracked files only. `git ls-files` skips anything in
    # .gitignore and any untracked working-copy noise.
    git ls-files | tar -cf - --files-from=- | tar -xf - -C "$DEST"
fi

if [ -z "$VERSION" ]; then
    VERSION=$(grep -oP '<version>\K[^<]+' "$DEST/appinfo/info.xml" | head -1)
fi
if [ -z "$VERSION" ]; then
    echo "could not determine app version" >&2
    exit 1
fi

# Files that ship in source control but have no business inside the
# distributed app. Keeping them out shrinks the archive and avoids App
# Store reviewer flags.
EXCLUDE=(
    .github
    .gitignore
    .php-cs-fixer.cache
    .php-cs-fixer.dist.php
    .phpunit.cache
    .phpstan.cache
    CLAUDE.md
    phpstan.neon.dist
    phpunit.xml.dist
    tests
    bin
    screenshots
)
for path in "${EXCLUDE[@]}"; do
    rm -rf "$DEST/$path"
done

# Production-only autoload — no phpunit/phpstan/php-cs-fixer in the zip.
( cd "$DEST" && "$COMPOSER_BIN" install --no-dev --classmap-authoritative \
    --optimize-autoloader --no-interaction --quiet )

# composer regenerates the lock during install. Drop it from the
# distributed archive — composer doesn't run on end-user NC servers
# and the extra dead weight is unnecessary.
rm -f "$DEST/composer.lock"
rm -f "$DEST/composer.json"

# Apps.nextcloud.com signature requirement: tarball must contain a single
# top-level directory whose name is the app id.
ARCHIVE="$PWD/${APP_ID}-${VERSION}.tar.gz"
rm -f "$ARCHIVE"
( cd "$STAGE" && tar -czf "$ARCHIVE" "$APP_ID" )

echo "$ARCHIVE"
