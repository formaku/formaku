#!/usr/bin/env bash
set -euo pipefail

check_missing_chunk=false
if [[ ${1:-} == --check-missing-chunk ]]; then
    check_missing_chunk=true
    shift
fi

if [[ $# -ne 1 || "$1" != /*/formaku-cms-v*.zip ]]; then
    echo "Usage: $0 [--check-missing-chunk] /absolute/path/formaku-cms-v0.1.0-alpha.51.zip" >&2
    exit 1
fi

umask 077
scratch=$(mktemp -d "${TMPDIR:-/tmp}/formaku-composer-smoke.XXXXXX")
trap 'rm -rf -- "$scratch"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

version=$(python3 - "$1" "$scratch" <<'PY'
from hashlib import sha1, sha256
import json
from pathlib import Path
import re
import shutil
import sys
from zipfile import ZipFile

source, scratch = map(Path, sys.argv[1:])
match = re.fullmatch(r"formaku-cms-v([0-9]+\.[0-9]+\.[0-9]+-alpha(?:\.[0-9]+)?)\.zip", source.name)
if match is None:
    sys.exit("Expected an alpha release ZIP named formaku-cms-v<VERSION>.zip.")
version = match[1]
archive = scratch / source.name
shutil.copyfile(source, archive)
checksum = source.with_suffix(".zip.sha256").read_text(encoding="ascii").split()
digest = archive.read_bytes()
if checksum != [sha256(digest).hexdigest(), archive.name]:
    sys.exit("SHA-256 checksum or archive name does not match the release ZIP.")

with ZipFile(archive) as package:
    manifest = json.loads(package.read("composer.json"))
    if manifest.get("name") != "formaku/cms" or manifest.get("version") != version:
        sys.exit("The package manifest must identify formaku/cms at the version in the ZIP filename.")
    for name in ("app.js", "main.css"):
        asset = package.read(f"public/{name}")
        if not asset:
            sys.exit(f"The release ZIP contains an empty panel asset: {name}")
        (scratch / name).write_bytes(asset)

manifest.pop("source", None)
manifest["dist"] = {"type": "zip", "url": archive.resolve().as_uri(), "shasum": sha1(digest).hexdigest()}
(scratch / "repository.json").write_text(json.dumps({"type": "package", "package": manifest}))
print(version)
PY
)
echo "Verified the release ZIP checksum and manifest for formaku/cms $version."

# Isolate Artisan and Composer from host environment variables and credential files.
env -i PATH="$PATH" TMPDIR="$scratch" \
    GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null GIT_TERMINAL_PROMPT=0 \
    COMPOSER_HOME="$scratch/composer-home" COMPOSER_CACHE_DIR="$scratch/composer-cache" \
    APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_DATABASE="$scratch/host.sqlite" \
    SESSION_DRIVER=array CACHE_STORE=array QUEUE_CONNECTION=sync FORMAKU_PUBLIC_ROUTES=false \
    bash -s -- "$scratch" "$version" "$check_missing_chunk" <<'SMOKE'
set -euo pipefail
scratch=$1
version=$2
check_missing_chunk=$3
cd "$scratch"

php -r 'if (PHP_VERSION_ID < 80400 || ! extension_loaded("pdo_sqlite")) {
    fwrite(STDERR, "PHP 8.4+ with pdo_sqlite is required.\n");
    exit(1);
}'
composer create-project laravel/laravel "$scratch/host" '^13.0' \
    --prefer-dist --no-dev --no-scripts --no-interaction --no-progress
cd "$scratch/host"

cp .env.example .env
touch "$DB_DATABASE"
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction

cat >> routes/web.php <<'PHP'

Route::get('/formaku-smoke-host', static fn () => 'Synthetic host route');
Route::get('/formaku-smoke-sign-in', static fn () => 'Synthetic host sign-in')->name('login');
PHP

cat > smoke-host.php <<'PHP'
<?php

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$email = 'formaku-smoke@example.test';

function requireCheck(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, $message.PHP_EOL);
        exit(1);
    }
}

$host = $kernel->handle(Request::create('/formaku-smoke-host'));
requireCheck($host->getStatusCode() === 200 && $host->getContent() === 'Synthetic host route', 'The host route did not return its expected response.');

if ($argv[1] === 'seed') {
    User::create([
        'name' => 'Synthetic host record',
        'email' => $email,
        'password' => Hash::make(bin2hex(random_bytes(16))),
    ]);
}

