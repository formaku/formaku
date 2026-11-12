<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\FormakuServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

class InstallCommand extends Command
{
    protected $signature = 'formaku:install';

    protected $description = 'Publish Formaku configuration and panel assets, and refresh its compiled views';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'formaku-config']);
        $this->call('vendor:publish', ['--tag' => 'formaku-assets', '--force' => true]);
        if (! $this->clearFormakuViews($files, $this->laravel->make('blade.compiler'))) {
            return self::FAILURE;
        }

        $this->info('Run php artisan migrate, configure formaku.authorize, then open /formaku.');

        return self::SUCCESS;
    }

    private function clearFormakuViews(Filesystem $files, BladeCompiler $compiler): bool
    {
        foreach ($files->allFiles(FormakuServiceProvider::VIEW_PATH) as $view) {
            if ($view->isLink() || ! str_ends_with($view->getFilename(), '.blade.php')) {
                continue;
            }

            // Blade may hash either the registered path or the canonical path used by view:cache.
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $view->getRelativePathname());
            $registeredPath = FormakuServiceProvider::VIEW_PATH.'/'.$relativePath;
            foreach (array_unique([$registeredPath, $view->getRealPath() ?: $registeredPath]) as $path) {
                $compiledPath = $compiler->getCompiledPath($path);
                if (! $files->exists($compiledPath) || $files->delete($compiledPath)) {
                    continue;
                }

                clearstatcache(false, $compiledPath);
                if ($files->exists($compiledPath)) {
                    $this->error("Could not remove compiled Formaku view: {$relativePath}");

                    return false;
                }
            }
        }

        return true;
    }
}
