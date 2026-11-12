<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\FormakuServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Throwable;

class DoctorCommand extends Command
{
    protected $signature = 'formaku:doctor';

    protected $description = 'Check Formaku authentication, migrations and published panel assets';

    public function handle(Migrator $migrator): int
    {
        $checks = [
            $this->checkAuthentication(),
            $this->checkMigrations($migrator),
            $this->checkAssets(),
        ];

        $this->line(config('formaku.public_routes') ? 'Public routes are enabled.' : 'Public routes are disabled.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function checkAuthentication(): bool
    {
        $guard = config('formaku.guard');
        $guards = config('auth.guards');
        $guardExists = is_string($guard) && $guard !== '' && is_array($guards) && is_array($guards[$guard] ?? null);
        $authorizationConfigured = is_callable(config('formaku.authorize'));

        if ($guardExists) {
            $this->info('Authentication guard is configured.');
        } else {
            $this->error('Set formaku.guard to a guard defined in auth.guards.');
        }

        if ($authorizationConfigured) {
            $this->info('Authorization callback is callable.');
        } else {
            $this->error('Configure a callable formaku.authorize policy.');
        }

        return $guardExists && $authorizationConfigured;
    }

    private function checkMigrations(Migrator $migrator): bool
    {
        $path = FormakuServiceProvider::MIGRATION_PATH;
        if (! in_array($path, $migrator->paths(), true)) {
            $this->error('Formaku migrations are not registered. Check the package service provider.');

            return false;
        }

        $migrations = $migrator->getMigrationFiles($path);
        if ($migrations === []) {
            $this->error('No Formaku migration files were found. Reinstall the package.');

            return false;
        }

        try {
            $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        } catch (Throwable) {
            $this->error('Could not read migration history. Check the configured database connection.');

            return false;
        }

        $pending = count(array_diff(array_keys($migrations), $ran));
        if ($pending > 0) {
            $this->error("Pending Formaku migrations: {$pending}. Run php artisan migrate.");

            return false;
        }

        $this->info('Applied Formaku migrations: '.count($migrations).'.');

        return true;
    }

    private function checkAssets(): bool
    {
        $paths = FormakuServiceProvider::pathsToPublish(FormakuServiceProvider::class, 'formaku-assets');
        if ($paths === []) {
            $this->error('Formaku panel assets are not registered for publishing. Check the package service provider.');

            return false;
        }

        $passed = true;
        foreach ($paths as $source => $target) {
            foreach (['main.css', 'app.js'] as $asset) {
                $bundle = $this->assetHash($source.'/'.$asset);
                $published = $this->assetHash($target.'/'.$asset);

                if ($bundle === false) {
                    $this->error("Bundled {$asset} is missing or unreadable. Install a packaged release or build the assets from source.");
                } elseif ($published === false) {
                    $this->error("Published {$asset} is missing or unreadable. Run php artisan formaku:install.");
                } elseif ($published !== $bundle) {
                    $this->error("Published {$asset} does not match the bundle. Run php artisan formaku:install.");
                } else {
                    $this->info("Published {$asset} matches the bundle.");

                    continue;
                }

                $passed = false;
            }
        }

        return $passed;
    }

    private function assetHash(string $path): string|false
    {
        return is_file($path) ? @hash_file('sha256', $path) : false;
    }
}
