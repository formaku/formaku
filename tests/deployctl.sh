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
export PREVIOUS_IMAGE_ID="sha256:$(printf '%064d' 101)" CANDIDATE_IMAGE_ID="sha256:$(printf '%064d' 102)"
export WEB_CONTAINER=$(printf '%064d' 11) WORKER_CONTAINER=$(printf '%064d' 12) SCHEDULER_CONTAINER=$(printf '%064d' 13)

mkdir "$scratch/bin"
cat > "$scratch/bin/docker" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail

record() { printf '%s %s\n' "$1" "${FORMAKU_IMAGE:?}" >> "$TRACE"; }
fail_at() { if [[ "$SCENARIO" == "$1" ]]; then exit 42; fi; }

image_id() {
    case "$1" in
        "$PREVIOUS_IMAGE") printf '%s\n' "$PREVIOUS_IMAGE_ID" ;;
        "$CANDIDATE_IMAGE") printf '%s\n' "$CANDIDATE_IMAGE_ID" ;;
        *) return 42 ;;
    esac
}

if [[ ${RUNTIME_READ:-} == 1 ]]; then
    [[ ${BOUNDED_RUNTIME_READ:-} == 1 ]] || { echo 'unexpected unbounded Docker command' >> "$TRACE"; exit 97; }
    if [[ $# == 5 && $1 == image && $2 == inspect && $3 == --format && $4 == '{{.Id}}' ]]; then
        printf 'image inspect %s\n' "$5" >> "$TRACE"
        if [[ $RUNTIME_CASE == inspect-error ]]; then
            printf 'private synthetic Docker diagnostic\n' >&2
            exit 42
        fi
        image_id "$5"
    elif [[ $# == 6 && "$1 $2 $3 $4 $5" == 'compose --env-file .env.docker ps --quiet' ]]; then
        printf 'compose ps %s %s\n' "$6" "$FORMAKU_IMAGE" >> "$TRACE"
        [[ $FORMAKU_IMAGE == "$(cat active-image)" ]] || { echo 'unexpected Compose image' >> "$TRACE"; exit 97; }
        case "$6" in
            web) printf '%s\n' "$WEB_CONTAINER" ;;
            worker) printf '%s\n' "$WORKER_CONTAINER" ;;
            scheduler) printf '%s\n' "$SCHEDULER_CONTAINER" ;;
            *) echo 'unexpected service' >> "$TRACE"; exit 97 ;;
        esac
    elif [[ $# == 5 && $1 == container && $2 == inspect && $3 == --format &&
        $4 == '{{.Image}} {{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' ]]; then
        printf 'container inspect %s\n' "$5" >> "$TRACE"
        observed_image=$(image_id "$(cat "$RUNTIME_IMAGE")")
        health=healthy
        state=running
        case "$5" in
            "$WEB_CONTAINER")
                if [[ $RUNTIME_CASE == paused ]]; then state=paused; fi
                if [[ $RUNTIME_CASE == changed-attempt ]]; then
                    (
                        cp "$RUNTIME_NEXT_ATTEMPT" .release.fixture-next
                        mv .release.fixture-next release.json
                    )
                fi
                ;;
            "$WORKER_CONTAINER")
                if [[ $RUNTIME_CASE == wrong-image ]]; then observed_image="sha256:$(printf '%064d' 999)"; fi
                if [[ $RUNTIME_CASE == no-health ]]; then health=none; fi
                ;;
            "$SCHEDULER_CONTAINER")
                if [[ $RUNTIME_CASE == unhealthy ]]; then health=unhealthy; fi
                ;;
            *) echo 'unexpected container' >> "$TRACE"; exit 97 ;;
        esac
        printf '%s %s %s\n' "$observed_image" "$state" "$health"
    else
        printf 'unexpected Docker command %s\n' "$*" >> "$TRACE"
        exit 97
    fi
    exit
