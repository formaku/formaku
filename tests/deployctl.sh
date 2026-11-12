#!/usr/bin/env bash
set -euo pipefail
umask 077

repo=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
controller=${1:-$repo/deploy/deployctl}
dispatcher=${2:-$repo/deploy/ssh-dispatch}
for tool in flock openssl python3 tar; do
    command -v "$tool" >/dev/null || { echo "Required test tool: $tool" >&2; exit 1; }
done
scratch=$(mktemp -d /tmp/formaku-deployctl.XXXXXX)
trap 'rm -rf -- "$scratch"' EXIT
export REAL_OPENSSL
REAL_OPENSSL=$(command -v openssl)

previous="ghcr.io/formaku/formaku@sha256:$(printf '%064d' 1)"
candidate="ghcr.io/formaku/formaku@sha256:$(printf '%064d' 2)"
older="ghcr.io/formaku/formaku@sha256:$(printf '%064d' 3)"
release=22222222-2222-4222-8222-222222222222
prior_release=11111111-1111-4111-8111-111111111111
export PREVIOUS_IMAGE=$previous CANDIDATE_IMAGE=$candidate

mkdir "$scratch/bin"
cat > "$scratch/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail

record() { printf '%s %s\n' "$1" "${FORMAKU_IMAGE:?}" >> "$TRACE"; }
fail_at() { if [[ "$SCENARIO" == "$1" ]]; then exit 42; fi; }

if [[ ${1:-} == pull && $# == 2 && $2 == "$CANDIDATE_IMAGE" ]]; then
    record pull
    fail_at pull
    exit 0
fi
[[ $# -ge 4 && $1 == compose && $2 == --env-file && $3 == .env.docker ]]
shift 3
case "$1" in
    stop)
        [[ $* == 'stop web worker scheduler' ]]
        record stop
        fail_at stop
        ;;
    exec)
        [[ $* == 'exec -T database '*pg_dump* ]]
        record database
        fail_at database
        printf 'CREATE TABLE fixture (id integer);\n'
        ;;
    run)
        if [[ $* == *'--entrypoint tar '* ]]; then
            record uploads
            tar -C "$UPLOADS" -czf - .
        elif [[ $* == *'web php artisan migrate --force' ]]; then
            # Migration may start only after the encrypted archive is finalized.
            [[ -s "$FINALIZED_BACKUP" ]]
            record migrate
            fail_at migrate
        else
            echo "Unexpected Docker run: $*" >&2
            exit 97
        fi
        ;;
    up)
        # The rollback and the candidate startup must both wait for health with a timeout.
        [[ " $* " == *' -d '* && " $* " == *' --no-build '* && " $* " == *' --wait '*
            && $* =~ --wait-timeout\ ([1-9][0-9]*)([[:space:]]|$) ]]
        record up
        if [[ $FORMAKU_IMAGE == "$CANDIDATE_IMAGE" ]]; then
            # The active marker still describes the previous healthy deployment.
            if [[ -n "$ACTIVE_BEFORE" ]]; then
                [[ $(cat active-image) == "$ACTIVE_BEFORE" ]]
            else
                [[ ! -e active-image ]]
            fi
            [[ $SCENARIO != health && $SCENARIO != rollback ]]
        else
            [[ $FORMAKU_IMAGE == "$PREVIOUS_IMAGE" && $SCENARIO != rollback ]]
        fi
        ;;
    *)
        echo "Unexpected Docker Compose command: $*" >&2
        exit 97
        ;;
