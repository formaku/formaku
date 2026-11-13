#!/usr/bin/env bash
set -euo pipefail
umask 077

cd "$(dirname "${BASH_SOURCE[0]}")/.."
pg=${FORMAKU_TEST_PG_BIN:-$(pg_config --bindir)}
scratch=$(mktemp -d)
cleanup() {
    if [[ -f "$scratch/data/postmaster.pid" ]]; then
        "$pg/pg_ctl" -D "$scratch/data" -m fast -w stop >/dev/null || return 1
    fi
    rm -rf -- "$scratch"
}
trap cleanup EXIT

# Use a private Unix socket and two databases in a disposable cluster.
"$pg/initdb" -D "$scratch/data" -U rehearsal --auth-local=trust --auth-host=reject --no-locale >/dev/null
"$pg/pg_ctl" -D "$scratch/data" -l "$scratch/postgres.log" -o "-h '' -k $scratch" -w start
"$pg/createdb" -h "$scratch" -U rehearsal source_fixture
"$pg/createdb" -h "$scratch" -U rehearsal recovery_fixture

app_key="base64:$(openssl rand -base64 32)"
laravel() {
    local database=$1
    shift
    env -i PATH="$PATH" APP_ENV=testing APP_CONFIG_CACHE="$scratch/config.php" \
        APP_KEY="$app_key" FORMAKU_PUBLIC_ROUTES=true DB_CONNECTION=pgsql \
        DB_HOST="$scratch" DB_PORT=5432 DB_DATABASE="$database" DB_USERNAME=rehearsal \
        SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync php "$@"
}

laravel source_fixture apps/starter/artisan migrate --force --no-interaction
printf 'synthetic-rehearsal-password\n' | laravel source_fixture apps/starter/artisan formaku:admin rehearsal@example.test
laravel source_fixture apps/starter/artisan formaku:demo

mkdir -p "$scratch/stage" "$scratch/uploads" "$scratch/recovered" "$scratch/restore"
printf 'Synthetic upload\n' > "$scratch/uploads/example.txt"
printf 'APP_ENV=testing\n' > "$scratch/stage/runtime.env"
"$pg/pg_dump" -h "$scratch" -U rehearsal source_fixture > "$scratch/stage/database.sql"
tar -C "$scratch/uploads" -czf "$scratch/stage/uploads.tar.gz" .
openssl rand -base64 48 > "$scratch/backup.key"
tar -C "$scratch/stage" -cf - database.sql uploads.tar.gz runtime.env \
    | openssl enc -aes-256-cbc -salt -pbkdf2 -pass "file:$scratch/backup.key" -out "$scratch/backup.tar.enc"

openssl enc -d -aes-256-cbc -pbkdf2 -pass "file:$scratch/backup.key" \
    -in "$scratch/backup.tar.enc" -out "$scratch/restore/backup.tar"
tar -C "$scratch/restore" -xf "$scratch/restore/backup.tar" database.sql uploads.tar.gz runtime.env
"$pg/psql" -X --single-transaction --set=ON_ERROR_STOP=1 \
    --host="$scratch" --username=rehearsal --dbname=recovery_fixture \
    --file="$scratch/restore/database.sql" > "$scratch/import.log"
tar -C "$scratch/recovered" -xzf "$scratch/restore/uploads.tar.gz"
cmp "$scratch/uploads/example.txt" "$scratch/recovered/example.txt"
cmp "$scratch/stage/runtime.env" "$scratch/restore/runtime.env"

laravel recovery_fixture apps/starter/artisan formaku:doctor --no-interaction
laravel recovery_fixture <<'PHP'
<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require 'apps/starter/vendor/autoload.php';
$app = require 'apps/starter/bootstrap/app.php';
$response = $app->make(Kernel::class)->handle(Request::create('/p/start'));
if ($response->getStatusCode() !== 200 || ! str_contains($response->getContent(), 'Od pomysłu do własnej aplikacji.')) {
    fwrite(STDERR, "The restored database did not render the published page.\n");
    exit(1);
}
PHP
echo 'Verified recovery of the published page, upload and runtime settings.'
