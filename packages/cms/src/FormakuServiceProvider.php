<?php

namespace Formaku\Cms;

use Illuminate\Support\ServiceProvider;

class FormakuServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Services\Registry::class);
        $this->mergeConfigFrom(__DIR__.'/../config/formaku.php', 'formaku');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'formaku');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->publishes([__DIR__.'/../config/formaku.php' => config_path('formaku.php')], 'formaku-config');
        $this->publishes([__DIR__.'/../public' => public_path('vendor/formaku')], 'formaku-assets');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\InstallCommand::class]);
        }
    }
}
