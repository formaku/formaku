<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Commerce\Bridge\Formaku\CatalogResource;
use Formaku\Commerce\CommerceServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MerchantIsolationTest extends TestCase
{
    private const A = '00000000-0000-4000-8000-000000000001';

    private const B = '00000000-0000-4000-8000-000000000002';

    private const COMMERCE = '/formaku/api/v2/commerce';

    private const EMAIL = 'merchant@example.test';

    private const PASSWORD = 'synthetic-test-password';

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
            $runtime = $this->runtimeConfiguration($host);
            $database = $runtime['DB_DATABASE'];
            $sqlite = $this->directory.'/'.$database.'.sqlite';
            $port = getenv('FORMAKU_MERCHANT_TEST_PG_PORT');
            if ($port === false && ! is_file($sqlite)) {
                touch($sqlite);
            }
            $app['config']->set([
                'merchant.id' => $runtime['FORMAKU_MERCHANT_ID'],
                'merchant.host' => $runtime['FORMAKU_MERCHANT_HOST'],
                'database.default' => $port === false ? 'sqlite' : $runtime['DB_CONNECTION'],
                'database.connections.sqlite.database' => $sqlite,
                'database.connections.sqlite.url' => null,
                'database.connections.pgsql.url' => null,
                'database.connections.pgsql.host' => '127.0.0.1',
                'database.connections.pgsql.port' => (int) $port,
                'database.connections.pgsql.database' => $database,
                'database.connections.pgsql.username' => $runtime['DB_USERNAME'],
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
        $platform = getenv('FORMAKU_PLATFORM_TEST_PATH');
        $port = getenv('FORMAKU_MERCHANT_TEST_PG_PORT');
        if ($commerce !== false || $platform !== false || $port !== false) {
            $this->assertNotEmpty($commerce, 'Set FORMAKU_COMMERCE_TEST_PATH for the cross-repository test.');
            $this->assertNotEmpty($platform, 'Set FORMAKU_PLATFORM_TEST_PATH for the cross-repository test.');
            $this->assertNotEmpty($port, 'Set FORMAKU_MERCHANT_TEST_PG_PORT for the cross-repository test.');
            $this->assertFileExists($commerce.'/src/CommerceServiceProvider.php');
            $this->assertFileExists($platform.'/bin/formaku-platform');
            $loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
            $loader->addPsr4('Formaku\\Commerce\\', $commerce.'/src');
            $this->assertTrue(class_exists(CommerceServiceProvider::class));
        }
        $this->directory = sys_get_temp_dir().'/formaku-isolation-'.Str::uuid();
        $this->withCredentials();

        File::ensureDirectoryExists($this->directory.'/framework/views');

        $this->bootMerchant(self::A);
        $this->assertDatabaseBoundary(self::A, self::B);
        $this->seedMerchant('seller-a.test', 'ALPHA');
        $sessionCookie = config('session.cookie');
        $cookie = $this->post('https://seller-a.test/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertRedirect('/formaku')->assertCookie($sessionCookie)->getCookie($sessionCookie, decrypt: false);
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->withUnencryptedCookie($sessionCookie, $cookie->getValue());
        $orderA = $this->placeOrder('seller-a.test');
        $this->get('https://seller-a.test/formaku/media/1')->assertOk()->assertStreamedContent('ALPHA');
        Cache::put('shared-key', 'alpha');
        Queue::pushRaw('{"displayName":"alpha"}');
        $code = $this->postJson('https://seller-a.test/formaku/api/v1/assistant/pairing', ['collection_id' => 1, 'can_publish' => false])
            ->assertCreated()->json('code');
        $this->getJson('https://seller-b.test/formaku/assistant/v1/connection')->assertStatus(421)->assertCookieMissing(config('session.cookie'));

        $this->bootMerchant(self::B);
        $this->assertDatabaseBoundary(self::B, self::A);
        $this->assertNotSame($sessionCookie, config('session.cookie'));
        $this->assertSame(0, DB::table('sessions')->count());
        $this->assertNull(Cache::get('shared-key'));
        $this->assertFalse(Storage::disk('local')->exists('same-name.txt'));
        $this->assertNull(Queue::pop());
        $this->seedMerchant('seller-b.test', 'BETA');
        $this->withUnencryptedCookies([$sessionCookie => $cookie->getValue(), config('session.cookie') => $cookie->getValue()]);
        $this->getJson('https://seller-b.test/formaku/api/v1/collections')->assertUnauthorized();
        $this->get('https://seller-b.test/formaku/media/1')->assertNotFound();
        $cookieB = config('session.cookie');
        $login = $this->post('https://seller-b.test/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertRedirect('/formaku')->assertCookie($cookieB)->getCookie($cookieB, decrypt: false);
        $this->withUnencryptedCookie($cookieB, $login->getValue());
        $this->get('https://seller-b.test/formaku/media/1')->assertOk()->assertStreamedContent('BETA');
        $this->assertCollections('seller-b.test', 'BETA');
        Cache::put('shared-key', 'beta');
        Queue::pushRaw('{"displayName":"beta"}');
        $orderB = $this->placeOrder('seller-b.test');
        if ($orderA !== null) {
            $this->assertNotSame($orderA, $orderB);
            $this->getJson('https://seller-b.test'.self::COMMERCE.'/orders/'.$orderA)->assertNotFound();
        }
        $this->postJson('https://seller-b.test/formaku/assistant/v1/exchange', ['code' => $code])->assertUnauthorized();
        $this->assertCatalog('seller-b.test', 'BETA');

        $this->bootMerchant(self::A, migrate: false);
        $this->withUnencryptedCookie($sessionCookie, $cookie->getValue());
        $this->assertCollections('seller-a.test', 'ALPHA');
        if ($orderB !== null) {
            $this->getJson('https://seller-a.test'.self::COMMERCE.'/orders/'.$orderB)->assertNotFound();
            $this->getJson('https://seller-a.test'.self::COMMERCE.'/orders/'.$orderA)->assertOk();
        }
        $token = $this->postJson('https://seller-a.test/formaku/assistant/v1/exchange', ['code' => $code])
            ->assertOk()->json('access_token');
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('https://seller-a.test/formaku/assistant/v1/connection', $headers)->assertOk();
        $this->assertCatalog('seller-a.test', 'ALPHA');
        $this->assertSame('alpha', Cache::get('shared-key'));
        $this->assertSame('ALPHA', Storage::disk('local')->get('same-name.txt'));
        $this->assertSame('{"displayName":"alpha"}', Queue::pop()?->getRawBody());

        $this->bootMerchant(self::B, migrate: false);
        $this->getJson('https://seller-b.test/formaku/assistant/v1/connection', $headers)->assertUnauthorized();
        $this->assertSame('beta', Cache::get('shared-key'));
        $this->assertSame('BETA', Storage::disk('local')->get('same-name.txt'));
        $this->assertSame('{"displayName":"beta"}', Queue::pop()?->getRawBody());
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

    private function assertDatabaseBoundary(string $own, string $other): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $ownDatabase = 'formaku_'.str_replace('-', '', $own);
        $otherDatabase = 'formaku_'.str_replace('-', '', $other);
        $identity = DB::selectOne("select current_database() as database, current_user as role, has_database_privilege(?, 'CONNECT') as can_connect", [$otherDatabase]);
        $this->assertSame($ownDatabase, $identity->database);
        $this->assertSame($ownDatabase, $identity->role);
        $this->assertFalse($identity->can_connect);
    }

    private function seedMerchant(string $host, string $sku): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => self::EMAIL, 'password' => self::PASSWORD]);
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

        Storage::disk('local')->put('same-name.txt', $sku);
        $media = Media::create([
            'owner_id' => $admin->id,
            'disk' => 'local',
            'path' => 'same-name.txt',
            'name' => 'same-name.txt',
            'mime' => 'text/plain',
            'visibility' => 'private',
            'size' => strlen($sku),
        ]);
        $this->assertSame(1, $media->id);

        if (class_exists(CommerceServiceProvider::class)) {
            $this->actingAs($admin);
            $this->postJson('https://'.$host.'/formaku/api/v1/commerce/admin/products', [
                'sku' => $sku, 'name' => $sku, 'price_minor' => 2900, 'available' => 1, 'published' => true,
            ])->assertCreated()->assertJsonPath('id', 1);
            auth()->forgetGuards();
        }
    }

    private function assertCollections(string $host, string $name): void
    {
        $this->getJson('https://'.$host.'/formaku/api/v1/collections')
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', $name);
    }

    private function placeOrder(string $host): ?string
    {
        if (! class_exists(CommerceServiceProvider::class)) {
            return null;
        }

        $this->assertTrue(Route::has('commerce.v2.place'));
        $api = 'https://'.$host.self::COMMERCE;
        $quote = $this->postJson($api.'/quotes', ['items' => [['variant_id' => 1, 'quantity' => 1]]])
            ->assertCreated()->json('id');
        $order = $this->postJson($api.'/orders', ['quote_id' => $quote], ['Idempotency-Key' => 'tenant-isolation'])
            ->assertCreated()->assertJsonPath('total_minor', '2900')->json('id');
        $this->getJson($api.'/orders/'.$order)->assertOk();

        return $order;
    }

    private function runtimeConfiguration(string $host): array
    {
        $platform = getenv('FORMAKU_PLATFORM_TEST_PATH');
        if ($platform !== false) {
            $process = new Process([PHP_BINARY, $platform.'/bin/formaku-platform', 'runtime-env', $platform.'/registry.example.json', $host]);
            $process->mustRun();

            return parse_ini_string($process->getOutput(), scanner_mode: INI_SCANNER_RAW);
        }

        $database = 'formaku_'.str_replace('-', '', $this->merchant);

        return [
            'FORMAKU_MERCHANT_ID' => $this->merchant,
            'FORMAKU_MERCHANT_HOST' => $host,
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $database,
        ];
    }

    private function assertCatalog(string $host, string $sku): void
    {
        if (class_exists(CommerceServiceProvider::class)) {
            $this->getJson('https://'.$host.'/formaku/api/v1/commerce/products')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', $sku);
        }

        if (class_exists(CatalogResource::class)) {
            $this->getJson('https://'.$host.'/formaku/api/v1/extensions/resources/'.CatalogResource::KEY)
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', $sku);
        }
    }
}