esac
MOCK
cat > "$scratch/bin/openssl" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$SCENARIO" != encrypt ]]; then exec "$REAL_OPENSSL" "$@"; fi
while (( $# > 1 )); do
    if [[ $1 == -out ]]; then
        cat >/dev/null
        printf 'interrupted ciphertext\n' > "$2"
        exit 43
    fi
    shift
done
echo 'Encryption did not specify an output file' >&2
exit 97
MOCK
chmod +x "$scratch/bin/docker" "$scratch/bin/openssl"

make_fixture() {
    fixture="$scratch/$1"
    mkdir -p "$fixture/uploads" "$fixture/backups"
    printf 'ghcr.io/formaku/formaku\n' > "$fixture/allowed-image"
    printf 'DB_PASSWORD=synthetic-test-value\n' > "$fixture/.env.docker"
    printf 'synthetic-backup-key\n' > "$fixture/backup.key"
    printf 'private synthetic upload\n' > "$fixture/uploads/sample.txt"
    active_before=''
    if [[ ${2:-previous} == previous ]]; then
        active_before=$previous
        printf '%s\n' "$previous" > "$fixture/active-image"
        printf '%s\n' "$older" > "$fixture/previous-image"
        printf '{"state":"deployed","release":"%s","image":"%s"}\n' \
            "$prior_release" "$previous" > "$fixture/release.json"
    fi
    # Only the temporary copy knows the fixture path. The installed controller keeps /opt/formaku.
    grep -Fq /opt/formaku "$controller"
    sed "s|/opt/formaku|$fixture|g" "$controller" > "$fixture/deployctl"
    : > "$fixture/trace"
    archive="$fixture/backups/$release.tar.enc"
}

invoke() {
    PATH="$scratch/bin:$PATH" SCENARIO="$scenario" TRACE="$fixture/trace" \
        UPLOADS="$fixture/uploads" FINALIZED_BACKUP="$archive" ACTIVE_BEFORE="$active_before" \
        bash "$fixture/deployctl" "$@"
}

deploy_success() {
    if ! invoke deploy "$candidate" "$release" > "$fixture/output" 2>&1; then
        cat "$fixture/output" >&2
        echo "Expected deployment success for $scenario" >&2
        exit 1
    fi
}

deploy_failure() {
    if invoke deploy "$candidate" "$release" > "$fixture/output" 2>&1; then
        echo "Expected deployment failure for $scenario" >&2
        exit 1
    fi
}

assert_trace() {
    if ! printf '%s\n' "$@" | diff -u - "$fixture/trace"; then
        cat "$fixture/output" >&2
        echo "Unexpected command sequence for $scenario" >&2
        exit 1
    fi
}

assert_status() {
    local expected_state=$1 expected_rollback=$2 expected_backup=$3 actual
    actual=$(invoke status)
    if [[ "$expected_state" != not_installed ]]; then
        [[ $(invoke status "$release") == "$actual" ]]
    fi
    python3 - "$actual" "$expected_state" "$release" "$candidate" "$expected_rollback" "$expected_backup" <<'PYTHON'
import json
import sys

actual, state, release, image, rollback, backup = sys.argv[1:]
actual = json.loads(actual)
expected = {"state": state}
if state != "not_installed":
    expected.update(release=release, image=image)
if rollback:
    expected["rollback"] = rollback
    if backup:
        expected["backup"] = backup
    else:
        assert "backup" not in actual, actual
assert all(actual.get(key) == value for key, value in expected.items()), (expected, actual)
PYTHON
}

assert_rejected() {
    local code=0
    "$@" > /dev/null 2>&1 || code=$?
    if (( code != 2 )); then
        printf 'Expected command rejection (exit 2), got %s\n' "$code" >&2
        exit 1
    fi
}

assert_backup() {
    local target="$fixture/decrypted"
    [[ $(find "$fixture/backups" -type f -print) == "$archive" ]]
    mkdir -p "$target/uploads"
    "$REAL_OPENSSL" enc -d -aes-256-cbc -pbkdf2 -pass "file:$fixture/backup.key" -in "$archive" \
        | tar -C "$target" -xf -
    printf 'CREATE TABLE fixture (id integer);\n' | cmp - "$target/database.sql"
    cmp "$fixture/.env.docker" "$target/runtime.env"
    tar -C "$target/uploads" -xzf "$target/uploads.tar.gz"
    cmp "$fixture/uploads/sample.txt" "$target/uploads/sample.txt"
}

assert_no_backup() {
    [[ -z $(find "$fixture/backups" -type f -print) ]]
}

assert_previous_unchanged() {
    [[ $(cat "$fixture/active-image") == "$previous" ]]
    [[ $(cat "$fixture/previous-image") == "$older" ]]
}

make_fixture success
scenario=success
deploy_success
assert_trace "pull $candidate" "stop $candidate" "database $candidate" "uploads $candidate" \
    "migrate $candidate" "up $candidate"
[[ $(cat "$fixture/active-image") == "$candidate" ]]
[[ $(cat "$fixture/previous-image") == "$previous" ]]
assert_status deployed '' ''
assert_backup
printf 'PASS: healthy deployment records the new image and produces a recoverable backup\n'

cp "$archive" "$fixture/archived-before"
cp "$fixture/release.json" "$fixture/status-before"
: > "$fixture/trace"
scenario=duplicate
deploy_failure
[[ ! -s "$fixture/trace" ]]
cmp "$fixture/archived-before" "$archive"
cmp "$fixture/status-before" "$fixture/release.json"
[[ $(cat "$fixture/active-image") == "$candidate" ]]
[[ $(cat "$fixture/previous-image") == "$previous" ]]
printf 'PASS: duplicate release ID preserves the finalized backup and status\n'

make_fixture locked
scenario=locked
(
    exec 9>"$fixture/deploy.lock"
    flock -n 9
    invoke status | cmp - "$fixture/release.json"
    invoke status "$prior_release" | cmp - "$fixture/release.json"
    [[ $(invoke status "$release") == '{"state":"unavailable"}' ]]
    deploy_failure
    [[ ! -s "$fixture/trace" ]]
    assert_previous_unchanged
)
printf 'PASS: unscoped and matching scoped status remain readable while the lock rejects a second deployment\n'

make_fixture missing-status none
scenario=missing-status
assert_status not_installed '' ''
[[ $(invoke status "$release") == '{"state":"unavailable"}' ]]
assert_rejected invoke status invalid
assert_rejected invoke status "$release" extra
[[ ! -s "$fixture/trace" ]]
printf 'PASS: scoped status reports missing records and rejects invalid arguments\n'

for scenario in pull stop database encrypt; do
    make_fixture "$scenario"
    deploy_failure
    case "$scenario" in
        pull)
            assert_trace "pull $candidate"
            assert_status failed not_attempted ''
            ;;
        stop)
            assert_trace "pull $candidate" "stop $candidate" "up $previous"
            assert_status failed restored ''
            ;;
        database)
            assert_trace "pull $candidate" "stop $candidate" "database $candidate" "up $previous"
            assert_status failed restored ''
            ;;
        encrypt)
            assert_trace "pull $candidate" "stop $candidate" "database $candidate" "uploads $candidate" "up $previous"
            assert_status failed restored ''
            ;;
    esac
    assert_no_backup
    assert_previous_unchanged
    printf 'PASS: %s failure stops before migration and records rollback outcome\n' "$scenario"
