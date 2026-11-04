<?php

namespace Formaku\Cms\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'formaku:install';

    protected $description = 'Publish Formaku configuration and compiled panel assets';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'formaku-config']);
        $this->call('vendor:publish', ['--tag' => 'formaku-assets', '--force' => true]);
        $this->info('Run php artisan migrate, configure formaku.authorize, then open /formaku.');

        return self::SUCCESS;
    }
}
