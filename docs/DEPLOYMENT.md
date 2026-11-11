# Deployment

## Release the source and container

1. Add a dated entry for the version to `CHANGELOG.md` and push the reviewed source to `main`. Keep the project website and its documentation build in the separate Forgejo repository.
2. Wait for the successful `CI` push run on `main` for that exact commit. This includes the database matrix, frontend build, browser tests, and Docker Compose startup.
3. Create an annotated alpha tag for the tested commit and push that tag on its own using your GitHub credentials. The workflow accepts versions such as `v0.1.0-alpha.21`. The tagged commit must contain the release workflow. If the workflow reports that CI has not completed, rerun it once CI passes.
4. The workflow builds the SHA-tagged image, verifies the exact manifest by digest using anonymous GHCR access, and creates a GitHub prerelease with the changelog entry, digest and build links. Check the public release and pull the listed digest using a client without registry credentials.
5. Update the project website in English and Polish with the verified release link, digest, and upgrade instructions. Verify the public documentation after deployment.

For a standalone image build, run **Build Formaku image** on `main` using the full SHA as `source_commit` and a fresh UUID as `release_id`. The run publishes the SHA-tagged image and records the digest in its summary. [Manual workflow dispatch](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow)

To dispatch the image build from the Formaku panel, configure `GITHUB_REPOSITORY` and a fine-grained `GITHUB_TOKEN` restricted to that repository with Actions write permission. In **Releases**, dispatch a build using the full source SHA, then choose **Refresh status** in its row to read the matching GitHub Actions run. A run can take a moment to appear. The first refresh searches up to 300 recent manual runs of the configured workflow, then later refreshes use the matched run ID. Open the run for its image digest after the build succeeds. Deploying that digest is a separate operation.

## Run the alpha locally with Docker Compose

