<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Jobs\GeneratePortalProject;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\PortalBlueprint;
use Formaku\Cms\Services\PortalProjectGeneration;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalProjectTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/formaku/api/v1/portal-projects';

    private function signIn(string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        return $user;
    }

    private function reference(): array
    {
        return $this->postJson(self::API, ['provider' => 'reference', 'brief' => 'Synthetic reference portal.'])->assertCreated()->json();
    }

    public function test_build_access_is_required_and_approval_creates_private_page_drafts_only_once(): void
    {
        $this->getJson(self::API)->assertUnauthorized();
        $this->signIn('editor');
        $this->postJson(self::API, [])->assertForbidden();
        $this->signIn('admin');
        $project = $this->reference();
        $url = self::API.'/'.$project['id'];
        $this->assertDatabaseCount('formaku_collections', 0);
        $proposal = $project['proposal'];
        $proposal['pages'][0]['title'] = 'A reviewed title';
        $this->putJson($url, ['version' => 1, 'proposal' => $proposal])->assertOk()->assertJsonPath('version', 2);
        $this->get($url.'/preview/0')->assertOk()->assertSee('A reviewed title')->assertHeader('X-Robots-Tag', 'noindex');
        $this->get($url.'/preview/1')->assertNotFound();
        $this->get($url.'/preview-form')->assertOk()->assertSee('Sprawdź próbne dane')->assertSee('Preferowany termin')->assertDontSee('action="/portal/requests"', false);
        $this->postJson($url.'/preview-form', ['title' => 'Test', 'description' => 'Synthetic.', 'desired_date' => 'invalid date'])->assertUnprocessable();
        $this->post($url.'/preview-form', ['title' => 'Test', 'description' => 'Synthetic.', 'desired_date' => '2027-01-18'])->assertOk()->assertSee('Próbne dane przeszły walidację.');
        $this->assertDatabaseCount('formaku_records', 0);
        $this->get('/p/'.$proposal['pages'][0]['slug'])->assertNotFound();
        $this->getJson($url.'/changes')->assertOk()->assertJsonPath('stale', false)->assertJsonFragment(['after' => 'A reviewed title']);
        $this->postJson($url.'/apply', ['version' => 1])->assertConflict();
        $applied = $this->postJson($url.'/apply', ['version' => 2])->assertOk()->assertJsonPath('state', 'applied')->json();
        $this->postJson($url.'/apply', ['version' => 2])->assertOk()->assertJsonPath('pages.0.id', $applied['pages'][0]['id']);
        $this->assertDatabaseCount('formaku_records', 1);
        $page = $applied['pages'][0];
        $this->get($page['preview_url'])->assertOk()->assertSee('A reviewed title')->assertSee('href="/portal"', false);
        $this->get('/p/'.$page['slug'])->assertNotFound();
        $this->postJson('/formaku/api/v1/records/'.$page['id'].'/publish', ['version' => $page['version']])->assertOk();
        $this->get('/p/'.$page['slug'])->assertOk()->assertSee('A reviewed title');
        $this->getJson(self::API)->assertOk()->assertJsonPath('projects.0.id', $project['id']);
        $this->getJson($url)->assertOk()->assertJsonPath('brief', 'Synthetic reference portal.');
    }

    public function test_live_schema_changes_require_review_and_a_conflicting_address_rolls_back_the_project(): void
    {
        $this->signIn('admin');
        $first = $this->reference();
        $second = $this->reference();
        $this->postJson(self::API.'/'.$first['id'].'/apply', ['version' => 1])->assertOk();
        $url = self::API.'/'.$second['id'];
        $proposal = $second['proposal'];
        $proposal['fields'][] = ['key' => 'extra_field', 'label' => 'Extra', 'type' => 'text', 'options' => []];
        $this->putJson($url, ['version' => 1, 'proposal' => $proposal])->assertOk();
        $this->getJson($url.'/changes')->assertOk()->assertJsonPath('stale', true);
        $this->postJson($url.'/apply', ['version' => 2])->assertConflict();
        $this->postJson($url.'/refresh', ['version' => 2])->assertOk()->assertJsonPath('version', 3);
        $this->postJson($url.'/apply', ['version' => 3])->assertConflict();
        $this->assertNotContains('extra_field', array_column(Collection::where('key', 'requests')->firstOrFail()->definition['fields'], 'key'));
        $this->assertDatabaseCount('formaku_records', 1);
        $proposal['pages'][0]['slug'] = 'second-portal';
        $this->putJson($url, ['version' => 3, 'proposal' => $proposal])->assertOk();
        $this->postJson($url.'/apply', ['version' => 4])->assertOk();
        $this->assertDatabaseCount('formaku_records', 2);
    }

    public function test_request_inputs_cannot_redefine_staff_fields_or_discard_an_active_workflow(): void
    {
        $admin = $this->signIn('admin');
        $this->artisan('formaku:demo')->assertSuccessful();
        $requests = Collection::where('key', 'requests')->firstOrFail();
        $record = app(RecordService::class)->save($requests, null, ['title' => 'Existing request', 'description' => 'Synthetic.'], 0, $admin->id);
        $project = $this->reference();
        $url = self::API.'/'.$project['id'];
        $proposal = $project['proposal'];
        $proposal['fields'][0]['key'] = 'customer_reply';
        $this->putJson($url, ['version' => 1, 'proposal' => $proposal])->assertUnprocessable();
        $proposal = $project['proposal'];
        $proposal['states'] = ['start', 'end'];
        $this->putJson($url, ['version' => 1, 'proposal' => $proposal])->assertOk();
        $this->postJson($url.'/apply', ['version' => 2])->assertConflict();
        $this->assertSame('new', Record::findOrFail($record->id)->state);
        $this->putJson($url, ['version' => 2, 'proposal' => $project['proposal']])->assertOk();
        $record->update(['state' => 'legacy_unlisted']);
        $this->postJson($url.'/apply', ['version' => 3])->assertConflict();
        $this->assertSame('legacy_unlisted', $record->fresh()->state);
    }

    public function test_other_builders_do_not_open_change_or_apply_a_private_brief(): void
    {
        $this->signIn('admin');
        $project = $this->reference();
        $this->signIn('admin');
        $url = self::API.'/'.$project['id'];
        $this->getJson(self::API)->assertOk()->assertJsonCount(0, 'projects');
        $this->getJson($url)->assertNotFound();
        $this->get($url.'/preview/0')->assertNotFound();
        $this->getJson($url.'/changes')->assertNotFound();
        $this->putJson($url, ['version' => 1, 'proposal' => $project['proposal']])->assertNotFound();
        $this->postJson($url.'/apply', ['version' => 1])->assertNotFound();
        $this->assertDatabaseCount('formaku_collections', 0);
    }

    public function test_an_invalid_model_proposal_keeps_the_brief_for_recovery_without_activating_any_schema(): void
    {
        $this->signIn('admin');
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => '{"questions":[]}']]]],
            'usage' => ['input_tokens' => 25, 'output_tokens' => 6],
        ])]);
        config(['formaku.ai.openai' => ['key' => 'synthetic', 'model' => 'test-model'], 'formaku.portal_ai_rates.openai' => ['input' => 100, 'output' => 1000]]);
        $project = $this->postJson(self::API, ['provider' => 'openai', 'brief' => 'Source retained.', 'materials' => 'Approved.', 'budget_cents' => 20])
            ->assertAccepted()->json();
        (new GeneratePortalProject($project['id']))->handle(app(PortalProjectGeneration::class), app(PortalBlueprint::class));
        $url = self::API.'/'.$project['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('state', 'failed')->assertJsonPath('proposal', null)
            ->assertJsonPath('brief', 'Source retained.')->assertJsonPath('materials', 'Approved.')->assertJsonPath('cost_cents', 1);
        $this->postJson($url.'/apply', ['version' => 1])->assertConflict();
        $this->assertDatabaseCount('formaku_collections', 0);
    }

    public static function providers(): array
    {
        return [['openai'], ['gemini']];
    }

    #[DataProvider('providers')]
    public function test_queue_uses_approved_inputs_only_and_reports_measured_cost(string $provider): void
    {
        $this->signIn('admin');
        $this->artisan('formaku:demo')->assertSuccessful();
        Queue::fake();
        config(['formaku.ai.'.$provider => ['key' => 'synthetic', 'model' => 'test-model']]);
        $this->getJson('/formaku/api/v1/bootstrap')->assertOk()
            ->assertJsonPath('providers.'.$provider.'.configured', true)->assertJsonPath('providers.'.$provider.'.portal_configured', false);
        config(['formaku.portal_ai_rates.'.$provider => ['input' => 100, 'output' => 1000]]);
        $this->getJson('/formaku/api/v1/bootstrap')->assertOk()->assertJsonPath('providers.'.$provider.'.portal_configured', true);
        $payload = ['provider' => $provider, 'brief' => 'Approved brief', 'materials' => 'Approved public material', 'budget_cents' => 0];
        $this->postJson(self::API, $payload)->assertUnprocessable();
        Queue::assertNothingPushed();
        $payload['budget_cents'] = 25;
        $project = $this->postJson(self::API, $payload)->assertAccepted()->assertJsonPath('state', 'queued')->json();
        Queue::assertPushed(GeneratePortalProject::class);
        $output = json_encode(app(PortalBlueprint::class)->reference(), JSON_THROW_ON_ERROR);
        $fixture = $provider === 'openai' ? [
            'status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => $output]]]],
            'usage' => ['input_tokens' => 3000, 'output_tokens' => 1000],
        ] : [
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => $output]]]]],
            'usageMetadata' => ['promptTokenCount' => 3000, 'totalTokenCount' => 4000],
        ];
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response($fixture)]);
        $job = new GeneratePortalProject($project['id']);
        $job->handle(app(PortalProjectGeneration::class), app(PortalBlueprint::class));
        $job->handle(app(PortalProjectGeneration::class), app(PortalBlueprint::class));
        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($provider): bool {
            $prompt = $provider === 'openai' ? $request['input'] : $request['contents'][0]['parts'][0]['text'];

            return json_decode($prompt, true) === ['brief' => 'Approved brief', 'approved_materials' => 'Approved public material'];
        });
        $this->getJson(self::API.'/'.$project['id'])->assertOk()->assertJsonPath('state', 'review')->assertJsonPath('cost_cents', 2);
        $this->assertNull(PortalProject::findOrFail($project['id'])->pages);
    }
}
