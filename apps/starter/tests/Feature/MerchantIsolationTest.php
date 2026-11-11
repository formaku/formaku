<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Commerce\CommerceServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantIsolationTest extends TestCase
{
    private const A = '00000000-0000-4000-8000-000000000001';

    private const B = '00000000-0000-4000-8000-000000000002';

    private ?string $merchant = null;

    private ?string $directory = null;

    public function createApplication(): Application
    {
        if ($this->merchant === null) {
            return parent::createApplication();
        }

        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useStoragePath($this->directory);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $host = $this->merchant === self::A ? 'seller-a.test' : 'seller-b.test';
            $database = 'formaku_'.str_replace('-', '', $this->merchant);
            $sqlite = $this->directory.'/'.$database.'.sqlite';
            $port = getenv('FORMAKU_MERCHANT_TEST_PG_PORT');
            if ($port === false && ! is_file($sqlite)) {
                touch($sqlite);
            }
            $app['config']->set([
                'merchant.id' => $this->merchant,
                'merchant.host' => $host,
                'database.default' => $port === false ? 'sqlite' : 'pgsql',
                'database.connections.sqlite.database' => $sqlite,
                'database.connections.sqlite.url' => null,
                'database.connections.pgsql.url' => null,
                'database.connections.pgsql.host' => '127.0.0.1',
                'database.connections.pgsql.port' => (int) $port,
                'database.connections.pgsql.database' => $database,
                'database.connections.pgsql.username' => $database,
                'database.connections.pgsql.password' => 'testing-only',
                'app.key' => 'base64:'.base64_encode(hash('sha256', $this->merchant, true)),
                'formaku-commerce.enabled' => true,
            ]);
        });
        if (class_exists(CommerceServiceProvider::class)) {
            $app->booting(fn () => $app->register(CommerceServiceProvider::class));
        }
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_two_merchants_isolate_catalog_assistants_files_cache_and_queue(): void
    {
        $commerce = getenv('FORMAKU_COMMERCE_TEST_PATH');
        if ($commerce !== false) {
            $this->assertFileExists($commerce.'/src/CommerceServiceProvider.php');
            $loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
            $loader->addPsr4('Formaku\\Commerce\\', $commerce.'/src');
        }
        $this->directory = sys_get_temp_dir().'/formaku-isolation-'.Str::uuid();
        File::ensureDirectoryExists($this->directory);

        $this->bootMerchant(self::A);
        $this->seedMerchant('seller-a.test', 'ALPHA');
        Cache::put('shared-key', 'alpha');
        Storage::disk('local')->put('same-name.txt', 'alpha');
        Queue::pushRaw('{"displayName":"alpha"}');
        $code = $this->postJson('https://seller-a.test/formaku/api/v1/assistant/pairing', ['collection_id' => 1, 'can_publish' => false])
            ->assertCreated()->json('code');
        $this->getJson('https://seller-b.test/formaku/assistant/v1/connection')->assertStatus(421)->assertCookieMissing(config('session.cookie'));

        $this->bootMerchant(self::B);
        $this->seedMerchant('seller-b.test', 'BETA');
        $this->assertNull(Cache::get('shared-key'));
        $this->assertFalse(Storage::disk('local')->exists('same-name.txt'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->postJson('https://seller-b.test/formaku/assistant/v1/exchange', ['code' => $code])->assertUnauthorized();
        $this->assertCatalog('seller-b.test', 'BETA');

        $this->bootMerchant(self::A, migrate: false);
        $token = $this->postJson('https://seller-a.test/formaku/assistant/v1/exchange', ['code' => $code])
            ->assertOk()->json('access_token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('https://seller-a.test/formaku/assistant/v1/connection', $headers)->assertOk();
        $this->assertCatalog('seller-a.test', 'ALPHA');
        $this->assertSame('alpha', Cache::get('shared-key'));
        $this->assertSame('alpha', Storage::disk('local')->get('same-name.txt'));

        $this->bootMerchant(self::B, migrate: false);
        $this->getJson('https://seller-b.test/formaku/assistant/v1/connection', $headers)->assertUnauthorized();
    }

    protected function tearDown(): void
    {
        $directory = $this->directory;
        parent::tearDown();
        if ($directory !== null) {
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function bootMerchant(string $id, bool $migrate = true): void
    {
        $this->merchant = $id;
        $this->refreshApplication();
        if ($migrate) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        }
    }

    private function seedMerchant(string $host, string $sku): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame(1, $admin->id);
        $collection = Collection::create([
            'key' => 'pages',
            'kind' => 'content',
            'name' => $sku,
            'definition' => [
                'public' => true,
                'fields' => [
                    ['key' => 'title', 'type' => 'text'],
                    ['key' => 'slug', 'type' => 'text'],
                    ['key' => 'blocks', 'type' => 'richtext'],
                ],
            ],
        ]);
        $this->assertSame(1, $collection->id);

        $this->actingAs($admin);
        if (class_exists(CommerceServiceProvider::class)) {
            Gate::define('formaku-commerce.catalog', fn (User $user): bool => $user->role === 'admin');
            $this->postJson('https://'.$host.'/formaku/api/v1/commerce/admin/products', [
                'sku' => $sku, 'name' => $sku, 'price_minor' => 2900, 'available' => 1, 'published' => true,
            ])->assertCreated()->assertJsonPath('id', 1);
        }
    }

    private function assertCatalog(string $host, string $sku): void
    {
        if (class_exists(CommerceServiceProvider::class)) {
            $this->getJson('https://'.$host.'/formaku/api/v1/commerce/products')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', $sku);
        }

    }
}
