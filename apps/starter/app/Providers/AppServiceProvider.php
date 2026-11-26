<?php

namespace App\Providers;

use App\Models\User;
use App\Support\MerchantRuntime;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        MerchantRuntime::configure($this->app['config']);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('formaku-commerce.enabled')) {
            foreach (['catalog', 'stock', 'orders', 'payments'] as $ability) {
                Gate::define('formaku-commerce.'.$ability, fn (User $user): bool => $user->role === 'admin');
            }
        }
    }
}
