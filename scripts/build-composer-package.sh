#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 || ! "$1" =~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-alpha(\.(0|[1-9][0-9]*))?$ ]]; then
    echo "Usage: $0 v0.1.0-alpha.23 OUTPUT_DIR" >&2
    exit 1
fi

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)

python3 - "$root" "$1" "$2" <<'PY'
from hashlib import sha256
from io import BytesIO
import json
from pathlib import Path
import re
import subprocess
import sys
import tarfile
from tempfile import TemporaryDirectory
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


root, tag, output = Path(sys.argv[1]), sys.argv[2], Path(sys.argv[3])
source = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
files = {}

with tarfile.open(fileobj=BytesIO(subprocess.check_output(
    ["git", "archive", f"{source}:packages/cms"], cwd=root
))) as tracked:
    for entry in tracked:
        if entry.isdir():
            continue
        if not entry.isfile():
            sys.exit(f"Unsupported tracked file type: {entry.name}")
        files[entry.name] = tracked.extractfile(entry).read()

manifest = json.loads(files["composer.json"])
manifest["version"] = tag[1:]
files["composer.json"] = (json.dumps(manifest, indent=2) + "\n").encode()
files["LICENSE"] = subprocess.check_output(["git", "show", f"{source}:LICENSE"], cwd=root)

public = root / "packages/cms/public"
if public.is_symlink() or (public / "assets").is_symlink():
    sys.exit("The generated panel asset directories must not be symlinks.")

def read_asset(name):
    path = public / name
    if path.is_symlink() or not path.is_file() or path.stat().st_size == 0:
        sys.exit(f"Missing Formaku panel asset {name}. Run npm ci and npm run build first.")
    return path.read_bytes()

assets = {name: read_asset(name) for name in ("app.js", "main.css", "favicon.svg")}
# Include the emitted editor chunks and fonts that the panel references.
editors = re.findall(rb"assets/editor-[A-Za-z0-9_-]+\.js", assets["app.js"])
fonts = re.findall(rb"(?:inter|jetbrains-mono)-[a-z0-9-]+\.woff2?", assets["main.css"])
if not editors or not fonts:
    sys.exit("The Formaku panel build is incomplete. Run npm ci and npm run build first.")
for name in set(editors + fonts):
    assets[name.decode("ascii")] = read_asset(name.decode("ascii"))

files.update({f"public/{name}": data for name, data in assets.items()})

# Some dev dependencies contribute fonts or CSS directly to the panel output.
asset_packages = {
    "node_modules/@fontsource/inter",
    "node_modules/@fontsource/jetbrains-mono",
    "node_modules/daisyui",
    "node_modules/tailwindcss",
}
lock = json.loads(subprocess.check_output(["git", "show", f"{source}:package-lock.json"], cwd=root))
locked_packages = lock["packages"]
if not asset_packages <= locked_packages.keys():
    sys.exit("The npm lockfile must list all bundled font and CSS packages.")

license_name = re.compile(r"(?:licen[cs]es?|copying|notices?|copyright)(?:[. _-].*)?", re.I)
for location, metadata in sorted(locked_packages.items()):
    if not location or (metadata.get("dev") and location not in asset_packages):
        continue
    package = root / location
    installed_manifest = package / "package.json"
    if not installed_manifest.is_file() and metadata.get("optional") and location not in asset_packages:
        continue
    if not installed_manifest.is_file():
        sys.exit(f"Missing npm package {location}. Run npm ci and npm run build first.")

    installed = json.loads(installed_manifest.read_bytes())
    name, version = installed["name"], installed["version"]
    if (
        version != metadata["version"]
        or not re.fullmatch(r"(?:@[A-Za-z0-9_.-]+/)?[A-Za-z0-9_.-]+", name)
        or any(part in {".", ".."} for part in name.split("/"))
        or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.+-]*", version)
    ):
        sys.exit(f"Unexpected package metadata for {location}. Run npm ci and npm run build first.")

    notices = [
        path for path in package.iterdir()
        if license_name.fullmatch(path.name) and path.is_file() and not path.is_symlink()
    ]
    if not any(re.match(r"(?:licen[cs]e|copying)", path.name, re.I) and path.stat().st_size for path in notices):
        sys.exit(f"Missing license for {name}@{version}.")
    for notice in notices:
        target = f"licenses/npm/{name}/{version}/{notice.name}"
        content = notice.read_bytes()
        if target in files and files[target] != content:
            sys.exit(f"Conflicting npm license files for {name}@{version}: {notice.name}")
        files[target] = content

output.mkdir(parents=True, exist_ok=True)
name = f"formaku-cms-{tag}.zip"
with TemporaryDirectory(dir=output) as temporary:
    archive = Path(temporary) / name
    checksum = archive.with_suffix(".zip.sha256")
    with ZipFile(archive, "w") as package:
        for path, data in sorted(files.items()):
            entry = ZipInfo(path, date_time=(1980, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = 0o100644 << 16
            package.writestr(entry, data, compress_type=ZIP_DEFLATED, compresslevel=9)

    checksum.write_text(f"{sha256(archive.read_bytes()).hexdigest()}  {name}\n", encoding="ascii")
    archive.replace(output / name)
    checksum.replace(output / checksum.name)
PY
