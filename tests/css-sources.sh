#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

npm run build
stylesheet=packages/cms/public/main.css
# Laravel's mobile pagination row must be hidden at the desktop breakpoint.
if ! grep -Fq '.sm\:hidden' "$stylesheet"; then
    echo 'The compiled stylesheet is missing the responsive pagination visibility rule.' >&2
    exit 1
fi
fixture=$(mktemp -d tests/css-source-fixture.XXXXXX)
trap 'rm -rf -- "$fixture"' EXIT
cp "$stylesheet" "$fixture/baseline.css"
# This class belongs to a test fixture outside the CMS templates.
printf 'bg-%s\n' '[#123abc]' > "$fixture/unrelated.txt"
./node_modules/.bin/vite build
if ! cmp -s "$stylesheet" "$fixture/baseline.css"; then
    echo 'An unrelated test file changed the compiled CMS stylesheet.' >&2
    exit 1
fi
printf 'Unrelated test files leave the compiled CMS stylesheet byte-identical.\n'
