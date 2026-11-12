<?php

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
$editor = User::firstOrNew(['email' => 'field-editor@formaku.test']);
$editor->name = 'Field Editor';
$editor->password = $user->password;
$editor->role = 'editor';
$editor->email_verified_at = now();
$editor->save();

Artisan::call('formaku:demo');

DB::transaction(function () use ($user) {
    $target = Collection::firstOrCreate(['key' => 'relation_targets'], [
        'name' => 'Relation targets',
        'kind' => 'data',
        'definition' => ['fields' => [['key' => 'name', 'label' => 'Nazwa', 'type' => 'text']]],
    ]);
    if (Record::where('collection_id', $target->id)->exists()) {
        return;
    }

    $records = app(RecordService::class);
    for ($index = 1; $index <= 26; $index++) {
        $records->save($target, null, ['name' => 'Target '.$index], 0, $user->id);
    }
});
