#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 || ! "$1" =~ ^ghcr\.io/[a-z0-9][a-z0-9._/-]*@sha256:[0-9a-f]{64}$ ]]; then
    echo "Usage: $0 GHCR_IMAGE@sha256:DIGEST" >&2
    exit 1
fi

docker run --rm --pull=never --platform linux/amd64 --network none \
    -e APP_ENV=testing -e APP_DEBUG=false -e DB_CONNECTION=sqlite \
    -e "APP_KEY=base64:$(openssl rand -base64 32)" -e LOG_CHANNEL=stderr \
    "$1" sh -ec '
        export DB_DATABASE="$(mktemp /tmp/formaku-release.XXXXXX.sqlite)"
        php artisan migrate --force --no-interaction
        php artisan formaku:doctor --no-ansi --no-interaction
    '
