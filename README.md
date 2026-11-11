# Formaku

Project website and documentation are at [formaku.eu](https://formaku.eu/). Formaku is developed by [Strefa Badań](https://strefabadan.pl/).

An open-source Laravel CMS and schema-driven application builder. **0.1.0-alpha.24**.

Vue 3 administration, server-rendered pages, versioned records, a customer portal and Docker delivery. MIT licensed. One installation hosts one application.

## Run locally

Requirements: PHP 8.4+, Composer, Node 22+, SQLite for a quick local start; PostgreSQL 17 or MySQL 8.4 for deployment.

```sh
cd apps/starter
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
cd ../..
npm ci --ignore-scripts
npm run build
cd apps/starter
php artisan formaku:install
php artisan formaku:admin your-email@example.com
php artisan formaku:demo
php artisan serve
```

Open `/formaku` and sign in with the administrator account you created. Use a password of at least 12 characters. The demo seeds example collections and publishes `/p/start` on a fresh installation. Run `php artisan queue:work --tries=1 --timeout=90` and `php artisan schedule:work` in separate terminals for AI, scheduled publication and notification delivery.

## Package integration

`packages/cms` is the `formaku/cms` Composer package. [Install from the Formaku Composer repository](docs/developer/README.md#install-in-an-existing-laravel-app) in an existing Laravel 13 app. It defaults to denying administrative access and disabling public routes. Set the `authorize` callable and host guard in `config/formaku.php`. Publish assets with `php artisan formaku:install`, then run migrations. The starter provides its own login and customer account routes; the package does not replace host authentication. See the [Laravel extension examples](docs/developer/README.md) or the [Polish guide](docs/developer/README.pl.md) to register a block and call a resource or action from a host route.

## Docker

```sh
cp .env.docker.example .env.docker
# Set persistent APP_KEY and DB_PASSWORD in .env.docker.
# Export DB_PASSWORD to Compose without committing the file.
docker compose --env-file .env.docker build
docker compose --env-file .env.docker up -d database
docker compose --env-file .env.docker run --rm web php artisan migrate --force
docker compose --env-file .env.docker up -d
```

The same image runs web, queue and scheduler. Local port is 8080. Upgrades from versions before alpha.4 still apply the slug redirects migration. See [deployment and upgrade instructions](docs/DEPLOYMENT.md), [user manual](docs/user/README.md), and [implementation status](docs/STATUS.md).

## Tests

```sh
cd apps/starter && php artisan test
# At repository root, with a local server and isolated QA data:
npm run build
npx playwright test
```

CI exercises PHP 8.4/8.5 against PostgreSQL 17 and MySQL 8.4. Browser tests require an explicitly created disposable local account described in `tests/browser/cms.spec.ts`.

## AI

Set `OPENAI_API_KEY` / `OPENAI_MODEL`, or `GEMINI_API_KEY` / `GEMINI_MODEL` on the server. Both providers return proposals, never automatic publication. Models are configurable. No API key or paid account is required to use the CMS.

## License and provenance

MIT. Laravel skeleton: Laravel contributors, MIT. The CMS is a new implementation based on patterns audited in DrPrep; no DrPrep code, medical assets or private data were copied. Third-party fonts retain their OFL notices. Existing Commerce documents are a separate planned extension, outside this alpha.
