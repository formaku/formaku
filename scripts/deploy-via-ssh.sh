#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ ! "${IMAGE:-}" =~ ^ghcr.io/[a-z0-9_.-]+/[a-z0-9_.-]+@sha256:[a-f0-9]{64}$ ||
    ! "${RELEASE_ID:-}" =~ ^[a-f0-9-]{36}$ || ! "${HOST:-}" =~ ^[a-zA-Z0-9.-]+$ ||
    ! "${USER:-}" =~ ^[a-z_][a-z0-9_-]*$ || -z "${SSH_KEY:-}" || -z "${KNOWN_HOSTS:-}" ]]; then
    echo 'Invalid deployment inputs or missing SSH configuration.' >&2
    exit 2
fi

ssh_dir=$(mktemp -d)
trap 'rm -rf -- "$ssh_dir"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
printf '%s\n' "$SSH_KEY" > "$ssh_dir/key"
printf '%s\n' "$KNOWN_HOSTS" > "$ssh_dir/known_hosts"
unset SSH_KEY KNOWN_HOSTS

connection=(
    ssh -F /dev/null -n -i "$ssh_dir/key"
    -o BatchMode=yes -o ConnectTimeout=10 -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes -o UpdateHostKeys=no
    -o GlobalKnownHostsFile=/dev/null -o "UserKnownHostsFile=$ssh_dir/known_hosts"
    "$USER@$HOST"
)

deploy_result=0
"${connection[@]}" "deploy $IMAGE $RELEASE_ID" >/dev/null 2>&1 || deploy_result=$?

host_status='Host status unavailable.'
if timeout --kill-after=2s 15s "${connection[@]}" "status $RELEASE_ID" 2>/dev/null \
    | head -c 4097 > "$ssh_dir/status.json" &&
    [[ $(wc -c < "$ssh_dir/status.json") -le 4096 ]]; then
    if verified_status=$(jq -ers --arg release "$RELEASE_ID" --arg image "$IMAGE" '
        select(length == 1) | .[0] | objects
        | select(.release == $release and .image == $image)
        | select(.state == "deploying" or .state == "deployed" or .state == "failed")
        | select(if .state == "failed" then
            .rollback as $rollback
            | ["not_attempted", "restored", "failed", "unavailable"] | index($rollback) != null
          else .rollback == null end)
        | select(.backup == null or .backup == $release)
        | "Host status: `\(.state)`"
          + (if .rollback then "\n\nRollback: `\(.rollback)`" else "" end)
          + (if .backup then "\n\nBackup: `\(.backup)`" else "" end)
    ' "$ssh_dir/status.json" 2>/dev/null); then
        host_status=$verified_status
    fi
fi

printf '### Deployment\n\nRelease: `%s`\n\nImage: `%s`\n\nSSH deploy exit code: `%s`\n\n%s\n' \
    "$RELEASE_ID" "$IMAGE" "$deploy_result" "$host_status" >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}" \
    || echo 'Could not write the deployment summary.' >&2
exit "$deploy_result"
