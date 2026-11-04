#!/usr/bin/env bash
set -euo pipefail
[[ $EUID == 0 ]] || { echo 'Run as root on a dedicated VPS'; exit 1; }
command -v docker >/dev/null
docker compose version >/dev/null
install -d -m 700 /opt/formaku/backups
install -m 750 deploy/deployctl /usr/local/sbin/formaku-deployctl
install -m 644 compose.yaml /opt/formaku/compose.yaml
if [[ ! -f /opt/formaku/backup.key ]]; then openssl rand -base64 48 > /opt/formaku/backup.key; fi
chmod 600 /opt/formaku/backup.key
printf 'Install .env.docker (mode 600), allowed-image, HTTPS reverse proxy and SSH forced-command integration. No application secrets were generated or overwritten.\n'
