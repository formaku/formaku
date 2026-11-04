<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../apps/starter/vendor/autoload.php';
$app = require __DIR__.'/../apps/starter/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite' || ! str_ends_with(config('database.connections.sqlite.database'), 'formaku-e2e.sqlite')) {
    throw new RuntimeException('Browser fixtures require APP_ENV=testing and a dedicated formaku-e2e.sqlite database.');
}
$user = User::firstOrNew(['email' => 'admin@formaku.test']);
$user->name = 'Formaku QA';
$user->password = Hash::make('Local-Test-Formaku-2026!');
$user->role = 'admin';
$user->email_verified_at = now();
$user->save();
Artisan::call('formaku:demo');
