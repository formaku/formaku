#!/usr/bin/env bash
set -euo pipefail

require_panel_catalog=0
if [[ ${1:-} == --require-panel-catalog ]]; then
    require_panel_catalog=1
    shift
fi

if [[ $# -ne 2 ]]; then
    echo "Usage: $0 [--require-panel-catalog] <Commerce 0.2 checkout> <Platform 0.2 checkout>" >&2
    exit 2
fi

commerce=$(cd "$1" && pwd -P)
platform=$(cd "$2" && pwd -P)
root=$(cd "$(dirname "$0")/.." && pwd -P)

if [[ ! -f "$commerce/src/CommerceServiceProvider.php" || ! -f "$platform/bin/formaku-platform" || ! -f "$root/apps/starter/vendor/autoload.php" ]]; then
    echo 'Provide the Commerce and Platform checkouts and install the starter Composer dependencies.' >&2
    exit 2
fi

if [[ "$require_panel_catalog" == 1 && ! -f "$commerce/src/Bridge/Formaku/CatalogResource.php" ]]; then
    echo 'The supplied Commerce checkout does not contain the panel catalog.' >&2
    exit 2
fi

pg_bin=${FORMAKU_TEST_PG_BIN:-}
if [[ -z "$pg_bin" ]]; then
    pg_bin=$(pg_config --bindir)
fi
for command in initdb pg_ctl psql; do
    if [[ ! -x "$pg_bin/$command" ]]; then
        echo "Set FORMAKU_TEST_PG_BIN to the PostgreSQL server binaries directory (missing $command)." >&2
        exit 2
    fi
done

umask 077
scratch=$(mktemp -d /tmp/formaku-merchant-test.XXXXXX)
cleanup() {
    if [[ -f "$scratch/data/postmaster.pid" ]]; then
        if ! "$pg_bin/pg_ctl" -D "$scratch/data" -m fast -w stop >/dev/null; then
            echo "PostgreSQL shutdown failed. Temporary files are at $scratch." >&2
            return 1
        fi
    fi
    rm -rf -- "$scratch"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

port=$(php -r '
    $socket = stream_socket_server("tcp://127.0.0.1:0");
    if ($socket === false) {
        exit(1);
    }
    echo explode(":", stream_socket_get_name($socket, false))[1];
')

"$pg_bin/initdb" -D "$scratch/data" -U formaku_acceptance --auth-local=trust --auth-host=scram-sha-256 --no-locale >/dev/null
if ! "$pg_bin/pg_ctl" -D "$scratch/data" -l "$scratch/server.log" -o "-h 127.0.0.1 -p $port -k $scratch" -w start; then
    cat "$scratch/server.log" >&2
    exit 1
fi

for suffix in 01 02; do
    merchant=formaku_000000000000400080000000000000$suffix
    env -i PATH="$PATH" "$pg_bin/psql" -X -h "$scratch" -p "$port" -U formaku_acceptance -d postgres -v ON_ERROR_STOP=1 \
        -c "CREATE ROLE $merchant LOGIN PASSWORD 'testing-only';" \
        -c "CREATE DATABASE $merchant OWNER $merchant;" \
        -c "REVOKE CONNECT ON DATABASE $merchant FROM PUBLIC;"
done

bash "$platform/tests/runtime.sh"

cd "$root/apps/starter"
env -i PATH="$PATH" TMPDIR="$scratch" APP_ENV=testing APP_CONFIG_CACHE="$scratch/config.php" \
    FORMAKU_REQUIRE_PANEL_CATALOG="$require_panel_catalog" FORMAKU_MERCHANT_TEST_PG_PORT="$port" FORMAKU_COMMERCE_TEST_PATH="$commerce" FORMAKU_PLATFORM_TEST_PATH="$platform" \
    php vendor/bin/phpunit --filter=MerchantIsolationTest --display-warnings --fail-on-warning
