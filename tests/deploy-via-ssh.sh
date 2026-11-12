#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
mkdir "$fixture/bin" "$fixture/tmp"
export PATH="$fixture/bin:$PATH" TMPDIR="$fixture/tmp"
export IMAGE="ghcr.io/formaku/formaku@sha256:$(printf 'a%.0s' {1..64})"
export RELEASE_ID=12345678-1234-1234-1234-123456789abc
export USER=formaku-deploy HOST=example.invalid
export SSH_KEY='test-secret-private-key' KNOWN_HOSTS='test-pinned-host-key'
export GITHUB_STEP_SUMMARY="$fixture/summary" TRACE="$fixture/trace"
export DEPLOY_RESULT=0 STATUS_RESULT=0 STATUS_TIMEOUT=0
unset FORMAKU_DEPLOYMENT_RECEIPT

cat > "$fixture/bin/ssh" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
[[ -z "${SSH_KEY+x}" && -z "${KNOWN_HOSTS+x}" ]]
[[ "$1" == -F && "$2" == /dev/null && "$3" == -n && "$4" == -i ]]
key=$5
shift 5
[[ "$1 $2 $3 $4 $5 $6" == '-o BatchMode=yes -o ConnectTimeout=10 -o IdentitiesOnly=yes' ]]
shift 6
[[ "$1 $2 $3 $4 $5 $6 $7" == '-o StrictHostKeyChecking=yes -o UpdateHostKeys=no -o GlobalKnownHostsFile=/dev/null -o' ]]
known_hosts=${8#UserKnownHostsFile=}
shift 8
[[ "$#" == 2 && "$1" == "$USER@$HOST" ]]
[[ "$(cat "$key")" == 'test-secret-private-key' && "$(cat "$known_hosts")" == 'test-pinned-host-key' ]]
python3 - "$key" "$known_hosts" <<'PYTHON'
import pathlib
import stat
import sys

for filename in sys.argv[1:]:
    file = pathlib.Path(filename)
    assert stat.S_IMODE(file.stat().st_mode) == 0o600
    assert stat.S_IMODE(file.parent.stat().st_mode) == 0o700
PYTHON
printf '%s\n' "$2" >> "$TRACE"
case "$2" in
    runtime)
        printf '%s\n' "$STATUS_RESPONSE"
        echo 'private runtime diagnostic' >&2
        exit "$STATUS_RESULT"
        ;;
    "deploy $IMAGE $RELEASE_ID")
        echo 'private deployment output'
        echo 'deployment SSH diagnostic' >&2
        exit "$DEPLOY_RESULT"
        ;;
    "status $RELEASE_ID")
        printf '%s\n' "$STATUS_RESPONSE"
        echo 'private status diagnostic' >&2
        exit "$STATUS_RESULT"
        ;;
    *) exit 100 ;;
esac
MOCK
cat > "$fixture/bin/timeout" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
case "$1 $2 $3" in
    '--kill-after=2s 15s ssh') printf 'bounded status\n' >> "$TRACE" ;;
    '--kill-after=2s 55s ssh') printf 'bounded runtime\n' >> "$TRACE" ;;
    *) exit 100 ;;
esac
shift 2
if [[ "$STATUS_TIMEOUT" == 1 ]]; then
    "$@"
    exit 124
fi
exec "$@"
MOCK
chmod +x "$fixture/bin/ssh" "$fixture/bin/timeout"

status_json() {
    jq -nc --arg release "$RELEASE_ID" --arg image "$IMAGE" --arg state "$1" \
        '{release: $release, image: $image, state: $state}'
}

run_deploy() {
    local expected=$1 receipt_error=${2:-0} result=0
    : > "$TRACE"
    : > "$GITHUB_STEP_SUMMARY"
    bash "$root/scripts/deploy-via-ssh.sh" > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == "$expected" ]]
    [[ -z $(find "$TMPDIR" -mindepth 1 -print -quit) ]]
    {
        printf 'deployment SSH diagnostic\n'
        if [[ "$receipt_error" == 1 ]]; then
            printf 'Could not write the deployment receipt.\n'
        fi
    } | cmp - "$fixture/output"
    printf 'deploy %s %s\nbounded status\nstatus %s\n' "$IMAGE" "$RELEASE_ID" "$RELEASE_ID" | cmp - "$TRACE"
    grep -Fq "SSH deploy exit code: \`$expected\`" "$GITHUB_STEP_SUMMARY"
}

run_status() {
    local expected=$1 expected_summary=$2 result=0
    : > "$TRACE"
    : > "$GITHUB_STEP_SUMMARY"
    bash "$root/scripts/deploy-via-ssh.sh" --status-only > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == "$expected" ]]
    [[ ! -s "$fixture/output" && -z $(find "$TMPDIR" -mindepth 1 -print -quit) ]]
    printf 'bounded status\nstatus %s\n' "$RELEASE_ID" | cmp - "$TRACE"
    printf '### Deployment status read\n\nRelease: `%s`\n\n%s\n' \
        "$RELEASE_ID" "$expected_summary" | cmp - "$GITHUB_STEP_SUMMARY"
}

