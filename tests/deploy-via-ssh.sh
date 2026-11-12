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
    "deploy $IMAGE $RELEASE_ID")
        echo 'private deployment output'
        echo 'private deployment diagnostic' >&2
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
[[ "$1 $2 $3" == '--kill-after=2s 15s ssh' ]]
printf 'bounded status\n' >> "$TRACE"
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
    local expected=$1 result=0
    : > "$TRACE"
    : > "$GITHUB_STEP_SUMMARY"
    bash "$root/scripts/deploy-via-ssh.sh" > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == "$expected" ]]
    [[ -z $(find "$TMPDIR" -mindepth 1 -print -quit) ]]
    [[ ! -s "$fixture/output" ]]
    printf 'deploy %s %s\nbounded status\nstatus %s\n' "$IMAGE" "$RELEASE_ID" "$RELEASE_ID" | cmp - "$TRACE"
    grep -Fq "SSH deploy exit code: \`$expected\`" "$GITHUB_STEP_SUMMARY"
}

export STATUS_RESPONSE
STATUS_RESPONSE=$(status_json deployed)
run_deploy 0
grep -Fxq 'Host status: `deployed`' "$GITHUB_STEP_SUMMARY"

DEPLOY_RESULT=17
STATUS_RESPONSE=$(status_json failed | jq -c '. + {rollback: "restored", backup: .release, extra: "private field"}')
run_deploy 17
grep -Fxq 'Host status: `failed`' "$GITHUB_STEP_SUMMARY"
grep -Fxq 'Rollback: `restored`' "$GITHUB_STEP_SUMMARY"
grep -Fxq "Backup: \`$RELEASE_ID\`" "$GITHUB_STEP_SUMMARY"
! grep -Fq 'private field' "$GITHUB_STEP_SUMMARY"

DEPLOY_RESULT=255
STATUS_RESPONSE=$(status_json deployed)
run_deploy 255
grep -Fxq 'Host status: `deployed`' "$GITHUB_STEP_SUMMARY"

DEPLOY_RESULT=0
valid=$(status_json deployed)
for STATUS_RESPONSE in '' '{' '{"state":"unavailable"}' "$valid"$'\n'"$valid" \
    "$(jq -c '.release = "other-release"' <<< "$valid")" \
    "$(jq -c '.image = "other-image"' <<< "$valid")" \
    "$(jq -c '.state = "unsupported state"' <<< "$valid")" \
    "$(jq -c '. + {state: "failed", rollback: "private field"}' <<< "$valid")" \
    "$(jq -c '. + {state: "failed"}' <<< "$valid")" \
    "$(jq -c '. + {state: "failed", rollback: "restored", backup: "other-release"}' <<< "$valid")" \
    "$(jq -c '. + {large: ("x" * 4096)}' <<< "$valid")"; do
    run_deploy 0
    grep -Fxq 'Host status unavailable.' "$GITHUB_STEP_SUMMARY"
done

STATUS_RESPONSE=$valid
STATUS_RESULT=255
run_deploy 0
grep -Fxq 'Host status unavailable.' "$GITHUB_STEP_SUMMARY"
STATUS_RESULT=0
STATUS_TIMEOUT=1
DEPLOY_RESULT=23
run_deploy 23
grep -Fxq 'Host status unavailable.' "$GITHUB_STEP_SUMMARY"

: > "$TRACE"
: > "$GITHUB_STEP_SUMMARY"
for bad_input in IMAGE RELEASE_ID HOST USER; do
    result=0
    env "$bad_input=invalid"$'\n'"argument" bash "$root/scripts/deploy-via-ssh.sh" > "$fixture/output" 2>&1 || result=$?
    [[ "$result" == 2 ]]
done
[[ ! -s "$TRACE" && ! -s "$GITHUB_STEP_SUMMARY" ]]
[[ -z $(find "$TMPDIR" -mindepth 1 -print -quit) ]]

printf 'PASS: pinned SSH deploy/status, preserved exit code, scoped summary, bounded query and credential cleanup\n'
