# Deployment

## Release the source and container

1. Add a dated entry for the version to `CHANGELOG.md` and push the reviewed source to `main`. Keep the project website and its documentation build in the separate Forgejo repository.
2. Wait for the successful `CI` push run on `main` for that exact commit. This includes the database matrix, frontend build, Composer package installation, browser tests, and Docker Compose startup.
3. Create an annotated alpha tag for the tested commit and push that tag on its own using your GitHub credentials. The workflow accepts versions such as `v0.1.0-alpha.21`. The tagged commit must contain the release workflow. If the workflow reports that CI has not completed, rerun it once CI passes.
4. The workflow builds the SHA-tagged image and Composer ZIP. It verifies the source, revision and version labels on the public Linux AMD64 image by digest, checks the image manifest and ZIP checksum, installs the release ZIP in an isolated Laravel host, then publishes the prerelease with the ZIP and checksum attached. Check the public release, pull the listed digest without registry credentials, and verify the downloaded ZIP against the checksum.
5. Register the verified ZIP in the project website’s Composer index. Update the English and Polish guides with the verified release link, image digest, and upgrade instructions. Verify the public documentation after deployment.

For a standalone image build, run **Build Formaku image** on `main` using the full SHA as `source_commit` and a fresh UUID as `release_id`. The run publishes the SHA-tagged image and records the digest in its summary. [Manual workflow dispatch](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow)

To dispatch the image build from the Formaku panel, configure `GITHUB_REPOSITORY` and a fine-grained `GITHUB_TOKEN` restricted to that repository with Actions write permission. In **Releases**, dispatch a build using the full source SHA, then choose **Refresh status** in its row to read the matching GitHub Actions run. A run can take a moment to appear. The first refresh searches up to 300 recent manual runs of the configured workflow, then later refreshes use the matched run ID. Open the run for its image digest after the build succeeds. Deploying that digest is a separate operation.

## Run the alpha locally with Docker Compose

