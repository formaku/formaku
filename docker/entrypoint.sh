#!/bin/sh
set -eu
: "${APP_KEY:?Set a persistent APP_KEY}"
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs
exec "$@"