done

for scenario in migrate health rollback; do
    make_fixture "$scenario"
    deploy_failure
    expected=("pull $candidate" "stop $candidate" "database $candidate" "uploads $candidate" "migrate $candidate")
    if [[ "$scenario" != migrate ]]; then expected+=("up $candidate"); fi
    assert_trace "${expected[@]}" "up $previous"
    rollback=restored
    if [[ "$scenario" == rollback ]]; then rollback=failed; fi
    assert_status failed "$rollback" "$release"
    assert_backup
    assert_previous_unchanged
    printf 'PASS: %s failure attempts rollback once and retains the backup reference\n' "$scenario"
done

for scenario in migrate health; do
    make_fixture "first-install-$scenario" none
    assert_status not_installed '' ''
    deploy_failure
    expected=("pull $candidate" "stop $candidate" "database $candidate" "uploads $candidate" "migrate $candidate")
    if [[ "$scenario" == health ]]; then expected+=("up $candidate" "stop $candidate"); fi
    assert_trace "${expected[@]}"
    assert_status failed unavailable "$release"
    assert_backup
    [[ ! -e "$fixture/active-image" && ! -e "$fixture/previous-image" ]]
    printf 'PASS: first deployment with %s failure leaves no active image and retains the backup\n' "$scenario"
done

cat > "$scratch/bin/sudo" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$@" > "$SUDO_TRACE"
MOCK
chmod +x "$scratch/bin/sudo"
sudo_trace="$scratch/sudo.trace"
dispatch() {
    PATH="$scratch/bin:$PATH" SUDO_TRACE="$sudo_trace" SSH_ORIGINAL_COMMAND="$1" bash "$dispatcher"
}

dispatch "status $release"
printf '%s\n' -n /usr/local/sbin/formaku-deployctl status "$release" | cmp - "$sudo_trace"
dispatch "deploy $candidate $release"
printf '%s\n' /usr/bin/systemd-run --quiet --collect --wait "--unit=formaku-$release" \
    /usr/local/sbin/formaku-deployctl deploy "$candidate" "$release" | cmp - "$sudo_trace"
: > "$sudo_trace"
for request in '' 'status' 'status invalid' "status $release extra" "status $candidate $release" \
    "deploy $candidate" "deploy $candidate $release extra" "unknown $release" \
    "status $release"$'\n' "status $release"$'\r' "deploy $candidate $release"$'\n'"status $release"; do
    assert_rejected dispatch "$request"
done
[[ ! -s "$sudo_trace" ]]
printf 'PASS: SSH dispatcher forwards only allowed deploy and scoped status commands\n'