fi

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
        rm -f -- "$RUNTIME_IMAGE"
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
        printf '%s\n' "$FORMAKU_IMAGE" > "$RUNTIME_IMAGE"
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
cat > "$scratch/bin/timeout" <<'MOCK'
#!/usr/bin/env bash
set -euo pipefail
if [[ $# -lt 4 || "$1 $2 $3" != '--kill-after=1s 5s docker' ]]; then
    printf 'unexpected timeout command %s\n' "$*" >> "$TRACE"
    exit 97
fi
shift 2
BOUNDED_RUNTIME_READ=1 exec "$@"
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
chmod +x "$scratch/bin/docker" "$scratch/bin/openssl" "$scratch/bin/timeout"

make_fixture() {
    fixture="$scratch/$1"
    mkdir -p "$fixture/uploads" "$fixture/backups"
    printf 'ghcr.io/formaku/formaku\n' > "$fixture/allowed-image"
    printf 'DB_PASSWORD=synthetic-test-value\n' > "$fixture/.env.docker"
    printf 'synthetic-backup-key\n' > "$fixture/backup.key"
    printf 'private synthetic upload\n' > "$fixture/uploads/sample.txt"
    active_before=''
    runtime_image="$fixture/runtime-image-fixture"
    touch "$fixture/deploy.lock"
    if [[ ${2:-previous} == previous ]]; then
        active_before=$previous
        printf '%s\n' "$previous" > "$fixture/active-image"
        printf '%s\n' "$previous" > "$runtime_image"
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
        RUNTIME_IMAGE="$runtime_image" \
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

runtime_snapshot() {
    python3 - "$fixture" "$1" <<'PYTHON'
import hashlib
import pathlib
import sys

fixture = pathlib.Path(sys.argv[1])
names = [
    "active-image", "previous-image", "release.json", "deploy.lock", "allowed-image",
    ".env.docker", "backup.key", "runtime-image-fixture",
]
if sys.argv[2]:
    names.remove("release.json")
paths = [fixture / name for name in names]
paths += sorted(fixture.joinpath("backups").iterdir())
for path in paths:
    if path.exists():
        info = path.stat()
        print(path.name, info.st_ino, info.st_mode, info.st_mtime_ns, hashlib.sha256(path.read_bytes()).hexdigest())
    else:
        print(path.name, "missing")
PYTHON
}

assert_runtime() {
    local expected_state=$1 expected_image=${2:-} next_attempt=${3:-} before actual
    before=$(runtime_snapshot "$next_attempt")
    : > "$fixture/trace"
    actual=$(RUNTIME_READ=1 RUNTIME_CASE="${runtime_case:-healthy}" RUNTIME_NEXT_ATTEMPT="$next_attempt" \
        FORMAKU_IMAGE="$candidate" invoke runtime 2>"$fixture/runtime-stderr")
    python3 - "$actual" "$expected_state" "$expected_image" <<'PYTHON'
import json
import sys

actual, state, image = sys.argv[1:]
expected = {"state": state}
if image:
    expected["image"] = image
assert json.loads(actual) == expected, (expected, actual)
PYTHON
    [[ ! -s "$fixture/runtime-stderr" ]]
    if grep -q '^unexpected ' "$fixture/trace"; then
        cat "$fixture/trace" >&2
        exit 1
    fi
    [[ $(runtime_snapshot "$next_attempt") == "$before" ]]
    if [[ -n "$next_attempt" ]]; then
        cmp "$next_attempt" "$fixture/release.json"
        [[ ! -e "$fixture/.release.fixture-next" ]]
    fi
}

assert_runtime_trace() {
    local image=$1
    assert_trace "image inspect $image" \
        "compose ps web $image" "container inspect $WEB_CONTAINER" \
        "compose ps worker $image" "container inspect $WORKER_CONTAINER" \
        "compose ps scheduler $image" "container inspect $SCHEDULER_CONTAINER"
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

assert_runtime verified "$candidate"
assert_runtime_trace "$candidate"
printf 'PASS: runtime confirms the active digest after checking three healthy containers\n'

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
    assert_runtime transitioning
    [[ ! -s "$fixture/trace" ]]
)
printf 'PASS: scoped status stays readable and runtime reports transitioning while the deployment lock is held\n'

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
    if [[ "$scenario" == health ]]; then
        assert_runtime verified "$previous"
        assert_runtime_trace "$previous"
        assert_status failed restored "$release"
        printf 'PASS: runtime confirms the restored image while scoped status still reports the failed candidate\n'
    fi
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

make_fixture runtime-mismatch
scenario=runtime-mismatch
for runtime_case in wrong-image unhealthy paused; do
    assert_runtime mismatch
    printf 'PASS: runtime reports mismatch for %s\n' "$runtime_case"
done
for runtime_case in inspect-error no-health; do
    assert_runtime unavailable
    printf 'PASS: runtime reports unavailable for %s\n' "$runtime_case"
done
unset runtime_case

make_fixture runtime-changed-attempt
scenario=runtime-changed-attempt
printf '{"state":"failed","release":"%s","image":"%s","rollback":"restored"}\n' \
    "$release" "$candidate" > "$fixture/next-release.json"
runtime_case=changed-attempt assert_runtime transitioning '' "$fixture/next-release.json"
assert_runtime_trace "$previous"
printf 'PASS: runtime reports transitioning when another writer replaces the attempt during inspection\n'

make_fixture runtime-missing-marker none
scenario=runtime-missing-marker
assert_runtime unavailable
[[ ! -s "$fixture/trace" ]]
assert_rejected invoke runtime "$release"
[[ ! -s "$fixture/trace" ]]
printf 'PASS: runtime without an active marker performs no Docker reads and rejects arguments\n'

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

dispatch runtime
printf '%s\n' -n /usr/local/sbin/formaku-deployctl runtime | cmp - "$sudo_trace"
dispatch "status $release"
printf '%s\n' -n /usr/local/sbin/formaku-deployctl status "$release" | cmp - "$sudo_trace"
dispatch "deploy $candidate $release"
printf '%s\n' /usr/bin/systemd-run --quiet --collect --wait "--unit=formaku-$release" \
    /usr/local/sbin/formaku-deployctl deploy "$candidate" "$release" | cmp - "$sudo_trace"
: > "$sudo_trace"
for request in '' "runtime $release" 'runtime ' $'runtime\n' $'runtime\r' \
    'status' 'status invalid' "status $release extra" "status $candidate $release" \
    "deploy $candidate" "deploy $candidate $release extra" "unknown $release" \
    "status $release"$'\n' "status $release"$'\r' "deploy $candidate $release"$'\n'"status $release"; do
    assert_rejected dispatch "$request"
done
[[ ! -s "$sudo_trace" ]]
printf 'PASS: SSH dispatcher forwards only allowed runtime, deploy and scoped status commands\n'
