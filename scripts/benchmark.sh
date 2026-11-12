#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ $# -lt 1 || ! "$1" =~ ^(ghcr\.io/formaku/formaku@sha256:[a-f0-9]{64}|formaku-benchmark:[a-f0-9]{40})$ ]]; then
    echo "Usage: bash scripts/benchmark.sh IMAGE [--concurrency N] [--warmup SECONDS] [--duration SECONDS]" >&2
    exit 2
fi

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
export FORMAKU_IMAGE=$1
shift
postgres=postgres@sha256:3645570cccdfa447589da9f57dd740faa29b30938e861289a5574b6ca6b03826
endpoint=${DOCKER_HOST:-$(docker context inspect --format '{{.Endpoints.docker.Host}}')}
if [[ "$endpoint" != unix://* ]]; then
    echo 'The loopback benchmark requires a local Unix-socket Docker context.' >&2
    exit 2
fi
case "$(docker info --format '{{.Architecture}}')" in
    x86_64|amd64) architecture=amd64 ;;
    aarch64|arm64) architecture=arm64 ;;
    *) echo 'Unsupported Docker architecture.' >&2; exit 2 ;;
esac
source_build=false
if [[ "$FORMAKU_IMAGE" == formaku-benchmark:* ]]; then
    source_build=true
else
    if [[ "$architecture" != amd64 ]]; then
        echo 'The published image benchmark requires native Linux AMD64 Docker.' >&2
        exit 2
    fi
    docker pull --platform linux/amd64 "$FORMAKU_IMAGE" >&2
fi
if [[ "$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$FORMAKU_IMAGE")" != "linux/$architecture" ]]; then
    echo 'Use a native image for the Docker engine architecture.' >&2
    exit 2
fi
revision=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$FORMAKU_IMAGE")
if [[ ! "$revision" =~ ^[a-f0-9]{40}$ || ( "$source_build" == true && "$FORMAKU_IMAGE" != "formaku-benchmark:$revision" ) ]]; then
    echo 'The image must carry the exact source revision in its OCI label.' >&2
    exit 2
fi
docker pull --platform "linux/$architecture" "$postgres" >&2

scratch=$(mktemp -d)
project="formaku-bench-$(openssl rand -hex 6)"
compose=(docker compose --project-name "$project" --project-directory "$scratch" --env-file "$scratch/.env.docker" -f "$scratch/compose.yaml" -f "$scratch/limits.yaml")
cleanup() {
    local status=$?
    trap - EXIT
    if ! "${compose[@]}" down --volumes --remove-orphans >&2; then
        echo "Could not remove the isolated Compose project $project." >&2
        status=1
    fi
    rm -rf -- "$scratch"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cp "$root/compose.yaml" "$scratch/compose.yaml"
cat > "$scratch/limits.yaml" <<'YAML'
services:
  web:
    build: !reset null
    ports: !override ["127.0.0.1::8080"]
    cpus: 1.0
    mem_limit: 1536m
    memswap_limit: 1536m
  database:
    image: ${FORMAKU_BENCHMARK_DATABASE}
    environment:
      POSTGRES_DB: formaku_benchmark
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U formaku -d formaku_benchmark"]
    cpus: 0.75
    mem_limit: 2048m
    memswap_limit: 2048m
  worker:
    cpus: 0.125
    mem_limit: 256m
    memswap_limit: 256m
  scheduler:
    cpus: 0.125
    mem_limit: 256m
    memswap_limit: 256m
YAML
export FORMAKU_BENCHMARK_DATABASE=$postgres
export FORMAKU_BENCHMARK_PASSWORD
FORMAKU_BENCHMARK_PASSWORD=$(openssl rand -hex 24)
sed -e "s|^APP_KEY=$|APP_KEY=base64:$(openssl rand -base64 32)|" \
    -e "s|^DB_PASSWORD=$|DB_PASSWORD=$(openssl rand -hex 24)|" \
    -e 's|^APP_ENV=production$|APP_ENV=benchmark|' \
    -e 's|^DB_DATABASE=formaku$|DB_DATABASE=formaku_benchmark|' \
    "$root/.env.docker.example" > "$scratch/.env.docker"

"${compose[@]}" up -d --no-build --pull never --wait --wait-timeout 90 web >&2
"${compose[@]}" exec -T web php artisan migrate --force --no-interaction >&2
collection=$("${compose[@]}" exec -T -e FORMAKU_BENCHMARK_PASSWORD web php < "$root/scripts/benchmark-fixture.php")
[[ "$collection" =~ ^[1-9][0-9]*$ ]] || { echo 'Fixture did not return a collection ID.' >&2; exit 1; }
"${compose[@]}" up -d --no-build --pull never --wait --wait-timeout 90 >&2
address=$("${compose[@]}" port web 8080)
[[ "$address" =~ ^127\.0\.0\.1:([0-9]+)$ ]] || { echo 'Expected a loopback-only benchmark port.' >&2; exit 1; }
port=${BASH_REMATCH[1]}

check_limits() {
    local service=$1 cpus=$2 mib=$3 id
    id=$("${compose[@]}" ps -q "$service")
    [[ "$(docker inspect --format '{{.HostConfig.NanoCpus}} {{.HostConfig.Memory}} {{.HostConfig.MemorySwap}} {{.State.OOMKilled}} {{.RestartCount}} {{.State.Health.Status}}' "$id")" \
        == "$cpus $((mib * 1048576)) $((mib * 1048576)) false 0 healthy" ]]
}
check_stack() {
    check_limits web 1000000000 1536 && check_limits database 750000000 2048 \
        && check_limits worker 125000000 256 && check_limits scheduler 125000000 256 \
        || { echo 'Stack resources, health, or restart count differ from the benchmark profile.' >&2; return 1; }
}
check_stack
result=0
python3 "$root/scripts/benchmark-http.py" "$@" --port "$port" --collection "$collection" > "$scratch/report.json" || result=$?
check_stack
python3 - "$scratch/report.json" "$FORMAKU_IMAGE" "$revision" "$architecture" "$postgres" "$source_build" <<'PY'
import json
import platform
import subprocess
import sys

path, image, revision, architecture, postgres, source_build = sys.argv[1:]
with open(path) as stream:
    result = json.load(stream)
result.update(
    image=image,
    source_revision=revision,
    docker_architecture=architecture,
    postgres_image=postgres,
    source_build=source_build == "true",
    load_generator_os=platform.system(),
    load_generator_python=platform.python_version(),
    docker_version=subprocess.check_output(["docker", "version", "--format", "{{.Server.Version}}"], text=True).strip(),
    compose_version=subprocess.check_output(["docker", "compose", "version", "--short"], text=True).strip(),
    docker_engine_resources=json.loads(subprocess.check_output(
        ["docker", "info", "--format", '{"cpus":{{.NCPU}},"memory_bytes":{{.MemTotal}}}'],
        text=True,
    )),
    resources={
        "web": {"vcpu": 1.0, "memory_mib": 1536},
        "database": {"vcpu": 0.75, "memory_mib": 2048},
        "worker": {"vcpu": 0.125, "memory_mib": 256},
        "scheduler": {"vcpu": 0.125, "memory_mib": 256},
    },
)
print(json.dumps(result, indent=2))
PY
exit "$result"
