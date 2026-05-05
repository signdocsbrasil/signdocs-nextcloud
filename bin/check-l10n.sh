#!/usr/bin/env bash
# Verify l10n/<lang>.json and l10n/<lang>.js stay in sync.
#
# NC reads .json on the server (PHP IL10N) and serves .js to the browser
# (OC.L10N). Both must contain the same set of source-string keys, otherwise
# a translated string in one path renders as the source string in the other.
# Shipping a .js file with one extra/missing key compared to its sibling .json
# is one of the most common NC App Store reviewer rejections.
#
# Run locally before pushing l10n updates; runs automatically in CI.

set -euo pipefail

cd "$(dirname "$0")/.."

fail=0
for json_file in l10n/*.json; do
    lang=$(basename "$json_file" .json)
    js_file="l10n/${lang}.js"

    if [ ! -f "$js_file" ]; then
        echo "MISSING: $js_file (sibling of $json_file)" >&2
        fail=1
        continue
    fi

    # Extract sorted source-key sets from both files and diff them.
    json_keys=$(python3 -c "
import json, sys
with open('$json_file') as f:
    print('\n'.join(sorted(json.load(f)['translations'].keys())))
")

    # The .js file is `OC.L10N.register('app', { ... }, 'plural');` — parse
    # by yanking the inner object via regex and JSON-loading it.
    js_keys=$(python3 -c "
import re, json, sys
with open('$js_file') as f:
    src = f.read()
m = re.search(r'register\(\s*\"[^\"]+\"\s*,\s*(\{.*?\})\s*,\s*\"', src, re.DOTALL)
if not m:
    print('FAILED to parse $js_file', file=sys.stderr); sys.exit(2)
print('\n'.join(sorted(json.loads(m.group(1)).keys())))
")

    if [ "$json_keys" != "$js_keys" ]; then
        echo "DRIFT: $json_file vs $js_file" >&2
        diff <(echo "$json_keys") <(echo "$js_keys") | head -20 >&2
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "" >&2
    echo "l10n/<lang>.json and l10n/<lang>.js must contain identical source-string keys." >&2
    exit 1
fi

echo "l10n: all language file pairs in sync."
