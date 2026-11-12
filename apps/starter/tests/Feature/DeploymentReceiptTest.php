<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class DeploymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = 'https://api.github.com/repos/example/cms/actions';

    private const RUN = self::ACTIONS.'/runs/12345';

    private const DOWNLOAD = 'https://formaku.blob.core.windows.net/artifacts/receipt?signature=example';

    private const RELEASE = '12345678-1234-1234-1234-123456789abc';

    private const IMAGE = 'ghcr.io/example/cms@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        config(['formaku.github' => ['token' => 'test-token', 'repository' => 'example/cms']]);
        Http::preventStrayRequests();
    }

    public function test_only_builders_can_read_and_invalid_input_does_not_reach_github(): void
    {
        Http::fake();
        $this->getJson($this->url())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->getJson($this->url())->assertForbidden();
        $this->admin();
        foreach (['run_id' => '9007199254740992', 'release_id' => str_repeat('-', 36), 'image' => 'https://other.example/image'] as $key => $value) {
            $this->getJson($this->url([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        Http::assertNothingSent();
    }

    public function test_verified_receipt_is_read_only_and_does_not_forward_the_github_token(): void
    {
        $receipt = $this->receipt();
        $zip = $this->zip(['formaku-deployment.json' => json_encode($receipt + ['untrusted' => '<script>'])]);
        $this->fakeGitHub($zip, artifact: ['digest' => 'sha256:'.hash('sha256', $zip)]);
        $this->admin();

        $response = $this->getJson($this->url())->assertOk()->assertExactJson([
            'state' => 'verified',
            'run_id' => 12345,
            'run_attempt' => 2,
            'run_url' => 'https://github.com/example/cms/actions/runs/12345/attempts/2',
            'receipt' => $receipt,
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(0, Release::query()->count());
        Http::assertSentCount(4);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertSame(
                $request->url() !== self::DOWNLOAD,
                $request->hasHeader('Authorization', 'Bearer test-token'),
            );
        }
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::RUN.'/artifacts?')
            && str_contains($request->url(), 'name=formaku-deployment-2'));
    }

    public function test_pending_and_unmatched_runs_do_not_read_artifacts(): void
    {
        Http::fake([self::RUN => Http::sequence()
            ->push($this->workflowRun(['status' => 'in_progress']))
            ->push($this->workflowRun(['id' => 42]))
            ->push($this->workflowRun(['event' => 'push']))
            ->push($this->workflowRun(['head_branch' => 'development']))
            ->push($this->workflowRun(['path' => '.github/workflows/release.yml']))
            ->push($this->workflowRun(['display_title' => 'Formaku deploy '.self::RELEASE.'-extra']))]);
        $this->admin();
        $this->getJson($this->url())->assertOk()->assertJsonPath('state', 'pending')->assertJsonPath('receipt', null);
        for ($index = 0; $index < 5; $index++) {
            $this->getJson($this->url())->assertNotFound();
        }
        Http::assertSentCount(6);
        Http::assertNotSent(fn (Request $request) => $request->url() !== self::RUN);
    }

    public function test_artifact_must_be_unique_exact_unexpired_and_bounded(): void
    {
        Http::fake([
            self::RUN => Http::response($this->workflowRun()),
            self::RUN.'/artifacts*' => Http::sequence()
                ->push(['total_count' => 0, 'artifacts' => []])
                ->push(['total_count' => 2, 'artifacts' => [$this->artifact(), $this->artifact()]])
                ->push(['total_count' => 1, 'artifacts' => [$this->artifact(['name' => 'formaku-deployment-1'])]])
                ->push(['total_count' => 1, 'artifacts' => [$this->artifact(['expired' => true])]])
                ->push(['total_count' => 1, 'artifacts' => [$this->artifact(['size_in_bytes' => 16385])]]),
        ]);
        $this->admin();
        for ($index = 0; $index < 5; $index++) {
            $this->assertUnavailable();
        }
        Http::assertSentCount(10);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/artifacts/'));
    }

    public function test_redirect_requires_an_allowed_https_host_and_no_follow_up_redirect(): void
    {
        Http::fake([
            self::RUN => Http::response($this->workflowRun()),
            self::RUN.'/artifacts*' => Http::response(['total_count' => 1, 'artifacts' => [$this->artifact()]]),
            self::ACTIONS.'/artifacts/54/zip' => Http::sequence()
                ->push('', 302, ['Location' => 'https://formaku.blob.core.windows.net.other.example/receipt'])
                ->push('', 302, ['Location' => 'https://user@formaku.blob.core.windows.net/receipt'])
                ->push('', 302, ['Location' => 'https://formaku.blob.core.windows.net:444/receipt'])
                ->push('', 302, ['Location' => 'http://formaku.blob.core.windows.net/receipt'])
                ->push('', 302, ['Location' => self::DOWNLOAD]),
            self::DOWNLOAD => Http::response('', 302, ['Location' => 'https://other.example/receipt']),
        ]);
        $this->admin();
        for ($index = 0; $index < 5; $index++) {
            $this->assertUnavailable();
        }
        Http::assertSentCount(16);
        Http::assertSent(fn (Request $request) => $request->url() === self::DOWNLOAD && ! $request->hasHeader('Authorization'));
    }

    public function test_archive_and_receipt_content_are_verified_before_exposure(): void
    {
        $json = json_encode($this->receipt());
        $wrongImage = $this->receipt(['image' => 'ghcr.io/other/cms@sha256:'.str_repeat('c', 64)]);
        $wrongRelease = $this->receipt(['release' => '22222222-2222-2222-2222-222222222222']);
        $wrongBackup = $this->receipt(['host_status' => ['state' => 'failed', 'rollback' => 'restored', 'backup' => 'other']]);
        $wrongRollback = $this->receipt(['host_status' => ['state' => 'deployed', 'rollback' => 'restored', 'backup' => null]]);
        $valid = $this->zip(['formaku-deployment.json' => $json]);
        Http::fake([
            self::RUN => Http::response($this->workflowRun()),
            self::RUN.'/artifacts*' => Http::response(['total_count' => 1, 'artifacts' => [$this->artifact()]]),
            self::ACTIONS.'/artifacts/54/zip' => Http::response('', 302, ['Location' => self::DOWNLOAD]),
            self::DOWNLOAD => Http::sequence()
                ->push(str_repeat('A', 16385))
                ->push($this->zip(['../formaku-deployment.json' => $json]))
                ->push($this->zip(['formaku-deployment.json' => $json, 'other.json' => '{}']))
                ->push($this->zip(['formaku-deployment.json' => str_repeat(' ', 4097)]))
                ->push($this->zip(['formaku-deployment.json' => json_encode($wrongImage)]))
                ->push($this->zip(['formaku-deployment.json' => json_encode($wrongRelease)]))
                ->push($this->zip(['formaku-deployment.json' => json_encode($wrongBackup)]))
                ->push($this->zip(['formaku-deployment.json' => json_encode($wrongRollback)]))
                ->push(substr($valid, 0, 100)),
        ]);
        $this->admin();
        for ($index = 0; $index < 9; $index++) {
            $this->assertUnavailable();
        }
    }

    public function test_artifact_digest_mismatch_is_unavailable(): void
    {
        $this->fakeGitHub($this->zip(['formaku-deployment.json' => json_encode($this->receipt())]),
            artifact: ['digest' => 'sha256:'.str_repeat('0', 64)]);
        $this->admin();
        $this->assertUnavailable();
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function assertUnavailable(): void
    {
        $this->getJson($this->url())->assertOk()->assertJsonPath('state', 'unavailable')->assertJsonPath('receipt', null);
    }

    private function url(array $override = []): string
    {
        return '/formaku/api/v1/deployments/receipt?'.http_build_query(array_replace([
            'run_id' => 12345, 'release_id' => self::RELEASE, 'image' => self::IMAGE,
        ], $override));
    }

    private function workflowRun(array $override = []): array
    {
        return array_replace([
            'id' => 12345, 'run_attempt' => 2, 'event' => 'workflow_dispatch', 'head_branch' => 'main',
            'path' => '.github/workflows/deploy.yml', 'display_title' => 'Formaku deploy '.self::RELEASE,
            'status' => 'completed', 'conclusion' => 'failure',
        ], $override);
    }

    private function receipt(array $override = []): array
    {
        return array_replace([
            'format' => 1, 'release' => self::RELEASE, 'image' => self::IMAGE, 'ssh_exit_code' => 255,
            'host_status' => ['state' => 'failed', 'rollback' => 'restored', 'backup' => self::RELEASE],
        ], $override);
    }

    private function artifact(array $override = []): array
    {
        return array_replace([
            'name' => 'formaku-deployment-2', 'id' => 54, 'expired' => false, 'size_in_bytes' => 400,
            'workflow_run' => ['id' => 12345],
        ], $override);
    }

    private function fakeGitHub(string $zip, array $artifact = []): void
    {
        Http::fake([
            self::RUN => Http::response($this->workflowRun()),
            self::RUN.'/artifacts*' => Http::response(['total_count' => 1, 'artifacts' => [$this->artifact($artifact)]]),
            self::ACTIONS.'/artifacts/54/zip' => Http::response('', 302, ['Location' => self::DOWNLOAD]),
            self::DOWNLOAD => Http::response($zip),
        ]);
    }

    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'formaku-test-zip-');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
            foreach ($files as $name => $contents) {
                $this->assertTrue($zip->addFromString($name, $contents));
            }
            $this->assertTrue($zip->close());

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
