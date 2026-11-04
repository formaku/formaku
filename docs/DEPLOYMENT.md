# Deployment

The checked-in workflows are templates until the GitHub organization, repositories, tokens, environments and target VPS exist.

Build: configure GITHUB_REPOSITORY and a fine-grained GITHUB_TOKEN restricted to that repository with Actions write permission. The browser dispatches release.yml from main, with an exact source SHA. The workflow verifies ancestry, builds and publishes to GHCR. See the run summary for the immutable digest.

VPS: dedicated Linux host with Docker Compose and a configured HTTPS reverse proxy to 127.0.0.1:8080. Run deploy/bootstrap.sh from the reviewed checkout as root. Place runtime settings in /opt/formaku/.env.docker (mode 600); keep APP_KEY stable. Write the approved ghcr.io/owner/repository to /opt/formaku/allowed-image. Configure pull-only registry access and a restricted deployment account. The deploy helper runs only the approved image repository, serializes operations and backs up DB/uploads/runtime settings with AES-256 encryption before migration.

Configure production environment secrets DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS; variables DEPLOY_HOST and DEPLOY_USER. Pin the host key from a separately verified source. Run deploy.yml with the immutable image reference and UUID. No target VPS was selected by the user yet, so no real server has been bootstrapped.

Automatic deployment supports backwards-compatible migrations only. On failure, the previous image is restarted; incompatible data must be recovered from the encrypted backup as an explicit recovery operation. Store backup.key separately from the host as well. Test recovery before using real data. The helper has not yet been accepted on a target VPS.

Compose must read .env.docker for interpolation: use docker compose --env-file .env.docker. The host helper exports DB_PASSWORD from the runtime file through Compose's env-file option (see script).
