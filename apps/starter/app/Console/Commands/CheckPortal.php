<?php

namespace App\Console\Commands;

use App\Models\User;
use Formaku\Cms\Models\AgencyDeployment;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\PortalAcceptance;
use Formaku\Cms\Services\RecordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class CheckPortal extends Command
{
    protected $signature = 'formaku:check-portal {project? : Applied project ID} {--installed : Verify the imported package}';

    protected $description = 'Run the B2B acceptance scenario in Chromium against a private copy of the disposable test installation';

    public function handle(PortalAcceptance $checks): int
    {
        $id = filter_var($this->argument('project'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $checks->available() || ((bool) $id === (bool) $this->option('installed')) || ($this->argument('project') !== null && $id === false)) {
            $this->error('Choose an applied project ID or --installed. Run in the starter source checkout with APP_ENV=testing, a dedicated formaku-e2e.sqlite database, npm dependencies and Chromium installed.');

            return self::FAILURE;
        }
        $scope = $id ? (string) $id : 'installed';
        $report = $checks->report($id ?: null);
        if (! $report['static_passed']) {
            $this->error('The portal configuration checks have failed.');

            return self::FAILURE;
        }
        $root = dirname(base_path(), 2);
        $temp = sys_get_temp_dir().'/formaku-portal-'.Str::uuid();
        File::makeDirectory($temp, 0700);
        $snapshot = $temp.'/snapshot.sqlite';
        $original = DB::getDefaultConnection();
        $server = null;
        $stage = 'Przygotowanie środowiska testowego';
        $checks->record($scope, $report['fingerprint'], 'running', $stage);
        try {
            DB::statement('VACUUM INTO ?', [$snapshot]);
            $settings = config('database.connections.sqlite');
            $settings['database'] = $snapshot;
            $settings['url'] = null;
            config(['database.connections.acceptance_snapshot' => $settings]);
            DB::setDefaultConnection('acceptance_snapshot');
            $input = $this->prepareSnapshot($id ?: null);
            DB::setDefaultConnection($original);
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($socket === false) {
                throw new RuntimeException('A loopback port is required.');
            }
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $base = 'http://'.$address;
            $server = new Process([PHP_BINARY, '-S', $address, '-t', base_path('public'), $root.'/tests/browser/router.php'], $root, [
                'APP_ENV' => 'testing', 'APP_URL' => $base, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $snapshot, 'DB_URL' => '',
                'APP_CONFIG_CACHE' => $temp.'/config.php', 'SESSION_DRIVER' => 'database', 'SESSION_CONNECTION' => 'sqlite', 'CACHE_STORE' => 'array',
                'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array',
            ]);
            $server->disableOutput()->start();
            $suite = new Process(['node', $root.'/tests/acceptance/b2b-portal.mjs'], $root, [], json_encode($input + ['base' => $base], JSON_THROW_ON_ERROR), 100);
            $suite->run(function (string $type, string $output) use (&$stage): void {
                if ($type === Process::OUT && preg_match('/^STEP (.{1,160})$/mu', trim($output), $matches)) {
                    $stage = $matches[1];
                    $this->line($stage);
                }
                if ($type === Process::ERR) {
                    $this->getOutput()->write($output);
                }
            });
            if (! $suite->isSuccessful()) {
                throw new RuntimeException('Playwright returned a failed result.');
            }
            $checks->record($scope, $report['fingerprint'], 'passed', 'Chromium: dwa konta klientów, pracownik, zgłoszenie, odpowiedź, izolacja i dostępność.');
            $this->info('B2B portal acceptance passed.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            DB::setDefaultConnection($original);
            $checks->record($scope, $report['fingerprint'], 'failed', $stage);
            $this->error($stage.' — '.$error->getMessage());

            return self::FAILURE;
        } finally {
            $server?->stop();
            DB::setDefaultConnection($original);
            DB::purge('acceptance_snapshot');
            File::deleteDirectory($temp);
        }
    }

    private function prepareSnapshot(?int $id): array
    {
        $pageIds = $id === null
            ? AgencyDeployment::findOrFail(DB::table('formaku_agency_package_head')->where('id', 1)->value('deployment_id'))->page_ids
            : array_column(PortalProject::findOrFail($id)->pages, 'id');
        $password = Str::random(36);
        $accounts = [];
        foreach (['owner' => 'client', 'other' => 'client', 'staff' => 'editor'] as $key => $role) {
            $user = new User(['name' => 'QA '.$key, 'email' => $key.'-'.Str::uuid().'@example.invalid', 'password' => $password]);
            $user->role = $role;
            $user->email_verified_at = now();
            $user->save();
            $accounts[$key] = ['id' => $user->id, 'email' => $user->email];
        }
        $paths = [];
        foreach ($pageIds as $pageId) {
            $page = Record::findOrFail($pageId);
            $page = app(RecordService::class)->publish($page, $page->version, $accounts['staff']['id']);
            $paths[] = '/p/'.$page->published_slug;
        }
        $definition = Collection::where('key', 'requests')->firstOrFail()->definition;

        return ['password' => $password, 'accounts' => $accounts, 'pages' => $paths, 'prefix' => '/'.trim(config('formaku.prefix'), '/'),
            'labels' => array_column($definition['fields'], 'label', 'key'), 'nextState' => collect($definition['transitions'])->firstWhere('from', $definition['states'][0])['to'] ?? null];
    }
}
