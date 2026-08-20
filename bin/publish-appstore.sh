#!/usr/bin/env bash
# Publish a signed release to apps.nextcloud.com.
#
# The app is already registered (that one-time POST /api/v1/apps happened for
# v0.1.0 on 2026-05-21), so publishing a new version is a single releases POST.
# The App Store fetches the tarball from the URL given here, so the GitHub
# Release must exist and be public before this runs.
#
# The channel is derived from the version string, not from a flag: plain semver
# publishes to stable, a pre-release suffix (0.4.0-rc.1) publishes to beta.
#
# Usage:
#     NC_APPSTORE_TOKEN=... bin/publish-appstore.sh 0.3.0
#     NC_APPSTORE_TOKEN=... bin/publish-appstore.sh 0.3.0 --dry-run
#
# The token comes from apps.nextcloud.com → account settings. It is read from
# the environment and never echoed, so it stays out of shell history if you
# prefix the command with a space or export it beforehand.

set -euo pipefail

cd "$(dirname "$0")/.."

APP_ID="signdocs_brasil"
REPO="signdocsbrasil/signdocs-nextcloud"
API="https://apps.nextcloud.com/api/v1/apps/releases"

VERSION="${1:-}"
DRY_RUN=""
[ "${2:-}" = "--dry-run" ] && DRY_RUN=1

if [ -z "$VERSION" ]; then
    echo "Usage: NC_APPSTORE_TOKEN=... $0 <version> [--dry-run]" >&2
    exit 2
fi
if [ -z "${NC_APPSTORE_TOKEN:-}" ]; then
    echo "NC_APPSTORE_TOKEN is not set." >&2
    echo "Get one from apps.nextcloud.com -> account settings." >&2
    exit 1
fi

SIG_B64_FILE="${APP_ID}-${VERSION}.tar.gz.sig.b64"
DOWNLOAD="https://github.com/${REPO}/releases/download/v${VERSION}/${APP_ID}-${VERSION}.tar.gz"

if [ ! -f "$SIG_B64_FILE" ]; then
    echo "Signature not found: $SIG_B64_FILE" >&2
    echo "Run bin/build-zip.sh then bin/sign-release.sh first." >&2
    exit 1
fi

# openssl base64 wraps at 64 columns; JSON cannot carry the newlines.
SIGNATURE="$(tr -d '\n' < "$SIG_B64_FILE")"

# The App Store fetches this itself, so a 404 here becomes an opaque failure
# on their side. Check before asking them to.
echo "Checking the release asset is reachable..."
HTTP="$(curl -sIL -o /dev/null -w '%{http_code}' "$DOWNLOAD")"
if [ "$HTTP" != "200" ]; then
    echo "Download URL returned HTTP $HTTP: $DOWNLOAD" >&2
    echo "Cut the GitHub Release and attach the tarball first." >&2
    exit 1
fi
echo "  200 OK"

# Verify the signature against the artifact the App Store will actually fetch,
# not against the local copy. They are supposed to be the same file; this is
# where you find out they are not.
echo "Verifying the published tarball against the signature..."
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
curl -sL -o "$TMP/app.tar.gz" "$DOWNLOAD"
KEY="${NC_SIGNING_KEY:-$HOME/.config/nextcloud-signing/${APP_ID}.key}"
if [ -f "$KEY" ]; then
    openssl rsa -in "$KEY" -pubout -out "$TMP/pub.pem" 2>/dev/null
    openssl base64 -d -in "$SIG_B64_FILE" -out "$TMP/app.sig"
    if ! openssl dgst -sha512 -verify "$TMP/pub.pem" -signature "$TMP/app.sig" "$TMP/app.tar.gz" >/dev/null 2>&1; then
        echo "Signature does NOT verify against the published tarball." >&2
        echo "The uploaded asset differs from the one that was signed." >&2
        exit 1
    fi
    echo "  Verified OK"
else
    echo "  Signing key not found at $KEY — skipping local verification." >&2
fi

BODY="$(printf '{"download":"%s","signature":"%s","nightly":false}' "$DOWNLOAD" "$SIGNATURE")"

if [ -n "$DRY_RUN" ]; then
    echo ""
    echo "--dry-run: would POST to $API"
    echo "  download:  $DOWNLOAD"
    echo "  signature: ${#SIGNATURE} chars"
    echo "  nightly:   false"
    exit 0
fi

echo "Publishing ${APP_ID} ${VERSION}..."
RESPONSE="$(mktemp)"
CODE="$(curl -s -o "$RESPONSE" -w '%{http_code}' -X POST "$API" \
    -H "Authorization: Token ${NC_APPSTORE_TOKEN}" \
    -H "Content-Type: application/json" \
    -d "$BODY")"

case "$CODE" in
    200|201)
        echo "Published: https://apps.nextcloud.com/apps/${APP_ID}"
        echo "The listing and the cached platform JSON lag the POST by minutes to hours."
        ;;
    *)
        echo "App Store returned HTTP $CODE:" >&2
        cat "$RESPONSE" >&2
        echo "" >&2
        rm -f "$RESPONSE"
        exit 1
        ;;
esac
rm -f "$RESPONSE"
