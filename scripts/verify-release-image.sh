#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 5 ]]; then
    echo "Usage: $0 IMAGE DIGEST SOURCE_URL SOURCE_COMMIT VERSION" >&2
    exit 1
fi

image=$1
digest=$2
source_url=$3
source_commit=$4
version=$5
if [[ ! "$image" =~ ^ghcr\.io/[a-z0-9][a-z0-9._/-]*$ || ! "$digest" =~ ^sha256:[a-f0-9]{64}$ \
    || ! "$source_commit" =~ ^[a-f0-9]{40}$ || -z "$source_url" || -z "$version" ]]; then
    echo 'Expected a GHCR repository, SHA-256 digest, source URL, full commit SHA, and version.' >&2
    exit 1
fi

image_ref="$image@$digest"
verify_docker_config=$(mktemp -d)
trap 'rm -rf -- "$verify_docker_config"' EXIT
# An explicit empty entry prevents Docker from discovering the host credential helper.
printf '%s\n' '{"auths":{"ghcr.io":{}}}' > "$verify_docker_config/config.json"
docker --config "$verify_docker_config" pull --quiet --platform linux/amd64 "$image_ref"

docker --config "$verify_docker_config" image inspect --format '{{json .}}' "$image_ref" \
    | jq -e --arg source "$source_url" --arg revision "$source_commit" --arg version "$version" '
        if .Os != "linux" or .Architecture != "amd64" then error("Expected linux/amd64 image")
        elif .Config.Labels["org.opencontainers.image.source"] != $source then error("OCI source label does not match")
        elif .Config.Labels["org.opencontainers.image.revision"] != $revision then error("OCI revision label does not match")
        elif .Config.Labels["org.opencontainers.image.version"] != $version then error("OCI version label does not match")
        else true end
    ' >/dev/null

printf 'Verified anonymous linux/amd64 pull and OCI labels for %s at %s\n' "$image_ref" "$source_commit"
