<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Contracts\BlockType;
use Formaku\Cms\Models\AgencyDeployment;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\PortalBlueprint;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgencyPackageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/formaku/api/v1/agency/packages';

    public function test_export_keeps_structure_and_test_suite_without_customer_content_and_only_builders_can_export(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $project = $this->source($admin->id);
        DB::table('formaku_events')->insert(['type' => 'reply', 'payload' => '{"text":"CONFIDENTIAL REPLY"}']);
        $this->postJson(self::BASE.'/export', ['proposal_id' => $project->id, 'revision' => 1])->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->postJson(self::BASE.'/export', ['proposal_id' => $project->id, 'revision' => 1])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->postJson(self::BASE.'/export', ['proposal_id' => $project->id, 'revision' => 7])->assertNotFound();
        $this->actingAs($admin);
        $manifest = $this->postJson(self::BASE.'/export', ['proposal_id' => $project->id, 'revision' => 7])
            ->assertOk()->assertJsonPath('revision', 7)->assertJsonPath('acceptance.id', 'b2b-rfq-v1')->json();
        $this->assertStringNotContainsString('CONFIDENTIAL', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertSame(['format', 'version', 'revision', 'pages', 'fields', 'states', 'workflow', 'acceptance'], array_keys($manifest));
        $this->assertSame(['hero', 'cta'], $manifest['pages'][0]['blocks']);
        $this->assertSame(['Opcja 1', 'Opcja 2'], $manifest['fields'][0]['options']);
        $project->update(['state' => 'review']);
        $this->postJson(self::BASE.'/export', ['proposal_id' => $project->id, 'revision' => 7])->assertConflict();
    }

    public function test_import_and_upgrade_preserve_live_requests_and_local_page_edits_and_restore_configuration(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $manifest = $this->export();
        $target = ['name' => 'Nowa firma', 'body' => 'Usługi dla drugiej firmy.'];
        $first = $this->install($manifest, $target);
        $id = $first['page_ids']['oferta-b2b'];
        $page = Record::findOrFail($id);
        $this->assertNull($page->published_id);
        $this->assertSame('Nowa firma', $page->draft->data['title']);
        $this->get('/p/oferta-b2b')->assertNotFound();
        $this->postJson(self::BASE.'/'.$first['deployment_id'].'/rollback')->assertConflict();
        $draft = array_replace($page->draft->data, ['title' => 'Lokalna korekta']);
        $pages = Collection::where('key', 'agency_pages')->firstOrFail();
        app(RecordService::class)->save($pages, $page, $draft, $page->version, $admin->id, actorUser: $admin);
        $requests = Collection::where('key', 'requests')->firstOrFail();
        $secondManifest = $manifest;
        $secondManifest['revision']++;
        $secondManifest['fields'][] = ['key' => 'quantity', 'type' => 'number', 'label' => 'Liczba', 'options' => []];
        $second = $this->install($secondManifest);
        $this->assertContains(['key' => 'page.oferta-b2b', 'status' => 'local'], $second['changes']);
        $this->assertSame($id, $second['page_ids']['oferta-b2b']);
        $this->assertSame('Lokalna korekta', $page->fresh()->draft->data['title']);
        $requests->refresh();
        $client = User::factory()->create(['role' => 'customer']);
        $query = app(RecordService::class)->save($requests, null, ['title' => 'RFQ', 'description' => 'Opis', 'quantity' => 4], 0, $client->id, actorUser: $client);
        $this->assertContains('quantity', $requests->definition['portal_fields']);
        $this->postJson(self::BASE.'/'.$second['deployment_id'].'/rollback')->assertOk()->assertJsonPath('rolled_back', true);
        $this->assertNotContains('quantity', $requests->fresh()->definition['portal_fields']);
        $this->assertSame(4, $query->fresh()->draft->data['quantity']);
        $this->assertSame($client->id, $query->fresh()->owner_id);
        $this->assertSame('Lokalna korekta', $page->fresh()->draft->data['title']);
        $this->assertSame($first['deployment_id'], (int) DB::table('formaku_agency_package_head')->value('deployment_id'));
        $this->assertSame(1, Record::where('collection_id', $pages->id)->count());
    }

    public function test_stale_preview_conflicting_updates_and_untrusted_manifests_are_refused_without_partial_writes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $manifest = $this->export();
        $bad = $manifest;
        $bad['acceptance']['command'] = 'something else';
        $this->postJson(self::BASE.'/preview', ['package' => $bad])->assertUnprocessable();
        $first = $this->install($manifest);
        $next = $manifest;
        $next['revision'] = 2;
        $target = ['name' => 'Nowa nazwa'];
        $inputs = ['package' => $next, 'target' => $target];
        $plan = $this->postJson(self::BASE.'/preview', $inputs)->assertOk()->assertJsonPath('can_apply', true)->json();
        $page = Record::findOrFail($first['page_ids']['oferta-b2b']);
        $pages = Collection::findOrFail($page->collection_id);
        $draft = array_replace($page->draft->data, ['title' => 'Równoległa zmiana']);
        app(RecordService::class)->save($pages, $page, $draft, $page->version, $admin->id, actorUser: $admin);
        $this->postJson(self::BASE.'/apply', $inputs + ['token' => $plan['token']])->assertConflict();
        $this->postJson(self::BASE.'/preview', $inputs)->assertOk()->assertJsonPath('can_apply', false)->assertJsonFragment(['key' => 'page.oferta-b2b', 'status' => 'conflict']);
        $this->assertSame(1, AgencyDeployment::count());
        $this->assertSame('Równoległa zmiana', $page->fresh()->draft->data['title']);
    }

    public function test_live_approved_layout_and_role_restrictions_survive_transfer_without_source_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        app(Registry::class)->block(new class implements BlockType
        {
            public function key(): string
            {
                return 'notice';
            }

            public function schema(): array
            {
                return ['message' => ['required', 'string']];
            }

            public function render(array $props): string
            {
                return '<aside>'.e($props['message']).'</aside>';
            }
        });
        DB::beginTransaction();
        try {
            $sourceInstall = $this->install($this->export());
            $source = PortalProject::latest('id')->firstOrFail();
            $page = Record::findOrFail($sourceInstall['page_ids']['oferta-b2b']);
            $pages = Collection::findOrFail($page->collection_id);
            $draft = $page->draft->data;
            $draft['blocks'] = [['type' => 'columns', 'props' => ['columns' => 3], 'children' => [
                ['type' => 'text', 'props' => ['document' => ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'CONFIDENTIAL DRAFT']]]]],
                ['type' => 'notice', 'props' => ['message' => 'CONFIDENTIAL EXTENSION']],
                ...$draft['blocks'],
            ]]];
            app(RecordService::class)->save($pages, $page, $draft, $page->version, $admin->id, actorUser: $admin);
            $requests = Collection::where('key', 'requests')->firstOrFail();
            $schema = $requests->definition;
            $schema['access'] = ['editor' => ['scope' => 'assigned', 'write' => true], 'customer' => ['scope' => 'own', 'write' => true]];
            $schema['transitions'][] = ['from' => 'new', 'to' => 'closed'];
            $schema['portal_attachments'] = true;
            $schema['portal_attachment_limits'] = ['max_files' => 1, 'max_mib' => 1, 'types' => ['pdf']];
            foreach ($schema['fields'] as &$field) {
                if ($field['key'] === 'service') {
                    $field['required'] = true;
                    $field['label'] = 'Wybrana usługa';
                    $field['options'] = ['Montaż', 'Przegląd'];
                }
                if ($field['key'] === 'customer_reply') {
                    $field['access'] = ['write' => ['admin', 'editor']];
                }
            }
            unset($field);
            $requests->update(['definition' => $schema]);
            $transferred = $this->postJson(self::BASE.'/export', ['proposal_id' => $source->id, 'revision' => 3, 'include_field_text' => true])
                ->assertOk()->assertJsonPath('fields.0.label', 'Wybrana usługa')->assertJsonPath('fields.0.options.0', 'Montaż')->json();
            $this->assertStringNotContainsString('CONFIDENTIAL', json_encode($transferred));
            $this->assertSame(['type' => 'columns', 'variant' => 3, 'children' => ['text', 'notice', 'hero', 'cta']], $transferred['pages'][0]['blocks'][0]);
        } finally {
            DB::rollBack();
        }

        $this->assertSame(0, Record::count());
        $installed = $this->install($transferred, ['name' => 'Druga firma', 'body' => 'Oferta drugiej firmy.']);
        $page = Record::findOrFail($installed['page_ids']['oferta-b2b']);
        $this->assertNull($page->published_id);
        $this->assertSame(3, $page->draft->data['blocks'][0]['props']['columns']);
        $this->assertSame('Oferta drugiej firmy.', $page->draft->data['blocks'][0]['children'][0]['props']['document']['content'][0]['content'][0]['text']);
        $this->assertSame(['type' => 'notice', 'props' => ['message' => 'Oferta drugiej firmy.']], $page->draft->data['blocks'][0]['children'][1]);
        $cfg = Collection::where('key', 'requests')->firstOrFail()->definition;
        $this->assertSame('assigned', $cfg['access']['editor']['scope']);
        // MySQL reorders the keys of stored JSON objects, so compare the transition without key order.
        $this->assertContainsEquals(['from' => 'new', 'to' => 'closed'], $cfg['transitions']);
        $this->assertSame(['admin', 'editor'], collect($cfg['fields'])->keyBy('key')['customer_reply']['access']['write']);
        $this->assertSame(['Montaż', 'Przegląd'], collect($cfg['fields'])->keyBy('key')['service']['options']);
        $this->assertTrue(collect($cfg['fields'])->keyBy('key')['service']['required']);
        $this->assertTrue($cfg['portal_attachments']);
        $this->assertSame(1, $cfg['portal_attachment_limits']['max_files']);
    }

    public function test_rollback_blocks_active_page_and_then_retires_an_unused_field_without_deleting_drafts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $manifest = $this->export();
        $this->install($manifest);
        $manifest['revision']++;
        $manifest['fields'][] = ['key' => 'weight', 'label' => 'Waga', 'type' => 'number', 'options' => []];
        $manifest['pages'][] = ['key' => 'nowa-strona', 'blocks' => ['hero', 'cta']];
        $next = $this->install($manifest);
        $page = Record::findOrFail($next['page_ids']['nowa-strona']);
        $this->postJson('/formaku/api/v1/records/'.$page->id.'/publish', ['version' => $page->version])->assertOk();
        $rollback = self::BASE.'/'.$next['deployment_id'].'/rollback';
        $this->postJson($rollback)->assertConflict();
        $this->get('/p/nowa-strona')->assertOk();
        $this->deleteJson('/formaku/api/v1/records/'.$page->id.'/publish', ['version' => $page->fresh()->version])->assertOk();
        $this->postJson($rollback)->assertOk();
        $this->get('/p/nowa-strona')->assertNotFound();
        $this->assertNotNull($page->fresh()->draft_id);
        $this->assertNotContains('weight', array_column(Collection::where('key', 'requests')->firstOrFail()->definition['fields'], 'key'));
        $again = $this->install($manifest);
        $this->assertSame($page->id, $again['page_ids']['nowa-strona']);
    }

    private function export(): array
    {
        return $this->postJson(self::BASE.'/export', ['proposal_id' => $this->source()->id, 'revision' => 1])->assertOk()->json();
    }

    private function source(?int $owner = null): PortalProject
    {
        $proposal = app(PortalBlueprint::class)->reference();
        $proposal['name'] = 'CONFIDENTIAL BRAND';
        $proposal['questions'] = ['CONFIDENTIAL QUESTION'];
        $proposal['pages'][0] = ['slug' => 'confidential-slug', 'title' => 'CONFIDENTIAL TITLE', 'body' => 'CONFIDENTIAL BODY', 'cta' => 'CONFIDENTIAL CTA'];
        $proposal['fields'][0] = ['key' => 'service', 'label' => 'CONFIDENTIAL LABEL', 'type' => 'select', 'options' => ['CONFIDENTIAL S1', 'CONFIDENTIAL S2']];

        return PortalProject::create(['actor_id' => $owner ?? auth()->id(), 'provider' => 'reference', 'state' => 'applied', 'brief' => 'CONFIDENTIAL BRIEF',
            'materials' => 'CONFIDENTIAL SOURCE', 'snapshot' => [], 'proposal' => $proposal, 'pages' => []]);
    }

    private function install(array $manifest, array $target = []): array
    {
        $inputs = ['package' => $manifest, 'target' => $target];
        $plan = $this->postJson(self::BASE.'/preview', $inputs)->assertOk()->assertJsonPath('can_apply', true)->json();
        $payload = $inputs + ['token' => $plan['token']];
        $result = $this->postJson(self::BASE.'/apply', $payload)->assertOk()->json();
        $this->postJson(self::BASE.'/apply', $payload)->assertOk()->assertJsonPath('deployment_id', $result['deployment_id']);

        return $result;
    }
}
