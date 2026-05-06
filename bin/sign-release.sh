#!/usr/bin/env bash
# Sign a built release tarball with the developer key registered with the
# Nextcloud App Store. Produces an SHA-512 RSA signature alongside the
# tarball; both files are uploaded together when submitting a release.
#
# The private key MUST stay outside the repo — default location is
# ~/.config/nextcloud-signing/signdocs_brasil.key (created by the openssl
# steps documented in CLAUDE.md). Override with NC_SIGNING_KEY if you
# rotate it elsewhere.
#
# Usage:
#     bin/sign-release.sh signdocs_brasil-0.1.0.tar.gz
#
# Output:
#     signdocs_brasil-0.1.0.tar.gz.sig      (binary detached signature)
#     signdocs_brasil-0.1.0.tar.gz.sig.b64  (base64 form — what apps.next
#                                            cloud.com's submit form
#                                            actually pastes in)

set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Usage: $0 <tarball.tar.gz>" >&2
    exit 2
fi

ARCHIVE="$1"
KEY="${NC_SIGNING_KEY:-$HOME/.config/nextcloud-signing/signdocs_brasil.key}"

if [ ! -f "$ARCHIVE" ]; then
    echo "Archive not found: $ARCHIVE" >&2
    exit 1
fi
if [ ! -f "$KEY" ]; then
    echo "Signing key not found: $KEY" >&2
    echo "Generate one via the steps in bin/setup-signing-key.sh, or set NC_SIGNING_KEY." >&2
    exit 1
fi

SIG_BIN="${ARCHIVE}.sig"
SIG_B64="${ARCHIVE}.sig.b64"

openssl dgst -sha512 -sign "$KEY" -out "$SIG_BIN" "$ARCHIVE"
openssl base64 -in "$SIG_BIN" -out "$SIG_B64"

# Self-verify before declaring victory — catches a corrupted key or a
# version-skew openssl quietly producing wrong-format output.
PUBKEY="$(mktemp)"
trap "rm -f '$PUBKEY'" EXIT
openssl rsa -in "$KEY" -pubout -out "$PUBKEY" 2>/dev/null
if ! openssl dgst -sha512 -verify "$PUBKEY" -signature "$SIG_BIN" "$ARCHIVE" >/dev/null 2>&1; then
    echo "ERROR: self-verification of the signature failed." >&2
    rm -f "$SIG_BIN" "$SIG_B64"
    exit 1
fi

echo "Signed: $SIG_BIN"
echo "Base64: $SIG_B64  (paste into the apps.nextcloud.com submission form)"
