<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubBuildStatusTest extends TestCase
{
    use RefreshDatabase;

    private const RELEASES = '/formaku/api/v1/releases';

    private const ACTIONS = 'https://api.github.com/repos/example/cms/actions';

    protected function setUp(): void
    {
        parent::setUp();

        config(['formaku.github' => ['token' => 'synthetic-test-token', 'repository' => 'example/cms', 'workflow' => 'release.yml']]);
        Http::preventStrayRequests();
    }

    public function test_dispatch_records_the_workflow_and_keeps_the_build_contract(): void
    {
        Http::fake([self::ACTIONS.'/workflows/release.yml/dispatches' => Http::response(status: 204)]);
        $commit = str_repeat('a', 40);

        $id = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(self::RELEASES, ['commit' => $commit])
            ->assertSuccessful()
            ->assertJsonPath('state', 'requested')
            ->assertJsonPath('manifest.workflow', 'release.yml')
            ->json('id');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() === [
            'ref' => 'main',
            'inputs' => ['release_id' => $id, 'source_commit' => $commit],
        ]);
    }

    public function test_refresh_requires_build_permission_and_the_configured_repository(): void
    {
        Http::fake();
        $release = $this->legacyRelease(repository: 'other/cms');
        $url = self::RELEASES.'/'.$release->id.'/refresh';

        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson($url)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_refresh_finds_the_exact_manual_run_for_a_legacy_release_across_pages(): void
    {
        $release = $this->legacyRelease(state: 'unknown');
        $expected = $this->workflowRun($release, status: 'queued');
        $unrelated = array_replace($expected, ['display_title' => 'Formaku '.$release->id.'-extra']);
        $otherEvent = array_replace($expected, ['event' => 'push']);
        $otherBranch = array_replace($expected, ['head_branch' => 'beta']);
        Http::fake([self::ACTIONS.'/workflows/release.yml/runs*' => Http::sequence()
            ->push(['workflow_runs' => array_fill(0, 100, $unrelated)])
            ->push(['workflow_runs' => [$otherEvent, $otherBranch, $expected]])]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(self::RELEASES.'/'.$release->id.'/refresh')
            ->assertOk()
            ->assertJsonPath('state', 'queued')
            ->assertJsonPath('run_id', '10723456789')
            ->assertJsonPath('run_url', 'https://github.com/example/cms/actions/runs/10723456789');

        $this->assertDatabaseHas('formaku_releases', ['id' => $release->id, 'state' => 'queued', 'run_id' => '10723456789']);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET' && ($query['branch'] ?? null) === 'main'
                && ($query['event'] ?? null) === 'workflow_dispatch' && ($query['page'] ?? null) === '2'
                && ! array_key_exists('head_sha', $query);
        });
    }

    public function test_refresh_preserves_a_missing_build_and_returns_502_for_provider_failures(): void
    {
        $release = $this->legacyRelease();
        $runList = self::ACTIONS.'/workflows/pinned.yml/runs*';
        $release->update(['manifest' => $release->manifest + ['workflow' => 'pinned.yml']]);
        Http::fake([$runList => Http::sequence()
            ->push(['workflow_runs' => []])
            ->push(['message' => 'secret response body'], 403)
            ->pushFailedConnection()]);
        $url = self::RELEASES.'/'.$release->id.'/refresh';

        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson($url)
            ->assertOk()->assertJsonPath('state', 'requested')->assertJsonPath('run_id', null);
        $this->postJson($url)->assertStatus(502)->assertDontSee('secret response body');
        $this->postJson($url)->assertStatus(502);
        $this->assertDatabaseHas('formaku_releases', ['id' => $release->id, 'state' => 'requested', 'run_id' => null]);
    }

    public function test_refresh_uses_the_linked_run_and_keeps_the_newest_attempt_and_progress(): void
    {
        $release = $this->legacyRelease();
        $release->update(['run_id' => '10723456789', 'state' => 'queued', 'manifest' => $release->manifest + ['run_attempt' => 1]]);
        Http::fake([self::ACTIONS.'/runs/10723456789' => Http::sequence()
            ->push($this->workflowRun($release, status: 'in_progress'))
            ->push($this->workflowRun($release, status: 'queued'))
            ->push($this->workflowRun($release, conclusion: 'failure'))
            ->push($this->workflowRun($release, status: 'queued', attempt: 2))
            ->push($this->workflowRun($release, conclusion: 'cancelled', attempt: 2))
            ->push($this->workflowRun($release, conclusion: 'success', attempt: 3))
            ->push($this->workflowRun($release, conclusion: 'cancelled', attempt: 2))]);
        $url = self::RELEASES.'/'.$release->id.'/refresh';

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['running', 'running', 'failed', 'queued', 'cancelled', 'succeeded', 'succeeded'] as $state) {
            $this->postJson($url)->assertOk()->assertJsonPath('state', $state);
        }

        $this->assertDatabaseHas('formaku_releases', ['id' => $release->id, 'state' => 'succeeded', 'run_id' => '10723456789']);
        Http::assertSentCount(7);
    }

    private function legacyRelease(string $state = 'requested', string $repository = 'example/cms'): Release
    {
        return Release::create([
            'id' => (string) Str::uuid(),
            'commit' => str_repeat('b', 40),
            'state' => $state,
            'manifest' => ['format' => 1, 'mode' => 'build', 'repository' => $repository],
        ]);
    }

    private function workflowRun(Release $release, string $status = 'completed', ?string $conclusion = null, int $attempt = 1): array
    {
        return [
            'id' => 10723456789,
            'event' => 'workflow_dispatch',
            'head_branch' => 'main',
            'head_sha' => str_repeat('c', 40),
            'display_title' => 'Formaku '.$release->id,
            'status' => $status,
            'conclusion' => $conclusion,
            'run_attempt' => $attempt,
            'html_url' => 'https://untrusted.example/wrong-build',
        ];
    }
}
