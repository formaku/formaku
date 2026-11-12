<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFieldAccessTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/formaku/api/v1';

    public function test_admin_views_use_the_current_field_rules_for_existing_revisions(): void
    {
        [$admin, $publisher, $collection, $record] = $this->publishedRecordWithNewRules();
        $recordUrl = self::API.'/records/'.$record->id;
        $recordsUrl = self::API.'/collections/'.$collection->id.'/records';

        $this->actingAs($publisher)->getJson(self::API.'/bootstrap')
            ->assertOk()
            ->assertJsonPath('collections.0.definition.fields.0.key', 'name')
            ->assertJsonPath('collections.0.definition.fields.0.writable', false)
            ->assertJsonMissingPath('collections.0.definition.fields.0.access')
            ->assertJsonMissing(['key' => 'title'])
            ->assertJsonMissing(['key' => 'blocks']);
        $this->getJson(self::API.'/collections')->assertOk()
            ->assertJsonPath('0.definition.fields.1.key', 'slug');

        $this->getJson($recordsUrl)
            ->assertOk()
            ->assertJsonPath('data.0.published_title', 'Public listing label')
            ->assertJsonMissingPath('data.0.search_text')
            ->assertJsonMissingPath('data.0.data.title')
            ->assertJsonMissingPath('data.0.data.blocks')
            ->assertJsonMissingPath('data.0.published');
        $this->getJson($recordsUrl.'?search=hidden-title')
            ->assertOk()->assertJsonPath('total', 0);
        $this->getJson($recordsUrl.'?search=anything-else')
            ->assertOk()->assertJsonPath('total', 0);

        $this->getJson($recordUrl)
            ->assertOk()
            ->assertJsonPath('data.summary', 'Current summary')
            ->assertJsonPath('published_title', 'Public listing label')
            ->assertJsonCount(2, 'history')
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.blocks')
            ->assertJsonMissingPath('history.0.data.title')
            ->assertJsonMissingPath('history.1.data.blocks')
            ->assertDontSee('hidden-title-')
            ->assertDontSee('Hidden block');
        $this->get($recordUrl.'/preview')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSeeHtml('<title>Public listing label</title>')
            ->assertDontSee('Hidden block');

        $this->getJson(self::API.'/collections/'.$collection->id.'/relation-options?selected[]='.$record->id)
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Public listing label')
            ->assertJsonPath('selected.0.label', 'Public listing label')
            ->assertDontSee('hidden-title');

        $this->actingAs($admin)->getJson($recordUrl)
            ->assertOk()
            ->assertJsonPath('data.title', 'hidden-title-current')
            ->assertJsonPath('published_title', 'hidden-title-previous')
            ->assertJsonPath('history.1.data.title', 'hidden-title-previous');
        $this->getJson(self::API.'/collections')->assertOk()
            ->assertJsonPath('0.definition.fields.0.access.read', []);
        $this->get($recordUrl.'/preview')->assertOk()->assertSee('Hidden block current');
        $this->getJson($recordsUrl.'?search=hidden-title')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_mutations_preserve_protected_data_and_project_the_response(): void
    {
        [, $publisher, $collection, $record] = $this->publishedRecordWithNewRules();
        $recordUrl = self::API.'/records/'.$record->id;
        $recordsUrl = self::API.'/collections/'.$collection->id.'/records';
        $currentBlocks = $record->draft->data['blocks'];
        $previousRevision = $record->published_id;

        $this->actingAs($publisher)->postJson($recordsUrl, [
            'id' => $record->id,
            'version' => $record->version,
            'data' => ['summary' => 'Denied change', 'name' => 'Changed read-only name'],
        ])->assertForbidden();

        $saved = $this->postJson($recordsUrl, [
            'id' => $record->id,
            'version' => $record->version,
            'data' => ['summary' => 'Publisher summary', 'slug' => 'field-rule-example'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Public listing label')
            ->assertJsonPath('data.summary', 'Publisher summary')
            ->assertJsonMissingPath('data.title')
            ->assertJsonMissingPath('data.blocks')
            ->assertJsonMissingPath('search_text')
            ->json();
        $this->assertSame('hidden-title-current', $record->fresh()->draft->data['title']);

        $restored = $this->postJson($recordUrl.'/restore/'.$previousRevision, ['version' => $saved['version']])
            ->assertOk()
            ->assertJsonPath('published_title', 'Public listing label')
            ->assertJsonMissingPath('search_text')
            ->assertJsonMissingPath('data')
            ->json();
        $draft = $record->fresh()->draft->data;
        $this->assertSame('Previous summary', $draft['summary']);
        $this->assertSame('hidden-title-current', $draft['title']);
        $this->assertSame($currentBlocks, $draft['blocks']);
        $this->assertSame('Public listing label', $draft['name']);

        $transitioned = $this->postJson($recordUrl.'/transition', ['version' => $restored['version'], 'state' => 'review'])
            ->assertOk()->assertJsonPath('state', 'review')
            ->assertJsonPath('published_title', 'Public listing label')
            ->assertJsonMissingPath('search_text')->json();
        $scheduled = $this->postJson($recordUrl.'/schedule', [
            'version' => $transitioned['version'],
            'publish_at' => now()->addHour()->toIso8601String(),
        ])->assertOk()->assertJsonPath('published_title', 'Public listing label')
            ->assertJsonMissingPath('search_text')->json();
        $this->postJson($recordUrl.'/publish', ['version' => $scheduled['version']])
            ->assertOk()
            ->assertJsonPath('published_title', 'Public listing label')
            ->assertJsonMissingPath('search_text')
            ->assertDontSee('hidden-title-current');
        $this->assertSame('hidden-title-current', $record->fresh()->published->data['title']);
    }

    public function test_protected_drafts_accept_empty_input_when_all_fields_are_read_only(): void
    {
        [, $publisher, $collection, $record] = $this->publishedRecordWithNewRules();
        $definition = $collection->definition;
        $definition['fields'][2]['access'] = ['write' => []];
        $definition['fields'][4]['access'] = ['write' => []];
        $collection->update(['definition' => $definition]);
        $previous = $record->draft->data;
        $recordsUrl = self::API.'/collections/'.$collection->id.'/records';
        $request = ['id' => $record->id, 'version' => $record->version];

        $this->actingAs($publisher)->postJson($recordsUrl, $request)
            ->assertUnprocessable()->assertJsonValidationErrors('data');
        $this->postJson($recordsUrl, $request + ['data' => []])
            ->assertOk()->assertJsonPath('version', $record->version + 1)
            ->assertJsonPath('data.summary', 'Current summary')
            ->assertJsonMissingPath('data.title');
        $this->assertSame($previous, $record->fresh()->draft->data);
    }

    public function test_project_import_persists_the_validated_collection_definition(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(self::API.'/project/import', [
            'format' => 'formaku.project',
            'version' => 1,
            'collections' => [[
                'key' => 'imported_fields',
                'name' => 'Imported fields',
                'kind' => 'data',
                'version' => 400,
                'definition' => ['fields' => [[
                    'key' => 'note',
                    'label' => 'Note',
                    'type' => 'text',
                    'private' => true,
                    'access' => ['read' => []],
                    'writable' => false,
                ]]],
            ]],
        ])->assertOk()->assertJsonPath('imported', 1);

        $collection = Collection::where('key', 'imported_fields')->sole();
        $this->assertSame(1, $collection->version);
        $this->assertSame(['read' => []], $collection->definition['fields'][0]['access']);
        $this->assertArrayNotHasKey('writable', $collection->definition['fields'][0]);
    }

    /** @return array{User, User, Collection, Record} */
    private function publishedRecordWithNewRules(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $publisher = User::factory()->create(['role' => 'publisher']);
        $definition = [
            'public' => true,
            'states' => ['new', 'review'],
            'transitions' => [['from' => 'new', 'to' => 'review']],
            'fields' => [
                ['key' => 'title', 'label' => 'Internal title', 'type' => 'text', 'required' => true, 'private' => true],
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'slug', 'label' => 'Address', 'type' => 'text'],
                ['key' => 'blocks', 'label' => 'Internal blocks', 'type' => 'richtext', 'private' => true],
                ['key' => 'summary', 'label' => 'Summary', 'type' => 'text'],
            ],
        ];
        $collection = Collection::create([
            'key' => 'field_rule_example',
            'name' => 'Field rule example',
            'kind' => 'content',
            'definition' => $definition,
        ])->fresh();
        $service = app(RecordService::class);
        $previous = [
            'title' => 'hidden-title-previous',
            'name' => 'Public listing label',
            'slug' => 'field-rule-example',
            'blocks' => [['type' => 'hero', 'props' => ['title' => 'Hidden block previous']]],
            'summary' => 'Previous summary',
        ];
        $record = $service->save($collection, null, $previous, 0, $admin->id);
        $record = $service->publish($record, $record->version, $admin->id);
        $record = $service->save($collection, $record, array_replace($previous, [
            'title' => 'hidden-title-current',
            'blocks' => [['type' => 'hero', 'props' => ['title' => 'Hidden block current']]],
            'summary' => 'Current summary',
        ]), $record->version, $admin->id);

        $definition['fields'][0]['access'] = ['read' => []];
        $definition['fields'][1]['access'] = ['write' => []];
        $definition['fields'][3]['access'] = ['read' => []];
        $this->actingAs($admin)->postJson(self::API.'/collections', [
            'id' => $collection->id,
            'version' => $collection->version,
            'key' => $collection->key,
            'name' => $collection->name,
            'kind' => $collection->kind,
            'definition' => $definition,
        ])->assertOk();

        return [$admin, $publisher, $collection->fresh(), $record->fresh()];
    }
}
