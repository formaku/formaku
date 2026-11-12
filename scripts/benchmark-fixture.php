<?php

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

try {
    $root = getcwd();
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $database = DB::connection();
    if (! $app->environment('benchmark') || $database->getDriverName() !== 'pgsql'
        || $database->getConfig('host') !== 'database' || $database->getDatabaseName() !== 'formaku_benchmark') {
        throw new RuntimeException('Benchmark fixtures require APP_ENV=benchmark and PostgreSQL database:formaku_benchmark.');
    }

    $password = getenv('FORMAKU_BENCHMARK_PASSWORD');
    if (! is_string($password) || strlen($password) < 12) {
        throw new RuntimeException('Set FORMAKU_BENCHMARK_PASSWORD to at least 12 bytes.');
    }

    if ($database->scalar('select current_database()') !== 'formaku_benchmark') {
        throw new RuntimeException('The connected PostgreSQL database is not formaku_benchmark.');
    }

    $pagesId = $database->transaction(function () use ($database, $password): int {
        // Prevent a second fixture invocation or other writers from passing the empty-database check.
        $database->statement('lock table users, formaku_records in share row exclusive mode');
        if (User::query()->exists() || Record::query()->exists()) {
            throw new RuntimeException('Benchmark fixtures require empty users and formaku_records tables.');
        }

        $admin = new User([
            'name' => 'Benchmark administrator',
            'email' => 'benchmark@formaku.test',
            'password' => Hash::make($password),
        ]);
        $admin->role = 'admin';
        $admin->email_verified_at = now();
        $admin->save();

        if (Artisan::call('formaku:demo') !== 0) {
            throw new RuntimeException('Could not create the benchmark demo page.');
        }
        $pages = Collection::where('key', 'pages')->sole();
        $records = app(RecordService::class);
        for ($number = 1; $number <= 250; $number++) {
            $title = sprintf('Benchmark page %03d', $number);
            $slug = sprintf('benchmark-%03d', $number);
            $record = $records->save($pages, null, [
                'title' => $title,
                'slug' => $slug,
                'blocks' => [
                    ['type' => 'hero', 'props' => [
                        'eyebrow' => 'FORMAKU BENCHMARK',
                        'title' => $title,
                        'text' => 'Synthetic content for the Formaku reference workload.',
                    ]],
                    ['type' => 'cta', 'props' => [
                        'title' => 'Read more',
                        'label' => 'Demo page',
                        'url' => '/p/start',
                    ]],
                ],
            ], 0, $admin->id);
            $records->publish($record, $record->version, $admin->id);
        }

        // Leave the scheduler idle for the read benchmark without deleting the fixture history.
        $database->table('formaku_events')
            ->where('actor_id', $admin->id)
            ->where('type', 'saved')
            ->whereIn('record_id', Record::query()->select('id')->where('collection_id', $pages->id))
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]);

        return $pages->id;
    });

    echo $pagesId.PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