These instructions apply to `v0.1.0-alpha.75`. For an earlier release, see [its release notes](https://github.com/formaku/formaku/releases). The [first alpha instructions](#run-the-first-alpha-with-docker-compose) include its Compose override.

```sh
git clone --branch v0.1.0-alpha.75 --single-branch https://github.com/formaku/formaku.git
cd formaku
cp .env.docker.example .env.docker
```

Set a persistent `APP_KEY` and a strong `DB_PASSWORD` in `.env.docker`. Generate the key with `openssl rand -base64 32` and prefix its output with `base64:`. Keep the file local.

If the [alpha.75 release notes](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.75) list a verified Linux AMD64 image, copy the full `ghcr.io/formaku/formaku@sha256:…` reference. Export it as `FORMAKU_IMAGE` and pull it. Use the same shell for the Compose commands so they use that image.

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

### Verify the image source and revision

After pulling the digest listed in the release notes, inspect the image labels.

```sh
docker image inspect "$FORMAKU_IMAGE" --format '{{json .Config.Labels}}'
```

Starting with alpha.39, `org.opencontainers.image.source` gives the Formaku repository URL and `org.opencontainers.image.revision` gives the full source commit. For a tagged release, `org.opencontainers.image.version` gives the release version without the `v` prefix. For a manual build, it gives the requested source commit. The workflow checks these values on the image pulled by its immutable digest.

### Test a published image in GitHub Actions

In GitHub Actions, open **CI**, choose **Run workflow** on `main`, and enter the `sha256:…` digest from the release notes. The workflow pulls that image from `ghcr.io/formaku/formaku`, checks the installation against a fresh SQLite database, and starts the Compose services against a fresh PostgreSQL database. It checks `formaku:doctor`, `/up` and `/login`. Use an image from alpha.48 or later, when `formaku:doctor` became available.

For new tagged releases, the release workflow runs the SQLite installation check against the public image by digest before it publishes the GitHub prerelease.

### Verify a Composer archive

Run the same installation check locally with the ZIP and its adjacent `.sha256` file. It needs PHP 8.4 or later with PDO SQLite, Composer, Python 3, ZIP support and access to Packagist for the test host.

```sh
bash scripts/smoke-composer-package.sh /absolute/path/formaku-cms-v0.1.0-alpha.75.zip
```

The check creates a temporary Laravel 13 application and installs the ZIP as a Composer distribution. It verifies Formaku migrations, panel configuration and published assets, plus the host’s existing route and a synthetic database record.

### Upgrade an existing alpha installation

Back up the database and uploads and verify you can restore them. Keep the existing `.env.docker` and `APP_KEY`. Update the checkout to `v0.1.0-alpha.75`, clear any `COMPOSE_FILE` setting for the first alpha's health-check override, and pull or build the alpha.75 image as described above. Stop the web, worker and scheduler before applying pending migrations with the new image.

```sh
docker compose --env-file .env.docker stop web worker scheduler
docker compose --env-file .env.docker run --rm web php artisan migrate --force
docker compose --env-file .env.docker up -d --no-build --wait
```

Alpha.57 adds no migrations. Alpha.37 adds the request attachments table. Back up the database and the private uploads volume together before upgrading. For a code rollback to alpha.36, retain the table and files for use after re-upgrading. Alpha.30 creates a table for page translation links. Existing page drafts and publications are preserved. Apply the migration before starting the updated services. Set `APP_URL` to the public base URL, including any subdirectory, for the absolute `hreflang` links. When upgrading from alpha.14 or earlier, apply the alpha.15 migration that creates the page templates table. After upgrading, verify that a saved draft can be used as a template for a new draft. If you are upgrading from alpha.12 or earlier, the alpha.13 site navigation migration still applies. Existing sites retain their default header and start with an empty footer. Upgrades from alpha.10 or earlier still require the alpha.11 media usage migration. It indexes existing references from current drafts and published revisions. Allow it to finish before starting the updated services and check **Media → Użycie pliku** for a file referenced by an existing record. Existing layouts without explicit column or stacking settings retain their automatic grid. Upgrades from before alpha.4 still apply its redirect table migration. Existing published addresses stay active, and Formaku records URL changes when pages are published after the upgrade. Upgrades from the first alpha also apply the assistant migration introduced in alpha.2. After the upgrade, verify that published pages load and that `APP_URL` is the public base URL for the sitemap.

The revision history index migration adds an index on PostgreSQL and SQLite. PostgreSQL blocks writes to the revisions table while building it. On a large installation, allow time for this step in the maintenance window. The migration leaves revision data in place.

When updating an existing Laravel host through Composer, [republish assets and apply pending migrations](developer/README.md#upgrade-an-existing-composer-installation) before serving traffic.

When upgrading to alpha.33, review public content models that have a private `slug` field. Their existing drafts and revisions are retained. To restore public visibility, make sure the stored URLs are safe to publish and clear the field’s Private setting in the model.

Before rolling back from alpha.34 after activating field permissions, restrict affected collections to users allowed to see their full records. Verify collection access before starting the earlier version.

Before reverting to alpha.37, back up the site settings. Saving the menu in alpha.37 can discard the site name and logo selection.

For a code rollback to alpha.29, keep the translation links table in place. Rolling back its migration deletes the saved links. Back up the database before removing that table.

Before reverting to alpha.28 or earlier, record the selected color accent. Saving website settings in an older version can discard that preference. Select the accent again after returning to alpha.29.

Before reverting to alpha.27 or earlier, save an explicit **Light** or **Dark** website theme in the panel if you enabled **Follow system**. Older versions only support the two explicit themes.

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

### Read deployment status in GitHub Actions

Update the host controller and the `deploy/ssh-dispatch` script used by the deployment key's SSH forced command from the same alpha.41 or newer checkout. Keep the existing deployment authorization and add the narrowly scoped status command. For sudo 1.9.10 or newer, replace `formaku-deploy` with the deployment account in this sudoers example and validate the file with `visudo -c`.

```sudoers
formaku-deploy ALL=(root) NOPASSWD: /usr/local/sbin/formaku-deployctl ^status [a-f0-9-]{36}$
```

After the deployment command returns, open the workflow run summary. It shows the host state for the requested release UUID and image digest. A matching failure can include the rollback outcome and completed backup UUID. If the host gives no matching status, the summary reports that it is unavailable. The workflow keeps the exit code of the original deployment command.

For automation, download `formaku-deployment-<run attempt>` from that run’s artifacts. Its `formaku-deployment.json` identifies the release UUID and image digest and reports the SSH exit code separately from the host state observed after the command. An unavailable or mismatched host response yields `host_status: null`. Match both identifiers to the requested deployment before using the receipt. Use the controller’s status command for a fresh reading.

### Check a deployment receipt in Formaku

Open **Releases → Deployment receipt**. Enter the numeric GitHub Actions run ID, the deployment UUID and the full `ghcr.io/owner/repository@sha256:…` reference used to dispatch **Deploy existing image** on `main`. Select **Check receipt**. Formaku checks the report for the latest run attempt in `GITHUB_REPOSITORY`. Enable PHP’s ZIP extension and configure `GITHUB_TOKEN` with [Actions read permission](https://docs.github.com/en/rest/actions/artifacts#list-workflow-run-artifacts).

The result shows the SSH exit code and, when available, the state reported by the host after that attempt. If the report is missing or the host status is unavailable, check the workflow summary and use the controller’s status command for a fresh reading.

### Read the latest status of a deployment attempt from GitHub Actions

In GitHub Actions, run **Read deployment status** on `main` with the deployment UUID and full image digest. The workflow uses the existing `production` environment and its SSH configuration. It sends the restricted status command to the host and checks the returned UUID and digest before showing the result in the run summary.

A matching response means the status query succeeded. It can show that the host last recorded deployment in progress, completed, or failed, with rollback information when available. If the controller no longer retains that attempt, the identifiers differ, or the host cannot be reached, the query reports the status as unavailable. The result describes the controller’s most recent record for the requested attempt. Use service health checks when verifying which image currently serves traffic.

### Read deployment and rollback status

On an existing deployment host, install the controller from the alpha.75 checkout with `sudo install -m 750 deploy/deployctl /usr/local/sbin/formaku-deployctl`. Run `sudo /usr/local/sbin/formaku-deployctl status` to read the current status. Add a release UUID to return status only for that attempt. The controller retains the latest attempt. The JSON reports the current attempt, including `deploying` while the controller is running. A failed attempt includes the requested release UUID and digest, plus one `rollback` value.

| Value | Meaning |
| --- | --- |
| `not_attempted` | The failure happened before the controller attempted to stop application services. |
| `restored` | Compose brought the previous image back and its health checks passed. |
| `failed` | Compose could not confirm that the previous image was healthy. |
| `unavailable` | There was no previous image recorded. If the candidate was started, the controller attempts to stop its web, worker and scheduler services. |

When `backup` is present, the encrypted archive for that UUID is complete in `/opt/formaku/backups`. Use a fresh UUID for another deployment attempt. The controller preserves an existing archive.

### Verify the running image

On the deployment host, run `sudo /usr/local/sbin/formaku-deployctl runtime` with the controller from this release. It checks the digest in `active-image` against the locally resolved Docker image and the running, healthy Compose services `web`, `worker` and `scheduler`. This gives a fresh observation even when the latest deployment attempt failed and restored the previous image.

| State | Meaning |
| --- | --- |
| `verified` | Each of the three services has one healthy, running container with the image recorded in `image`. |
| `transitioning` | A deployment is active or the controller's deployment record changed during the check. |
| `mismatch` | The observed service state or image differs from the expected runtime. |
| `unavailable` | The controller lacks the marker or Docker evidence needed for an observation. |

Use `status` to read the result of the last deployment attempt and its rollback outcome. Use the public URL to verify the external route separately.

### Read the running image from GitHub Actions

Update the host controller and forced-command dispatcher from the same release. If the deployment account should be able to read runtime remotely, add the exact `runtime` command to its existing sudoers configuration. Replace `formaku-deploy` with that account and validate the file with `visudo -c`.

```sudoers
formaku-deploy ALL=(root) NOPASSWD: /usr/local/sbin/formaku-deployctl runtime
```

On `main`, run **Read runtime status**. It uses the existing `production` environment and pinned SSH host key. The summary includes the observed state and, for `verified`, the full image digest. A failed run can report `transitioning`, `mismatch`, or `unavailable`. For deployment attempt history, use **Read deployment status** with the attempt's UUID and image.

For automation, download the `formaku-runtime-<attempt>` artifact from the runtime workflow. Its `formaku-runtime.json` contains the run ID, attempt number, UTC observation time and host state. The image reference is included for `verified` observations. Match the run and attempt before using the result. Run a new observation to check the host again.

Automatic deployment supports backwards-compatible migrations. An image rollback uses the existing database and files. Recover data from the encrypted archive as a separate operation if needed. Store `backup.key` separately from the host and test data recovery before using real data. CI exercises deployment failures against a synthetic Compose fixture. The target VPS still needs its own acceptance test.

Compose must read .env.docker for interpolation: use docker compose --env-file .env.docker. The host helper exports DB_PASSWORD from the runtime file through Compose's env-file option (see script).
