<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class RuntimeObservationTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/formaku/api/v1/deployments/runtime-observation';

    private const ACTIONS = 'https://api.github.com/repos/example/cms/actions';

    private const RUNS = self::ACTIONS.'/workflows/runtime-status.yml/runs';

    private const ATTEMPT = self::ACTIONS.'/runs/12345/attempts/2';

    private const ARTIFACTS = self::ACTIONS.'/runs/12345/artifacts';

    private const ZIP = self::ACTIONS.'/artifacts/54/zip';

    private const DOWNLOAD = 'https://example.blob.core.windows.net/artifacts/observation?signature=synthetic';

    protected function setUp(): void
    {
        parent::setUp();

        config(['formaku.github' => ['token' => 'synthetic-token', 'repository' => 'example/cms']]);
        Http::preventStrayRequests();
    }

    public function test_only_builders_can_read_runtime_observations(): void
    {
        $this->getJson(self::API)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->getJson(self::API)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_bootstrap_only_exposes_the_configured_runtime_workflow_to_builders(): void
    {
        $bootstrap = '/formaku/api/v1/bootstrap';
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->getJson($bootstrap)->assertOk()->assertJsonMissingPath('runtimeWorkflowUrl');

        $this->admin();
        $url = 'https://github.com/example/cms/actions/workflows/runtime-status.yml';
        $this->getJson($bootstrap)->assertOk()->assertJsonPath('runtimeWorkflowUrl', $url)->assertDontSee('synthetic-token');

        config(['formaku.github.token' => null]);
        $this->getJson($bootstrap)->assertOk()->assertJsonPath('runtimeWorkflowUrl', $url);
        foreach ([null, ['example/cms'], 'https://github.com/example/cms', 'example/cms?secret=value', '../cms', 'example/..'] as $repository) {
            config(['formaku.github.repository' => $repository]);
            $this->getJson($bootstrap)->assertOk()->assertJsonMissingPath('runtimeWorkflowUrl');
        }

        Http::assertNothingSent();
    }

    public function test_no_dispatch_and_pending_latest_attempt_are_distinct(): void
    {
        Http::fake([
            self::RUNS.'?*' => Http::sequence()
                ->push(['total_count' => 0, 'workflow_runs' => []])
                ->push($this->runList()),
            self::ATTEMPT => Http::response($this->workflowRun(['status' => 'in_progress'])),
        ]);
        $this->admin();
        $this->getJson(self::API)->assertOk()->assertExactJson(['state' => 'none']);
        $this->getJson(self::API)->assertOk()->assertExactJson($this->responseShape('pending'));
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/artifacts'));
    }

    public function test_observation_binds_latest_attempt_and_never_sends_the_token_to_storage(): void
    {
        $image = 'ghcr.io/example/cms@sha256:'.str_repeat('a', 64);
        $host = ['state' => 'verified', 'image' => $image];
        $this->fakeArtifact($this->observation(['host' => $host]));
        $this->admin();
        $response = $this->getJson(self::API.'?run_id=12344')->assertOk()->assertExactJson(
            $this->responseShape('observed') + ['observation' => ['recorded_at' => '2026-11-13T00:00:00Z', 'host' => $host]],
        );
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(0, Release::query()->count());
        Http::assertSentCount(5);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method());
            $this->assertSame($request->url() !== self::DOWNLOAD, $request->hasHeader('Authorization', 'Bearer synthetic-token'));
        }
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::RUNS.'?')
            && str_contains($request->url(), 'branch=main&event=workflow_dispatch&per_page=1'));
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), self::ARTIFACTS.'?')
            && str_contains($request->url(), 'name=formaku-runtime-2'));
    }

    public function test_latest_attempt_with_no_artifact_never_falls_back_to_older_run(): void
    {
        Http::fake([
            self::RUNS.'?*' => Http::response($this->runList()),
            self::ATTEMPT => Http::response($this->workflowRun()),
            self::ARTIFACTS.'?*' => Http::response(['total_count' => 0, 'artifacts' => []]),
        ]);
        $this->admin();
        $this->getJson(self::API)->assertOk()->assertExactJson($this->responseShape('unavailable'));
        Http::assertSentCount(3);
    }

    public function test_wrong_attempt_identity_never_reaches_artifact_endpoint(): void
    {
        Http::fake([
            self::RUNS.'?*' => Http::response($this->runList()),
            self::ATTEMPT => Http::sequence()
                ->push($this->workflowRun(['id' => 12344]))
                ->push($this->workflowRun(['run_attempt' => 1]))
                ->push($this->workflowRun(['repository' => ['full_name' => 'other/cms']]))
                ->push($this->workflowRun(['path' => '.github/workflows/deploy.yml']))
                ->push($this->workflowRun(['head_branch' => 'other']))
                ->push($this->workflowRun(['event' => 'push'])),
        ]);
        $this->admin();
        for ($i = 0; $i < 6; $i++) {
            $this->getJson(self::API)->assertStatus(502);
        }
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/artifacts'));
    }

    public function test_artifact_requires_digest_and_valid_payload_bound_to_run(): void
    {
        $this->admin();
        foreach ([
            [[], ['digest' => null]],
            [[], ['digest' => 'sha256:'.str_repeat('0', 64)]],
            [[], ['name' => 'formaku-runtime-1']],
            [['run_id' => 12344], []],
            [['run_attempt' => 1], []],
            [['recorded_at' => '2026-02-30T00:00:00Z'], []],
            [['recorded_at' => '2026-11-13T00:00:00+00:00'], []],
            [['host' => ['state' => 'verified']], []],
            [['host' => ['state' => 'verified', 'image' => 'ghcr.io/example/cms:latest']], []],
            [['host' => ['state' => 'mismatch', 'image' => 'unused']], []],
            [['extra' => 'unknown'], []],
        ] as [$payload, $artifact]) {
            $this->fakeArtifact($this->observation($payload), $artifact);
            $this->getJson(self::API)->assertOk()->assertExactJson($this->responseShape('unavailable'));
        }
        $host = ['state' => 'unavailable'];
        $this->fakeArtifact($this->observation(['host' => $host]));
        $this->getJson(self::API)->assertOk()->assertExactJson(
            $this->responseShape('observed') + ['observation' => ['recorded_at' => '2026-11-13T00:00:00Z', 'host' => $host]],
        );
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function responseShape(string $state): array
    {
        return [
            'state' => $state, 'run_id' => 12345, 'run_attempt' => 2,
            'run_url' => 'https://github.com/example/cms/actions/runs/12345/attempts/2',
        ];
    }

    private function runList(): array
    {
        return ['total_count' => 2, 'workflow_runs' => [$this->workflowRun()]];
    }

    private function workflowRun(array $override = []): array
    {
        return array_replace([
            'id' => 12345, 'run_attempt' => 2, 'event' => 'workflow_dispatch', 'head_branch' => 'main',
            'path' => '.github/workflows/runtime-status.yml', 'repository' => ['full_name' => 'example/cms'],
            'status' => 'completed', 'conclusion' => 'failure',
        ], $override);
    }

    private function observation(array $override = []): array
    {
        return array_replace([
            'format' => 1, 'run_id' => 12345, 'run_attempt' => 2,
            'recorded_at' => '2026-11-13T00:00:00Z', 'host' => ['state' => 'transitioning'],
        ], $override);
    }

    private function fakeArtifact(array $payload, array $override = []): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        $path = tempnam(sys_get_temp_dir(), 'formaku-test-zip-');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
            $this->assertTrue($zip->addFromString('formaku-runtime.json', json_encode($payload, JSON_THROW_ON_ERROR)));
            $this->assertTrue($zip->close());
            $archive = file_get_contents($path);
        } finally {
            unlink($path);
        }
        $artifact = array_replace([
            'name' => 'formaku-runtime-2', 'id' => 54, 'expired' => false, 'size_in_bytes' => strlen($archive),
            'digest' => 'sha256:'.hash('sha256', $archive), 'workflow_run' => ['id' => 12345],
        ], $override);
        Http::fake([
            self::RUNS.'?*' => Http::response($this->runList()),
            self::ATTEMPT => Http::response($this->workflowRun()),
            self::ARTIFACTS.'?*' => Http::response(['total_count' => 1, 'artifacts' => [$artifact]]),
            self::ZIP => Http::response('', 302, ['Location' => self::DOWNLOAD]),
            self::DOWNLOAD => Http::response($archive),
        ]);
    }
}