run_runtime() {
    local expected=$1 expected_summary=$2 result=0
    : > "$TRACE"
    : > "$GITHUB_STEP_SUMMARY"
    env -u IMAGE -u RELEASE_ID bash "$root/scripts/deploy-via-ssh.sh" --runtime-only \
        > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == "$expected" ]]
    [[ ! -s "$fixture/output" && -z $(find "$TMPDIR" -mindepth 1 -print -quit) ]]
    printf 'bounded runtime\nruntime\n' | cmp - "$TRACE"
    printf '### Deployment runtime\n\n%s\n' "$expected_summary" | cmp - "$GITHUB_STEP_SUMMARY"
}

check_receipt() {
    jq -es --arg release "$RELEASE_ID" --arg image "$IMAGE" \
        --argjson ssh_exit_code "$1" --argjson host_status "$2" \
        '. == [{format: 1, $release, $image, $ssh_exit_code, $host_status}]' \
        "$FORMAKU_DEPLOYMENT_RECEIPT" > /dev/null
}

export STATUS_RESPONSE
STATUS_RESPONSE=$(status_json deployed)
run_deploy 0
grep -Fxq 'Host status: `deployed`' "$GITHUB_STEP_SUMMARY"

export FORMAKU_DEPLOYMENT_RECEIPT="$fixture/deployment receipt.json"
run_deploy 0
check_receipt 0 '{"state":"deployed","rollback":null,"backup":null}'

DEPLOY_RESULT=17
STATUS_RESPONSE=$(status_json failed | jq -c '. + {rollback: "restored", backup: .release, extra: "private field"}')
run_deploy 17
grep -Fxq 'Host status: `failed`' "$GITHUB_STEP_SUMMARY"
grep -Fxq 'Rollback: `restored`' "$GITHUB_STEP_SUMMARY"
grep -Fxq "Backup: \`$RELEASE_ID\`" "$GITHUB_STEP_SUMMARY"
! grep -Fq 'private field' "$GITHUB_STEP_SUMMARY"
check_receipt 17 "$(jq -nc --arg backup "$RELEASE_ID" '{state: "failed", rollback: "restored", $backup}')"

DEPLOY_RESULT=255
STATUS_RESPONSE=$(status_json deployed)
run_deploy 255
grep -Fxq 'Host status: `deployed`' "$GITHUB_STEP_SUMMARY"
check_receipt 255 '{"state":"deployed","rollback":null,"backup":null}'

STATUS_RESPONSE=$(status_json deploying)
run_deploy 255
grep -Fxq 'Host status: `deploying`' "$GITHUB_STEP_SUMMARY"
check_receipt 255 '{"state":"deploying","rollback":null,"backup":null}'

DEPLOY_RESULT=0
valid=$(status_json deployed)
for STATUS_RESPONSE in '' '{' '{"state":"unavailable"}' "$valid"$'\n'"$valid" \
    "$(jq -c '.release = "other-release"' <<< "$valid")" \
    "$(jq -c '.image = "other-image"' <<< "$valid")" \
    "$(jq -c '.state = "unsupported state"' <<< "$valid")" \
    "$(jq -c '. + {state: "failed", rollback: "private field"}' <<< "$valid")" \
    "$(jq -c '. + {state: "failed", rollback: ["restored"]}' <<< "$valid")" \
    "$(jq -c '. + {state: "failed"}' <<< "$valid")" \
    "$(jq -c '. + {state: "failed", rollback: "restored", backup: "other-release"}' <<< "$valid")" \
    "$(jq -c '. + {backup: .release}' <<< "$valid")" \
    "$(jq -c '. + {large: ("x" * 4096)}' <<< "$valid")"; do
    run_deploy 0
    grep -Fxq 'Host status unavailable.' "$GITHUB_STEP_SUMMARY"
    check_receipt 0 null
done

STATUS_RESPONSE=$valid
STATUS_RESULT=255
run_deploy 0
grep -Fxq 'Host status unavailable.' "$GITHUB_STEP_SUMMARY"
check_receipt 0 null
STATUS_RESULT=0
STATUS_TIMEOUT=1
DEPLOY_RESULT=23
run_deploy 23
grep -Fxq 'Host status unavailable.' "$GITHUB_STEP_SUMMARY"
check_receipt 23 null

receipt=$FORMAKU_DEPLOYMENT_RECEIPT
FORMAKU_DEPLOYMENT_RECEIPT="$fixture"
STATUS_TIMEOUT=0
run_deploy 23 1
grep -Fxq 'Host status: `deployed`' "$GITHUB_STEP_SUMMARY"
[[ -z $(find "$fixture" -maxdepth 1 -name 'deployment receipt.json.*' -print -quit) ]]
FORMAKU_DEPLOYMENT_RECEIPT=$receipt

