<?php

namespace Tests\Feature;

use Formaku\Cms\FormakuServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixture;

    private array $originalPublishes;

    private array $originalAssetGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = sys_get_temp_dir().'/formaku-doctor-'.Str::uuid();
        $this->originalPublishes = FormakuServiceProvider::$publishes[FormakuServiceProvider::class];
        $this->originalAssetGroup = FormakuServiceProvider::$publishGroups['formaku-assets'];

        $bundle = $this->fixture.'/bundle';
        $public = $this->fixture.'/public/vendor/formaku';
        File::ensureDirectoryExists($bundle);
        File::ensureDirectoryExists($public);
        FormakuServiceProvider::$publishes[FormakuServiceProvider::class][$bundle] = $public;
        FormakuServiceProvider::$publishGroups['formaku-assets'] = [$bundle => $public];

        foreach (['main.css' => 'body { color: black; }', 'app.js' => 'export default {};'] as $name => $contents) {
            File::put($bundle.'/'.$name, $contents);
            File::put($public.'/'.$name, $contents);
        }
    }

    protected function tearDown(): void
    {
        FormakuServiceProvider::$publishes[FormakuServiceProvider::class] = $this->originalPublishes;
        FormakuServiceProvider::$publishGroups['formaku-assets'] = $this->originalAssetGroup;
        File::deleteDirectory($this->fixture);

        parent::tearDown();
    }

    public function test_passes_with_applied_migrations_matching_assets_and_a_callable_policy(): void
    {
        config([
            'formaku.authorize' => fn () => $this->fail('The doctor must not invoke the authorization policy.'),
            'formaku.public_routes' => false,
        ]);

        $this->artisan('formaku:doctor')
            ->expectsOutput('Authentication guard is configured.')
            ->expectsOutput('Authorization callback is callable.')
            ->expectsOutputToContain('Applied Formaku migrations:')
            ->expectsOutput('Published main.css matches the bundle.')
            ->expectsOutput('Published app.js matches the bundle.')
            ->expectsOutput('Public routes are disabled.')
            ->assertSuccessful();
    }

    public function test_json_reports_success_with_public_routes_disabled(): void
    {
        config(['formaku.public_routes' => false]);

        $this->assertSame(0, Artisan::call('formaku:doctor', ['--json' => true]));
        $this->assertSame([
            'ok' => true,
            'checks' => ['authentication' => true, 'database' => true, 'assets' => true],
            'public_routes' => false,
        ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_json_reports_failed_checks_without_printing_connection_details(): void
    {
        $default = config('database.default');
        config([
            'database.default' => 'synthetic-private-connection',
            'formaku.public_routes' => true,
        ]);
        File::delete($this->fixture.'/public/vendor/formaku/main.css');

        try {
            $this->assertSame(1, Artisan::call('formaku:doctor', ['--json' => true]));
            $this->assertSame([
                'ok' => false,
                'checks' => ['authentication' => true, 'database' => false, 'assets' => false],
                'public_routes' => true,
            ], json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_reports_configuration_and_asset_problems_without_changing_the_installation(): void
    {
        config([
            'formaku.guard' => 'synthetic-missing-guard',
            'formaku.authorize' => null,
            'formaku.public_routes' => true,
        ]);
        File::delete($this->fixture.'/bundle/main.css');
        $publishedScript = $this->fixture.'/public/vendor/formaku/app.js';
        File::put($publishedScript, 'synthetic stale script');

        $this->artisan('formaku:doctor')
            ->expectsOutput('Set formaku.guard to a guard defined in auth.guards.')
            ->expectsOutput('Configure a callable formaku.authorize policy.')
            ->expectsOutputToContain('Bundled main.css is missing or unreadable.')
            ->expectsOutput('Published app.js does not match the bundle. Run php artisan formaku:install.')
            ->expectsOutput('Public routes are enabled.')
            ->doesntExpectOutputToContain('synthetic-missing-guard')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->fixture.'/bundle/main.css');
        $this->assertSame('synthetic stale script', File::get($publishedScript));
    }

    public function test_checks_nested_editor_chunks_and_fonts_without_republishing_them(): void
    {
        $bundle = $this->fixture.'/bundle';
        $public = $this->fixture.'/public/vendor/formaku';
        File::ensureDirectoryExists($bundle.'/assets');
        File::put($bundle.'/assets/editor-example.js', 'editor chunk');
        File::put($bundle.'/inter-example.woff2', 'font bundle');
        File::put($public.'/inter-example.woff2', 'stale font');

        $this->artisan('formaku:doctor')
            ->expectsOutput('Published assets/editor-example.js is missing or unreadable. Run php artisan formaku:install.')
            ->expectsOutput('Published inter-example.woff2 does not match the bundle. Run php artisan formaku:install.')
            ->assertFailed();

        $this->assertFileDoesNotExist($public.'/assets/editor-example.js');
        $this->assertSame('stale font', File::get($public.'/inter-example.woff2'));

        File::copyDirectory($bundle, $public);
        File::put($public.'/previous-release.js', 'leftover asset');

        $this->artisan('formaku:doctor')
            ->expectsOutput('All published Formaku panel assets match the bundle.')
            ->assertSuccessful();
    }

    public function test_counts_only_missing_formaku_migrations_and_leaves_missing_assets_unpublished(): void
    {
        $table = config('database.migrations.table');
        $packageMigration = '2026_11_04_000001_create_formaku_tables';
        $hostMigration = '0001_01_01_000000_create_users_table';
        $this->assertSame(2, DB::table($table)->whereIn('migration', [$packageMigration, $hostMigration])->delete());
        $missingAsset = $this->fixture.'/public/vendor/formaku/main.css';
        File::delete($missingAsset);

        $this->artisan('formaku:doctor')
            ->expectsOutput('Pending Formaku migrations: 1. Run php artisan migrate.')
            ->expectsOutput('Published main.css is missing or unreadable. Run php artisan formaku:install.')
            ->assertFailed();

        $this->assertDatabaseMissing($table, ['migration' => $packageMigration]);
        $this->assertFileDoesNotExist($missingAsset);
    }

    public function test_detects_missing_tables_despite_complete_migration_history(): void
    {
        Schema::rename('formaku_site_navigation', 'formaku_site_navigation_fixture');

        try {
            $this->artisan('formaku:doctor')
                ->expectsOutputToContain('Applied Formaku migrations:')
                ->expectsOutput('Missing Formaku tables: formaku_site_navigation. Check the database or restore a complete backup.')
                ->assertFailed();

            $this->assertFalse(Schema::hasTable('formaku_site_navigation'));
            $this->assertTrue(Schema::hasTable('formaku_site_navigation_fixture'));
        } finally {
            Schema::rename('formaku_site_navigation_fixture', 'formaku_site_navigation');
        }
    }

    public function test_reports_an_empty_migration_connection_without_creating_the_history_table(): void
    {
        $default = config('database.default');
        config([
            'database.connections.doctor_empty' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.default' => 'doctor_empty',
        ]);

        try {
            $count = count(glob(FormakuServiceProvider::MIGRATION_PATH.'/*_*.php'));
            $this->artisan('formaku:doctor')
                ->expectsOutput("Pending Formaku migrations: {$count}. Run php artisan migrate.")
                ->assertFailed();

            $this->assertFalse(Schema::connection('doctor_empty')->hasTable(config('database.migrations.table')));
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_reports_an_unavailable_database_without_printing_connection_details(): void
    {
        $default = config('database.default');
        config(['database.default' => 'synthetic-private-connection']);

        try {
            $this->artisan('formaku:doctor')
                ->expectsOutput('Could not read migration history. Check the configured database connection.')
                ->expectsOutput('Published main.css matches the bundle.')
                ->doesntExpectOutputToContain('synthetic-private-connection')
                ->assertFailed();
        } finally {
            config(['database.default' => $default]);
        }
    }
}
