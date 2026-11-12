<?php

namespace Formaku\Cms;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as BladeView;

class FormakuServiceProvider extends ServiceProvider
{
    public const VIEW_PATH = __DIR__.'/../resources/views';

    public function register(): void
    {
        $this->app->singleton(Services\Registry::class);
        $this->mergeConfigFrom(__DIR__.'/../config/formaku.php', 'formaku');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(self::VIEW_PATH, 'formaku');
        View::composer('formaku::layout', function (BladeView $view): void {
            $navigation = $this->app->make(Services\SiteNavigation::class);
            $settings = $navigation->current();
            $view->with('siteNavigation', $settings);
            $view->with('siteBrandLogoUrl', $navigation->publicBrandLogoUrl($settings['brandLogoId']));
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/assistant.php');
        $this->publishes([__DIR__.'/../config/formaku.php' => config_path('formaku.php')], 'formaku-config');
        $this->publishes([__DIR__.'/../public' => public_path('vendor/formaku')], 'formaku-assets');
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
                Console\MediaVariantsCommand::class,
                Console\PublishScheduledCommand::class,
            ]);
        }
    }
}