These instructions apply to `v0.1.0-alpha.22`. For an earlier release, see [its release notes](https://github.com/formaku/formaku/releases). The [first alpha instructions](#run-the-first-alpha-with-docker-compose) include its Compose override.

```sh
git clone --branch v0.1.0-alpha.22 --single-branch https://github.com/formaku/formaku.git
cd formaku
cp .env.docker.example .env.docker
```

Set a persistent `APP_KEY` and a strong `DB_PASSWORD` in `.env.docker`. Generate the key with `openssl rand -base64 32` and prefix its output with `base64:`. Keep the file local.

If the [alpha.22 release notes](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.22) list a verified Linux AMD64 image, copy the full `ghcr.io/formaku/formaku@sha256:…` reference. Export it as `FORMAKU_IMAGE` and pull it. Use the same shell for the Compose commands so they use that image.

```sh
docker pull "$FORMAKU_IMAGE"
```

To build from the tagged source, use the default local image.

```sh
unset FORMAKU_IMAGE
docker compose --env-file .env.docker build web
```

With either image, initialize the database and start the stack.

```sh
docker compose --env-file .env.docker up -d --wait database
docker compose --env-file .env.docker run --rm web php artisan migrate --force
docker compose --env-file .env.docker up -d --no-build --wait
docker compose --env-file .env.docker exec web php artisan formaku:admin you@example.com
```

Use your own email for the administrator and open `http://127.0.0.1:8080/formaku`. Use the language selector in the panel header to switch between Polish and English.

### Upgrade an existing alpha installation

Back up the database and uploads and verify you can restore them. Keep the existing `.env.docker` and `APP_KEY`. Update the checkout to `v0.1.0-alpha.22`, clear any `COMPOSE_FILE` setting for the first alpha's health-check override, and pull or build the alpha.22 image as described above. Stop the web, worker and scheduler before applying pending migrations with the new image.

```sh
docker compose --env-file .env.docker stop web worker scheduler
docker compose --env-file .env.docker run --rm web php artisan migrate --force
docker compose --env-file .env.docker up -d --no-build --wait
```

Alpha.22 adds no migrations after alpha.21. Installations already on alpha.15 or later can use their existing schema. When upgrading from alpha.14 or earlier, apply the alpha.15 migration that creates the page templates table. After upgrading, verify that a saved draft can be used as a template for a new draft. If you are upgrading from alpha.12 or earlier, the alpha.13 site navigation migration still applies. Existing sites retain their default header and start with an empty footer. Upgrades from alpha.10 or earlier still require the alpha.11 media usage migration. It indexes existing references from current drafts and published revisions. Allow it to finish before starting the updated services and check **Media → Użycie pliku** for a file referenced by an existing record. Existing layouts without explicit column or stacking settings retain their automatic grid. Upgrades from before alpha.4 still apply its redirect table migration. Existing published addresses stay active, and Formaku records URL changes when pages are published after the upgrade. Upgrades from the first alpha also apply the assistant migration introduced in alpha.2. After the upgrade, verify that published pages load and that `APP_URL` is the public base URL for the sitemap.

If you revert to alpha.10 and it accepts new writes while the alpha.11 migration remains applied, rebuild the usage index before re-upgrading. With writes stopped, use the alpha.11 image to roll back just its media usage migration, then apply it again. The rollback drops the derived index and the migration repopulates it from the current revisions.

### Generate variants for existing public images

The Docker image includes GD with WebP support. Public JPEG, PNG and WebP uploads can produce 480 px and 960 px variants when the original is larger. To process existing uploads after starting the updated stack, run:

```sh
docker compose --env-file .env.docker exec web php artisan formaku:media-variants
```

Check an existing published page that uses a public image. Its image block retains the original URL and includes the variants that were generated. On a custom Laravel host, enable GD with WebP support and the EXIF extension for JPEG handling. Files that cannot be resized without changing their appearance continue to use the original.

## Run the first alpha with Docker Compose

The [v0.1.0-alpha source](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha) includes the Compose configuration. After preparing it, use the public Linux AMD64 image from GHCR or build the tagged source locally.

```sh
git clone --branch v0.1.0-alpha --single-branch https://github.com/formaku/formaku.git
cd formaku
cp .env.docker.example .env.docker
```

Set `APP_KEY` in `.env.docker` to `base64:` followed by the output of `openssl rand -base64 32`. Set `DB_PASSWORD` to a strong password, for example the output of `openssl rand -hex 24`. Keep the file local and keep the application key stable across restarts.

The tagged alpha inherits a web-server health check for the queue and scheduler. Add this Compose override in the alpha checkout so that `--wait` checks each process using its own health check.

```sh
cat > compose.alpha.yaml <<'YAML'
services:
  worker:
    healthcheck:
      test: ["CMD", "grep", "-azqxF", "queue:work", "/proc/1/cmdline"]
      interval: 10s
  scheduler:
    healthcheck:
      test: ["CMD", "grep", "-azqxF", "schedule:work", "/proc/1/cmdline"]
      interval: 10s
YAML
export COMPOSE_FILE=compose.yaml:compose.alpha.yaml
```

On Linux AMD64, pull the [public alpha image](https://github.com/formaku/formaku/pkgs/container/formaku) by its immutable digest. GHCR login is optional for this image.

```sh
export FORMAKU_IMAGE=ghcr.io/formaku/formaku@sha256:18f0d3da2fe702e6d7161c12a28ebfdcfce1c2e7859646a0820eace5f1ffbc72
docker pull "$FORMAKU_IMAGE"
```

To build from the tagged source instead, use the default local image.

```sh
unset FORMAKU_IMAGE
docker compose --env-file .env.docker build web
```

With either image, start the database, apply migrations, and start the full stack.

```sh
docker compose --env-file .env.docker up -d --wait database
docker compose --env-file .env.docker run --rm web php artisan migrate --force
docker compose --env-file .env.docker up -d --no-build --wait
docker compose --env-file .env.docker exec web php artisan formaku:admin you@example.com
```

Use your own email for the administrator and open `http://127.0.0.1:8080/formaku`. Use the language selector in the panel header to switch between Polish and English. The local stack includes PostgreSQL, a queue worker and a scheduler.

## Deploy an existing image

Use a dedicated Linux host with Docker Compose and an HTTPS reverse proxy to 127.0.0.1:8080. Run deploy/bootstrap.sh from the reviewed checkout as root. Place runtime settings in /opt/formaku/.env.docker (mode 600) and keep APP_KEY stable. Write the approved ghcr.io/owner/repository to /opt/formaku/allowed-image. Use a restricted deployment account and configure pull-only registry access if you deploy a private image. The deploy helper runs only the approved image repository, serializes operations and backs up DB/uploads/runtime settings with AES-256 encryption before migration.

Configure production environment secrets DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS; variables DEPLOY_HOST and DEPLOY_USER. Pin the host key from a separately verified source. Run deploy.yml with the immutable image reference and UUID. No target VPS was selected by the user yet, so no real server has been bootstrapped.

Automatic deployment supports backwards-compatible migrations only. On failure, the previous image is restarted; incompatible data must be recovered from the encrypted backup as an explicit recovery operation. Store backup.key separately from the host as well. Test recovery before using real data. The helper has not yet been accepted on a target VPS.

Compose must read .env.docker for interpolation: use docker compose --env-file .env.docker. The host helper exports DB_PASSWORD from the runtime file through Compose's env-file option (see script).