printf 'existing deployment receipt\n' > "$receipt"
DEPLOY_RESULT=99
STATUS_RESPONSE=$valid
run_status 0 'Host status: `deployed`'
printf 'existing deployment receipt\n' | cmp - "$receipt"
rm -f -- "$receipt"
STATUS_RESPONSE=$(status_json deploying)
run_status 0 'Host status: `deploying`'
STATUS_RESPONSE=$(status_json failed | jq -c '. + {rollback: "restored", backup: .release, extra: "private field"}')
run_status 0 $'Host status: `failed`\n\nRollback: `restored`'

for STATUS_RESPONSE in '{' "$valid"$'\n'"$valid" \
    "$(jq -c '.release = "other-release"' <<< "$valid")" \
    "$(jq -c '.image = "other-image"' <<< "$valid")"; do
    run_status 3 'Host status unavailable.'
done
STATUS_RESPONSE=$valid
STATUS_RESULT=255
run_status 3 'Host status unavailable.'
STATUS_RESULT=0
STATUS_TIMEOUT=1
run_status 3 'Host status unavailable.'
STATUS_TIMEOUT=0
[[ ! -e "$receipt" ]]

runtime_valid=$(jq -nc --arg image "$IMAGE" '{state:"verified", $image}')
STATUS_RESPONSE=$runtime_valid
printf 'existing deployment receipt\n' > "$receipt"
run_runtime 0 "$(printf 'Host runtime: `verified`\n\nActive image: `%s`' "$IMAGE")"
printf 'existing deployment receipt\n' | cmp - "$receipt"
rm -f -- "$receipt"

result=0
GITHUB_STEP_SUMMARY="$fixture" env -u IMAGE -u RELEASE_ID bash "$root/scripts/deploy-via-ssh.sh" --runtime-only \
    > "$fixture/output" 2>&1 || result=$?
[[ "$result" == 3 && ! -e "$receipt" ]]
grep -Fxq 'Could not write the deployment runtime summary.' "$fixture/output"

for state in transitioning mismatch unavailable; do
    STATUS_RESPONSE=$(jq -nc --arg state "$state" '{$state}')
    run_runtime 3 "$(printf 'Host runtime: `%s`' "$state")"
done

for STATUS_RESPONSE in '' '{' '[]' "$runtime_valid"$'\n'"$runtime_valid" \
    '{"state":"verified"}' '{"state":"mismatch","state":"transitioning"}' \
    "$(jq -c '. + {extra: "private field"}' <<< "$runtime_valid")" \
    "$(jq -c '.state = "transitioning"' <<< "$runtime_valid")" \
    "$(jq -c '.image = "ghcrXio/example/site@sha256:" + ("a" * 64)' <<< "$runtime_valid")" \
    "$(jq -c '.image = .image + "\nprivate data"' <<< "$runtime_valid")" \
    "$(jq -c '.image = "ghcr.io/example/site@sha256:bad"' <<< "$runtime_valid")" \
    "$(printf '%4097s%s' '' "$runtime_valid")"; do
    run_runtime 3 'Host runtime: `unavailable`'
done
STATUS_RESPONSE=$runtime_valid
STATUS_RESULT=255
run_runtime 3 'Host runtime: `unavailable`'
STATUS_RESULT=0
STATUS_TIMEOUT=1
run_runtime 3 'Host runtime: `unavailable`'
STATUS_TIMEOUT=0
[[ ! -e "$receipt" ]]

: > "$TRACE"
: > "$GITHUB_STEP_SUMMARY"
for bad_input in IMAGE RELEASE_ID HOST USER; do
    result=0
    env "$bad_input=invalid"$'\n'"argument" bash "$root/scripts/deploy-via-ssh.sh" > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == 2 ]]
    result=0
    env "$bad_input=invalid"$'\n'"argument" bash "$root/scripts/deploy-via-ssh.sh" --status-only > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == 2 ]]
done
for bad_arg in --unknown ''; do
    result=0
    bash "$root/scripts/deploy-via-ssh.sh" "$bad_arg" > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == 2 ]]
    grep -Fxq 'Usage: deploy-via-ssh.sh [--status-only|--runtime-only]' "$fixture/output"
done
result=0
bash "$root/scripts/deploy-via-ssh.sh" --status-only --status-only > "$fixture/output" 2>&1 || result=$?
[[ "$result" == 2 ]]
for bad_input in HOST USER SSH_KEY KNOWN_HOSTS; do
    result=0
    env -u IMAGE -u RELEASE_ID -u "$bad_input" bash "$root/scripts/deploy-via-ssh.sh" --runtime-only \
        > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == 2 ]]
done
result=0
bash "$root/scripts/deploy-via-ssh.sh" --runtime-only extra > "$fixture/output" 2>&1 || result=$?
[[ "$result" == 2 ]]
[[ ! -s "$TRACE" && ! -s "$GITHUB_STEP_SUMMARY" && ! -e "$receipt" ]]
[[ -z $(find "$TMPDIR" -mindepth 1 -print -quit) ]]

printf 'PASS: pinned SSH deploy, status and runtime reads, scoped summaries and receipt, bounded queries and credential cleanup\n'
