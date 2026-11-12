#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

baseline=ghcr.io/formaku/formaku@sha256:f8eacc6d6d305928860c70dfec6ff2cd08777b46a92522fd049feb4251bf85db
candidate=${1:-formaku:local}
candidate_platform=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$candidate")
migration=2026_11_12_123500_index_formaku_revision_history
migration_path="vendor/formaku/cms/database/migrations/$migration.php"
index=formaku_revisions_record_id_id_index

scratch=$(mktemp -d)
project="formaku-index-upgrade-$$-$RANDOM"
export DB_PASSWORD
DB_PASSWORD=$(openssl rand -hex 24)
sed -e "s|^APP_KEY=$|APP_KEY=base64:$(openssl rand -base64 32)|" \
    -e "s|^DB_PASSWORD=$|DB_PASSWORD=$DB_PASSWORD|" \
    .env.docker.example > "$scratch/fixture.env"

cat > "$scratch/compose.yml" <<YAML
services:
  web:
    build: !reset null
    ports: !reset []
    env_file: !override ["$scratch/fixture.env"]
    platform: \${FORMAKU_UPGRADE_PLATFORM}
  worker:
    env_file: !override ["$scratch/fixture.env"]
  scheduler:
    env_file: !override ["$scratch/fixture.env"]
  database:
    healthcheck:
      test: ["CMD", "pg_isready", "-h", "127.0.0.1", "-U", "formaku", "-d", "formaku"]
YAML

compose() {
    docker compose --project-name "$project" --env-file "$scratch/fixture.env" \
        -f compose.yaml -f "$scratch/compose.yml" "$@"
}

cleanup() {
    local status=$?
    if (( status != 0 )); then
        compose logs --no-color --tail 50 web database >&2 || true
    fi
    compose down --volumes || status=1
    rm -rf -- "$scratch"
    exit "$status"
}

sql() {
    compose exec -T database psql -XqAt -v ON_ERROR_STOP=1 -U formaku -d formaku -c "$1" < /dev/null
}

assert_query() {
    local expected=$1 label=$2 actual
    shift 2
    actual=$("$@")
    if [[ "$actual" != "$expected" ]]; then
        printf '%s: expected %s, got %s\n' "$label" "$expected" "$actual" >&2
        exit 1
    fi
}

index_definition() {
    sql "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'formaku_revisions' AND indexname = '$index'"
}

migration_batch() {
    sql "SELECT batch FROM migrations WHERE migration = '$migration'"
}

snapshot() {
    sql 'SELECT row_to_json(r) FROM formaku_records r ORDER BY id;
         SELECT row_to_json(r) FROM formaku_revisions r ORDER BY id;' > "$scratch/$1-data"
}

verify_publication() {
    local container expected_image
    container=$(compose ps -q web)
    expected_image=$(docker image inspect --format '{{.Id}}' "$FORMAKU_IMAGE")
    assert_query "$expected_image" 'Web runtime image' docker inspect --format '{{.Image}}' "$container"
    compose exec -T web curl --fail --silent --show-error --max-time 15 \
        http://127.0.0.1:8080/p/revision-index-upgrade < /dev/null > "$scratch/$1.html"
    grep -Fq '<title>Revision upgrade published title</title>' "$scratch/$1.html"
    grep -Fq '<h1>Revision upgrade published body</h1>' "$scratch/$1.html"
    if grep -Fq 'Revision upgrade private draft' "$scratch/$1.html"; then
        echo "Draft appeared in the public page during $1." >&2
        exit 1
    fi
}

export FORMAKU_IMAGE="$baseline" FORMAKU_UPGRADE_PLATFORM=linux/amd64
trap cleanup EXIT

docker pull --platform linux/amd64 "$baseline"
compose up -d --no-build --wait --wait-timeout 90 database
compose run --rm --no-deps --pull never -T web php artisan migrate --force --no-interaction < /dev/null
compose run --rm --no-deps --pull never -T web php <<'PHP'
<?php

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Services\RecordService;
use Illuminate\Contracts\Console\Kernel;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::create([
    'name' => 'Upgrade fixture',
    'email' => 'revision-upgrade@formaku.test',
    'password' => bin2hex(random_bytes(24)),
]);
$collection = Collection::create([
    'key' => 'revision_upgrade',
    'name' => 'Revision upgrade fixture',
    'kind' => 'content',
    'definition' => [
        'public' => true,
        'fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
            ['key' => 'slug', 'label' => 'Slug', 'type' => 'text', 'required' => true],
            ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext'],
        ],
    ],
]);
$service = $app->make(RecordService::class);
$record = $service->save($collection, null, [
    'title' => 'Revision upgrade published title',
    'slug' => 'revision-index-upgrade',
    'blocks' => [['type' => 'hero', 'props' => ['title' => 'Revision upgrade published body']]],
], 0, $user->id);
$record = $service->publish($record, $record->version, $user->id);
$service->save($collection, $record, [
    'title' => 'Revision upgrade private draft',
    'slug' => 'revision-index-upgrade',
    'blocks' => [['type' => 'hero', 'props' => ['title' => 'Revision upgrade private draft']]],
], $record->version, $user->id);
PHP

assert_query '' 'Baseline revision index' index_definition
assert_query '' 'Baseline migration ledger' migration_batch
assert_query 1 'Baseline draft and published pointers' sql 'SELECT count(*) FROM formaku_records WHERE draft_id > published_id'
assert_query 2 'Baseline revision count' sql 'SELECT count(*) FROM formaku_revisions'
snapshot baseline
sql 'SELECT row_to_json(m) FROM migrations m ORDER BY id' > "$scratch/baseline-migrations"
compose up -d --no-build --pull never --wait --wait-timeout 90 web
verify_publication baseline
compose stop web

export FORMAKU_IMAGE="$candidate" FORMAKU_UPGRADE_PLATFORM="$candidate_platform"
echo "Applying $migration on the alpha.60 database."
compose run --rm --no-deps --pull never -T web php artisan migrate \
    --force --no-interaction --path="$migration_path" < /dev/null
batch=$(migration_batch)
if [[ ! "$batch" =~ ^[1-9][0-9]*$ ]]; then
    echo "Expected a single batch for $migration." >&2
    exit 1
fi
assert_query "CREATE INDEX $index ON public.formaku_revisions USING btree (record_id, id)" \
    'Upgraded revision index' index_definition
snapshot upgraded
diff -u "$scratch/baseline-data" "$scratch/upgraded-data"
compose up -d --no-build --pull never --wait --wait-timeout 90 web
verify_publication upgraded
compose stop web

echo "Rolling back $migration from batch $batch."
compose run --rm --no-deps --pull never -T web php artisan migrate:rollback \
    --force --no-interaction --batch="$batch" --path="$migration_path" < /dev/null
assert_query '' 'Rolled back revision index' index_definition
assert_query '' 'Rolled back migration ledger' migration_batch
snapshot rolled-back
sql 'SELECT row_to_json(m) FROM migrations m ORDER BY id' > "$scratch/rolled-back-migrations"
diff -u "$scratch/baseline-data" "$scratch/rolled-back-data"
diff -u "$scratch/baseline-migrations" "$scratch/rolled-back-migrations"

export FORMAKU_IMAGE="$baseline" FORMAKU_UPGRADE_PLATFORM=linux/amd64
compose up -d --no-build --pull never --wait --wait-timeout 90 web
verify_publication rolled-back
echo 'Verified alpha.60 PostgreSQL upgrade and targeted rollback with unchanged revisions and public content.'