$user = User::where('email', $email)->sole();
echo json_encode($user->getRawOriginal(), JSON_THROW_ON_ERROR).PHP_EOL;

if ($argv[1] === 'verify') {
    $guest = $kernel->handle(Request::create('/formaku'));
    requireCheck($guest->getStatusCode() === 302 && str_ends_with($guest->headers->get('Location', ''), '/formaku-smoke-sign-in'), 'The panel did not redirect a guest to the host sign-in route.');

    Auth::guard('web')->setUser($user);
    $panel = $kernel->handle(Request::create('/formaku'));
    requireCheck($panel->getStatusCode() === 200, 'The authorized host user could not open the Formaku panel.');
    requireCheck(str_contains($panel->getContent(), '/vendor/formaku/app.js') && str_contains($panel->getContent(), '/vendor/formaku/main.css'), 'The panel does not reference its published assets.');

    $bootstrap = $kernel->handle(Request::create('/formaku/api/v1/bootstrap', server: ['HTTP_ACCEPT' => 'application/json']));
    requireCheck($bootstrap->getStatusCode() === 200, 'The authorized host user could not load the Formaku API.');
    $payload = json_decode($bootstrap->getContent(), true, flags: JSON_THROW_ON_ERROR);
    requireCheck(($payload['user']['name'] ?? null) === $user->name && ($payload['abilities']['work'] ?? null) === true, 'The API did not return the expected host user and permissions.');
}
PHP
php smoke-host.php seed > "$scratch/host-record-before.json"

composer config repositories.formaku-release "$(cat "$scratch/repository.json")"
composer require "formaku/cms:$version" --prefer-dist --update-no-dev --no-interaction --no-progress
python3 - "$scratch/repository.json" <<'PY'
import json
from pathlib import Path
import sys

expected = json.loads(Path(sys.argv[1]).read_text())["package"]
packages = json.loads(Path("composer.lock").read_text())["packages"]
package = next((package for package in packages if package["name"] == expected["name"]), {})
if package.get("version") != expected["version"] or package.get("dist") != expected["dist"]:
    sys.exit("Composer did not lock the supplied release ZIP as the distribution.")
PY
php artisan formaku:install --no-interaction
test -s config/formaku.php

mkdir -p app/Support
cat > app/Support/FormakuSmokeAccess.php <<'PHP'
<?php

namespace App\Support;

use App\Models\User;

final class FormakuSmokeAccess
{
    public static function allows(User $user, string $ability): bool
    {
        return $user->email === 'formaku-smoke@example.test' && $ability === 'work';
    }
}
PHP
cat > config/formaku.php <<'PHP'
<?php

return [
    'guard' => 'web',
    'authorize' => [App\Support\FormakuSmokeAccess::class, 'allows'],
    'public_routes' => false,
];
PHP

php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan formaku:doctor --no-interaction
if [[ "$check_missing_chunk" == true ]]; then
    chunks=(vendor/formaku/cms/public/assets/editor-*.js)
    bundled_chunk=${chunks[0]}
    test -s "$bundled_chunk"
    relative_chunk=${bundled_chunk#vendor/formaku/cms/public/}
    published_chunk="public/vendor/formaku/$relative_chunk"
    cmp "$bundled_chunk" "$published_chunk"
    mv "$published_chunk" "$scratch/editor-chunk"

    doctor_status=0
    php artisan formaku:doctor --no-ansi --no-interaction > "$scratch/doctor-missing-chunk.log" 2>&1 || doctor_status=$?
    if [[ "$doctor_status" != 1 ]] ||
        ! grep -Fxq "Published $relative_chunk is missing or unreadable. Run php artisan formaku:install." "$scratch/doctor-missing-chunk.log"; then
        cat "$scratch/doctor-missing-chunk.log" >&2
        echo "Expected Formaku doctor to report the missing editor chunk and exit with code 1." >&2
        exit 1
    fi
    test ! -e "$published_chunk"
    php artisan formaku:install --no-interaction
    cmp "$scratch/editor-chunk" "$published_chunk"
    php artisan formaku:doctor --no-interaction
    echo "Verified missing editor chunk detection and recovery."
fi

php smoke-host.php verify > "$scratch/host-record-after.json"
cmp "$scratch/host-record-before.json" "$scratch/host-record-after.json"

for name in app.js main.css; do
    cmp "$scratch/$name" "vendor/formaku/cms/public/$name"
    cmp "$scratch/$name" "public/vendor/formaku/$name"
done
echo "Verified Formaku installation, preserved host route and record, panel/API access, and published ZIP assets."
SMOKE
