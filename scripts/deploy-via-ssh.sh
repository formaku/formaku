#!/usr/bin/env bash
set -euo pipefail
umask 077

status_only=0
if [[ "$#" -eq 1 && "${1:-}" == --status-only ]]; then
    status_only=1
elif (( $# != 0 )); then
    echo 'Usage: deploy-via-ssh.sh [--status-only]' >&2
    exit 2
fi

if [[ ! "${IMAGE:-}" =~ ^ghcr.io/[a-z0-9_.-]+/[a-z0-9_.-]+@sha256:[a-f0-9]{64}$ ||
    ! "${RELEASE_ID:-}" =~ ^[a-f0-9-]{36}$ || ! "${HOST:-}" =~ ^[a-zA-Z0-9.-]+$ ||
    ! "${USER:-}" =~ ^[a-z_][a-z0-9_-]*$ || -z "${SSH_KEY:-}" || -z "${KNOWN_HOSTS:-}" ]]; then
    echo 'Invalid deployment inputs or missing SSH configuration.' >&2
    exit 2
fi

ssh_dir=$(mktemp -d)
receipt_tmp=
cleanup() {
    rm -rf -- "$ssh_dir"
    if [[ -n "$receipt_tmp" ]]; then
        rm -f -- "$receipt_tmp"
    fi
}
trap cleanup EXIT
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
if (( ! status_only )); then
    "${connection[@]}" "deploy $IMAGE $RELEASE_ID" >/dev/null || deploy_result=$?
fi

host_status=null
if timeout --kill-after=2s 15s "${connection[@]}" "status $RELEASE_ID" 2>/dev/null \
    | head -c 4097 > "$ssh_dir/status.json" &&
    [[ $(wc -c < "$ssh_dir/status.json") -le 4096 ]]; then
    if verified_status=$(jq -ecs --arg release "$RELEASE_ID" --arg image "$IMAGE" '
        select(length == 1) | .[0] | objects
        | select(.release == $release and .image == $image)
        | select(.state == "deploying" or .state == "deployed" or .state == "failed")
        | select(if .state == "failed" then
            .rollback | strings as $rollback
            | ["not_attempted", "restored", "failed", "unavailable"] | index($rollback) != null
          else .rollback == null end)
        | select(.backup == null or (.state == "failed" and .backup == $release))
        | {state, rollback, backup}
    ' "$ssh_dir/status.json" 2>/dev/null); then
        host_status=$verified_status
    fi
fi

summary_status='Host status unavailable.'
if [[ "$host_status" != null ]]; then
    summary_status=$(jq -r --argjson status_only "$status_only" '
        "Host status: `\(.state)`"
        + (if .rollback then "\n\nRollback: `\(.rollback)`" else "" end)
        + (if .backup and $status_only == 0 then "\n\nBackup: `\(.backup)`" else "" end)
    ' <<< "$host_status") || summary_status='Host status unavailable.'
fi

if (( status_only )); then
    printf '### Deployment status read\n\nRelease: `%s`\n\n%s\n' \
        "$RELEASE_ID" "$summary_status" >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}" \
        || echo 'Could not write the deployment status summary.' >&2
    if [[ "$host_status" == null ]]; then
        exit 3
    fi
    exit 0
fi

printf '### Deployment\n\nRelease: `%s`\n\nImage: `%s`\n\nSSH deploy exit code: `%s`\n\n%s\n' \
    "$RELEASE_ID" "$IMAGE" "$deploy_result" "$summary_status" >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}" \
    || echo 'Could not write the deployment summary.' >&2

if [[ -n "${FORMAKU_DEPLOYMENT_RECEIPT:-}" ]]; then
    receipt_path=$FORMAKU_DEPLOYMENT_RECEIPT
    if [[ ! -d "$receipt_path" ]] &&
        receipt_tmp=$(mktemp -- "${receipt_path}.XXXXXX") &&
        jq -n --arg release "$RELEASE_ID" --arg image "$IMAGE" \
            --argjson ssh_exit_code "$deploy_result" --argjson host_status "$host_status" \
            '{format: 1, $release, $image, $ssh_exit_code, $host_status}' > "$receipt_tmp" &&
        mv -f -- "$receipt_tmp" "$receipt_path"; then
        receipt_tmp=
    else
        echo 'Could not write the deployment receipt.' >&2
    fi
fi
exit "$deploy_result"
