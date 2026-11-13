#!/usr/bin/env bash
set -euo pipefail
umask 077

root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
if [[ "${GITHUB_ACTIONS:-}" != true || "${RUNNER_ENVIRONMENT:-}" != github-hosted ||
    "${GITHUB_EVENT_NAME:-}" != workflow_dispatch || "${COMPOSE_PROJECT_NAME:-}" != formaku-ci ]]; then
    echo 'Runtime smoke requires the isolated formaku-ci project on a manual GitHub-hosted runner.' >&2
    exit 2
fi
if [[ ! "${FORMAKU_IMAGE:-}" =~ ^ghcr\.io/formaku/formaku@sha256:[a-f0-9]{64}$ ]]; then
    echo 'Runtime smoke requires a digest-pinned public Formaku image.' >&2
    exit 2
fi

scratch=$(mktemp -d "${RUNNER_TEMP:?}/formaku-runtime.XXXXXX")
trap 'rm -rf -- "$scratch"' EXIT
cp "$root/compose.yaml" "$root/.env.docker" "$scratch/"
sed "s|/opt/formaku|$scratch|g" "$root/deploy/deployctl" > "$scratch/deployctl"
printf '%s\n' "${FORMAKU_IMAGE%@*}" > "$scratch/allowed-image"
printf '%s\n' "$FORMAKU_IMAGE" > "$scratch/active-image"

compose=(docker compose --project-name formaku-ci --file "$root/compose.yaml" --env-file "$root/.env.docker")
scheduler=$("${compose[@]}" ps --quiet scheduler)
if [[ ! "$scheduler" =~ ^[a-f0-9]{12,64}$ ||
    $(docker container inspect --format '{{index .Config.Labels "com.docker.compose.project"}} {{index .Config.Labels "com.docker.compose.service"}}' "$scheduler") != 'formaku-ci scheduler' ]]; then
    echo 'Expected exactly one running scheduler in the isolated formaku-ci project.' >&2
    exit 1
fi

check_runtime() {
    local expected=$1
    COMPOSE_FILE="$scratch/compose.yaml" bash "$scratch/deployctl" runtime |
        jq -es --arg expected "$expected" --arg image "$FORMAKU_IMAGE" '
            if $expected == "verified" then
                . == [{state: $expected, image: $image}]
            else
                . == [{state: $expected}]
            end
        ' > /dev/null
    printf 'Runtime observation: %s\n' "$expected"
}

check_runtime verified
"${compose[@]}" stop scheduler
[[ $(docker container inspect --format '{{.State.Status}}' "$scheduler") == exited ]]
check_runtime mismatch
"${compose[@]}" start --wait --wait-timeout 60 scheduler
check_runtime verified
