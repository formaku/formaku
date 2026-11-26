<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\FormakuServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Symfony\Component\Finder\Finder;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'formaku:doctor {--json : Print the diagnostic result as JSON}';

    protected $description = 'Check Formaku authentication, migrations and published panel assets';

    private const REQUIRED_TABLES = [
        'formaku_ai_tasks',
        'formaku_assistant_creates',
        'formaku_assistants',
        'formaku_collections',
        'formaku_events',
        'formaku_media',
        'formaku_media_usages',
        'formaku_page_templates',
        'formaku_portal_attachments',
        'formaku_record_translations',
        'formaku_records',
        'formaku_relations',
        'formaku_releases',
        'formaku_revisions',
        'formaku_schema_versions',
        'formaku_site_navigation',
        'formaku_slug_redirects',
    ];

    public function handle(Migrator $migrator): int
    {
        $checks = [
            'authentication' => $this->checkAuthentication(),
            'database' => $this->checkMigrations($migrator),
            'assets' => $this->checkAssets(),
        ];

        $passed = ! in_array(false, $checks, true);
        $publicRoutes = (bool) config('formaku.public_routes');

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $passed,
                'checks' => $checks,
                'public_routes' => $publicRoutes,
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->line($publicRoutes ? 'Public routes are enabled.' : 'Public routes are disabled.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    private function checkAuthentication(): bool
    {
        $guard = config('formaku.guard');
        $guards = config('auth.guards');
        $guardExists = is_string($guard) && $guard !== '' && is_array($guards) && is_array($guards[$guard] ?? null);
        $authorizationConfigured = is_callable(config('formaku.authorize'));

        if ($guardExists) {
            $this->reportInfo('Authentication guard is configured.');
        } else {
            $this->reportError('Set formaku.guard to a guard defined in auth.guards.');
        }

        if ($authorizationConfigured) {
            $this->reportInfo('Authorization callback is callable.');
        } else {
            $this->reportError('Configure a callable formaku.authorize policy.');
        }

        return $guardExists && $authorizationConfigured;
    }

    private function checkMigrations(Migrator $migrator): bool
    {
        $path = FormakuServiceProvider::MIGRATION_PATH;
        if (! in_array($path, $migrator->paths(), true)) {
            $this->reportError('Formaku migrations are not registered. Check the package service provider.');

            return false;
        }

        $migrations = $migrator->getMigrationFiles($path);
        if ($migrations === []) {
            $this->reportError('No Formaku migration files were found. Reinstall the package.');

            return false;
        }

        try {
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        } catch (Throwable) {
            $this->reportError('Could not read migration history. Check the configured database connection.');

            return false;
        }

        $pending = count(array_diff(array_keys($migrations), $ran));
        if ($pending > 0) {
            $this->reportError("Pending Formaku migrations: {$pending}. Run php artisan migrate.");

            return false;
        }

        $this->reportInfo('Applied Formaku migrations: '.count($migrations).'.');

        try {
            $schema = $migrator->resolveConnection(null)->getSchemaBuilder();
            $missing = array_filter(self::REQUIRED_TABLES, fn (string $table): bool => ! $schema->hasTable($table));
        } catch (Throwable) {
            $this->reportError('Could not inspect Formaku tables. Check the configured database connection.');

            return false;
        }

        if ($missing !== []) {
            $this->reportError('Missing Formaku tables: '.implode(', ', $missing).'. Check the database or restore a complete backup.');

            return false;
        }

        $this->reportInfo('All required Formaku tables exist.');

        return true;
    }

    private function checkAssets(): bool
    {
        $paths = FormakuServiceProvider::pathsToPublish(FormakuServiceProvider::class, 'formaku-assets');
        if ($paths === []) {
            $this->reportError('Formaku panel assets are not registered for publishing. Check the package service provider.');

            return false;
        }

        $passed = true;
        foreach ($paths as $source => $target) {
            $entrypoints = ['main.css', 'app.js'];
            $assets = $entrypoints;
            if (is_dir($source)) {
                try {
                    foreach (Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($source)->sortByName() as $file) {
                        $asset = $file->getRelativePathname();
                        if (! in_array($asset, $entrypoints, true)) {
                            $assets[] = $asset;
                        }
                    }
                } catch (Throwable) {
                    $this->reportError('Could not list the bundled panel assets. Check the package installation.');
                    $passed = false;
                }
            }

            foreach ($assets as $asset) {
                $bundle = $this->assetHash($source.'/'.$asset);
                $published = $this->assetHash($target.'/'.$asset);

                if ($bundle === false) {
                    $this->reportError("Bundled {$asset} is missing or unreadable. Install a packaged release or build the assets from source.");
                } elseif ($published === false) {
                    $this->reportError("Published {$asset} is missing or unreadable. Run php artisan formaku:install.");
                } elseif ($published !== $bundle) {
                    $this->reportError("Published {$asset} does not match the bundle. Run php artisan formaku:install.");
                } else {
                    if (in_array($asset, $entrypoints, true)) {
                        $this->reportInfo("Published {$asset} matches the bundle.");
                    }

                    continue;
                }

                $passed = false;
            }
        }

        if ($passed) {
            $this->reportInfo('All published Formaku panel assets match the bundle.');
        }

        return $passed;
    }

    private function reportInfo(string $message): void
    {
        if (! $this->option('json')) {
            $this->info($message);
        }
    }

    private function reportError(string $message): void
    {
        if (! $this->option('json')) {
            $this->error($message);
        }
    }

    private function assetHash(string $path): string|false
    {
        return is_file($path) ? @hash_file('sha256', $path) : false;
    }
}
