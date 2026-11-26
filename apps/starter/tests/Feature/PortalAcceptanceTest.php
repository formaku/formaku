<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\PortalAcceptance;
use Formaku\Cms\Services\PortalBlueprint;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_explicitly_shared_saved_answer_reaches_the_request_owner(): void
    {
        $blueprint = app(PortalBlueprint::class);
        $schema = $blueprint->requestsDefinition($blueprint->reference());
        $requests = Collection::create(['key' => 'requests', 'name' => 'Zapytania', 'kind' => 'data', 'definition' => $schema]);
        $owner = User::factory()->create(['role' => 'client']);
        $other = User::factory()->create(['role' => 'client']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($owner)->post('/portal/requests', [
            'title' => 'Usługa', 'description' => 'Wycena', 'service' => 'Transport', 'desired_date' => '2030-05-20',
            'internal_note' => 'Injected note', 'customer_reply' => 'Injected reply',
        ])->assertRedirect('/portal');
        $record = Record::firstOrFail();
        $path = '/portal/requests/'.$record->id;
        $share = '/formaku/api/v1/records/'.$record->id.'/share-reply';
        $this->getJson($path)->assertOk()->assertJsonPath('data.service', 'Transport')->assertJsonMissingPath('data.customer_reply');
        $this->postJson($share, ['version' => $record->version])->assertForbidden();
        $this->actingAs($other)->getJson($path)->assertNotFound();

        $data = $record->draft->data + ['customer_reply' => 'Odpowiedź A', 'internal_note' => 'Prywatna notatka QA'];
        $save = '/formaku/api/v1/collections/'.$requests->id.'/records';
        $version = $this->actingAs($editor)->postJson($save, ['id' => $record->id, 'version' => $record->version, 'data' => $data])
            ->assertOk()->json('version');
        $this->actingAs($owner)->getJson($path)->assertJsonMissingPath('data.customer_reply')->assertJsonMissingPath('data.internal_note');
        $this->actingAs($editor)->postJson($share, ['version' => $version - 1])->assertConflict();
        $this->postJson($share, ['version' => $version])->assertOk();
        $this->postJson($share, ['version' => $version])->assertOk();
        $data['customer_reply'] = 'Wersja robocza B';
        $this->postJson($save, ['id' => $record->id, 'version' => $version, 'data' => $data])->assertOk();
        $this->actingAs($owner)->get($path)->assertOk()->assertSee('Odpowiedź A')->assertDontSee('Wersja robocza B')->assertDontSee('Prywatna notatka QA');
        $this->getJson($path)->assertJsonPath('data.customer_reply', 'Odpowiedź A')->assertJsonMissingPath('data.internal_note');
        $this->actingAs($other)->get($path)->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);
        $definition = $requests->only('id', 'key', 'name', 'kind', 'version', 'definition');
        unset($definition['definition']['portal_reply']);
        $this->actingAs($admin)->postJson('/formaku/api/v1/collections', $definition)->assertOk()->assertJsonPath('definition.portal_reply', true);
        $definition['version']++;
        $definition['definition']['portal_reply'] = false;
        $this->postJson('/formaku/api/v1/collections', $definition)->assertUnprocessable()->assertJsonValidationErrors('definition.portal_reply');
        $this->actingAs($owner)->getJson($path)->assertJsonPath('data.customer_reply', 'Odpowiedź A')->assertDontSee('Wersja robocza B');
        $this->assertSame(1, \DB::table('formaku_events')->where('type', 'portal_reply_shared')->count());
    }

    public function test_checks_saved_page_and_marks_prior_browser_receipt_stale_after_revision(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $blueprint = app(PortalBlueprint::class);
        $proposal = $blueprint->reference();
        Collection::create(['key' => 'requests', 'name' => 'Zapytania', 'kind' => 'data', 'definition' => $blueprint->requestsDefinition($proposal)]);
        $pages = Collection::create(['key' => 'agency_pages', 'name' => 'Oferta', 'kind' => 'content', 'definition' => $blueprint->pageDefinition()]);
        $data = $blueprint->pageData($proposal['pages'][0]);
        $service = app(RecordService::class);
        $page = $service->save($pages, null, $data, 0, $admin->id);
        $project = PortalProject::create(['actor_id' => $admin->id, 'provider' => 'reference', 'brief' => '', 'materials' => '', 'state' => 'applied',
            'snapshot' => [], 'pages' => [['id' => $page->id]], 'proposal' => $proposal]);
        $url = '/formaku/api/v1/agency/check?project_id='.$project->id;
        $checks = app(PortalAcceptance::class);
        // The per-test application key distinguishes receipts in successive suites.
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $path = $checks->receiptPath((string) $project->id);
        try {
            $this->actingAs(User::factory()->create(['role' => 'client']))->getJson($url)->assertForbidden();
            $this->actingAs(User::factory()->create(['role' => 'admin']))->getJson($url)->assertNotFound();
            $result = $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('static_passed', true)
                ->assertJsonPath('passed', false)->json();
            $this->assertContains($result['browser']['status'], ['not_run', 'stale']);
            $this->postJson($url)->assertUnprocessable();
            $checks->record((string) $project->id, $result['fingerprint'], 'passed');
            $this->getJson($url)->assertJsonPath('passed', true);
            $data['blocks'] = [];
            $service->save($pages, $page, $data, $page->version, $admin->id);
            $this->getJson($url)->assertOk()->assertJsonPath('static_passed', false)->assertJsonPath('browser.status', 'stale');
            $this->assertNull($page->fresh()->published_id);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
