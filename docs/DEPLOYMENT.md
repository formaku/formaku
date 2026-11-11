# Deployment

## Release the source and container

1. Choose the full commit SHA and push the reviewed CMS changes to `main` in the GitHub repository. Keep the project website and its documentation build in the separate Forgejo repository.
2. In GitHub Actions, check the `CI` run for that SHA. Wait for the PHP database matrix, frontend build, browser tests and Docker Compose startup check to pass.
3. Run **Build Formaku image** on `main`. Enter the full SHA as `source_commit` and a fresh UUID as `release_id`. The workflow checks that the SHA is an ancestor of `origin/main` and publishes to GHCR under the SHA tag. Save the workflow URL and the digest reference from the run summary. [Manual workflow dispatch](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow)
4. For a public image, verify the GHCR package visibility and pull the digest reference from a Docker client without registry credentials. Use the verified `ghcr.io/<owner>/<repo>@sha256:<digest>` reference in the release notes. [GitHub Container registry](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry)
5. Publish a GitHub release with a version tag pinned to the same SHA. For `v0.1.0-alpha`, select **This is a pre-release**. Include the release scope, any migration requirements and the CI link. When an image is available publicly, include the build link and verified image digest. Verify the tag and release notes from an anonymous session, then update the website documentation to point to the published release. [GitHub releases](https://docs.github.com/en/repositories/releasing-projects-on-github/managing-releases-in-a-repository)

To dispatch the image build from the Formaku panel, configure `GITHUB_REPOSITORY` and a fine-grained `GITHUB_TOKEN` restricted to that repository with Actions write permission. The panel links to the Actions list, where you can find the run by its release ID.

## Run the alpha locally with Docker Compose

These instructions apply to `v0.1.0-alpha.5`. For an earlier release, see [its release notes](https://github.com/formaku/formaku/releases). The [first alpha instructions](#run-the-first-alpha-with-docker-compose) include its Compose override.

```sh
git clone --branch v0.1.0-alpha.5 --single-branch https://github.com/formaku/formaku.git
cd formaku
cp .env.docker.example .env.docker
```

Set a persistent `APP_KEY` and a strong `DB_PASSWORD` in `.env.docker`. Generate the key with `openssl rand -base64 32` and prefix its output with `base64:`. Keep the file local.

On Linux AMD64, copy the full `ghcr.io/formaku/formaku@sha256:…` reference from the [alpha.5 release notes](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.5). Export it as `FORMAKU_IMAGE` and pull it. Use the same shell for the Compose commands so they use that image.

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

Use your own email for the administrator and open `http://127.0.0.1:8080/formaku`.

### Upgrade an existing alpha installation

Back up the database and uploads and verify you can restore them. Keep the existing `.env.docker` and `APP_KEY`. Update the checkout to `v0.1.0-alpha.5`, clear any `COMPOSE_FILE` setting for the first alpha's health-check override, and pull or build the alpha.5 image as described above. Stop the web, worker and scheduler before applying pending migrations with the new image.

```sh
docker compose --env-file .env.docker stop web worker scheduler
docker compose --env-file .env.docker run --rm web php artisan migrate --force
docker compose --env-file .env.docker up -d --no-build --wait
```

Alpha.5 adds no database migration. Upgrades from before alpha.4 still apply its redirect table migration. Existing published addresses stay active, and Formaku records URL changes when pages are published after the upgrade. Upgrades from the first alpha also apply the assistant migration introduced in alpha.2. After the upgrade, verify that published pages load and that `APP_URL` is the public base URL for the sitemap.

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

Use your own email for the administrator and open `http://127.0.0.1:8080/formaku`. The local stack includes PostgreSQL, a queue worker and a scheduler.

## Deploy an existing image

Use a dedicated Linux host with Docker Compose and an HTTPS reverse proxy to 127.0.0.1:8080. Run deploy/bootstrap.sh from the reviewed checkout as root. Place runtime settings in /opt/formaku/.env.docker (mode 600) and keep APP_KEY stable. Write the approved ghcr.io/owner/repository to /opt/formaku/allowed-image. Use a restricted deployment account and configure pull-only registry access if you deploy a private image. The deploy helper runs only the approved image repository, serializes operations and backs up DB/uploads/runtime settings with AES-256 encryption before migration.

Configure production environment secrets DEPLOY_SSH_KEY, DEPLOY_KNOWN_HOSTS; variables DEPLOY_HOST and DEPLOY_USER. Pin the host key from a separately verified source. Run deploy.yml with the immutable image reference and UUID. No target VPS was selected by the user yet, so no real server has been bootstrapped.

Automatic deployment supports backwards-compatible migrations only. On failure, the previous image is restarted; incompatible data must be recovered from the encrypted backup as an explicit recovery operation. Store backup.key separately from the host as well. Test recovery before using real data. The helper has not yet been accepted on a target VPS.

Compose must read .env.docker for interpolation: use docker compose --env-file .env.docker. The host helper exports DB_PASSWORD from the runtime file through Compose's env-file option (see script).
