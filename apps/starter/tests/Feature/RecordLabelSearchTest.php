<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordLabelSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_label_search_uses_the_current_draft_and_legacy_search_uses_the_published_title(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('content', [$this->field('title')]);
        $records = app(RecordService::class);
        $draft = $records->save($collection, null, ['title' => 'Published label'], 0, $admin->id);
        $published = $records->publish($draft, $draft->version, $admin->id);
        $edited = $records->save($collection, $published, ['title' => 'Updated draft'], $published->version, $admin->id);

        $url = $this->recordsUrl($collection);
        $this->actingAs($admin)->getJson($url.'?label=Updated')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $edited->id);
        $this->getJson($url.'?label=Published')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'?search=Published')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.'?search=Updated')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_label_search_treats_pattern_characters_as_literal_text_in_data_names(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('data', [$this->field('name')]);
        $needle = '50%_!\\item';
        $matching = $this->record($collection, $admin, ['name' => 'Stock '.$needle.' ready']);
        $this->record($collection, $admin, ['name' => 'Stock 50any_!\\item ready']);
        $this->record($collection, $admin, ['name' => 'Stock 50%X!\\item ready']);
        $this->record($collection, $admin, ['name' => ['Stock '.$needle.' ready']]);
        $url = $this->recordsUrl($collection);

        $this->actingAs($admin)->getJson($url.'?label='.rawurlencode('  '.$needle.'  '))
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $matching->id);
        $this->getJson($url.'?label=%20%20')->assertOk()->assertJsonPath('total', 4);

        foreach (['label%5B%5D=Stock', 'label='.str_repeat('x', 201), 'label=one%00two'] as $query) {
            $this->getJson($url.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors('label');
        }
    }

    public function test_title_and_name_permissions_are_enforced_independently(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $publisher = User::factory()->create(['role' => 'publisher']);
        $collection = $this->collection('content', [
            $this->field('title', ['publisher']),
            $this->field('name', ['editor']),
        ]);
        $record = $this->record($collection, $editor, ['title' => 'Publisher title', 'name' => 'Editor name']);
        $url = $this->recordsUrl($collection);

        $this->actingAs($editor)->getJson($url.'?label=Publisher')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'?label=Editor')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $record->id)->assertJsonMissingPath('data.0.data.title');
        $this->actingAs($publisher)->getJson($url.'?label=Editor')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'?label=Publisher')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $record->id)->assertJsonMissingPath('data.0.data.name');

        $definition = $collection->definition;
        $definition['fields'][1] = $this->field('name', ['publisher']);
        $collection->update(['definition' => $definition]);
        $this->actingAs($editor)->getJson($url.'?label=Editor')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_label_filter_keeps_scope_and_other_filters_on_every_page(): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection('content', [$this->field('title')], [
            'editor' => ['scope' => 'own', 'write' => true],
        ]);
        $scheduled = ['publish_at' => '2026-11-14 12:00:00', 'state' => 'review'];
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = $this->record($collection, $owner, ['title' => 'Matching page'], $scheduled)->id;
        }
        $this->record($collection, $owner, ['title' => 'Matching unscheduled'], ['state' => 'review']);
        $this->record($collection, $owner, ['title' => 'Matching other state'], ['state' => 'closed'] + $scheduled);
        $this->record($collection, $other, ['title' => 'Matching other owner'], $scheduled);
        $unrelated = $this->record($collection, $owner, ['title' => 'Unrelated page'], $scheduled);
        $unrelated->update(['draft_id' => Record::findOrFail($ids[0])->draft_id]);

        $first = $this->actingAs($owner)
            ->getJson($this->recordsUrl($collection).'?label=Matching&scheduled=1&state=review&extra=discard')
            ->assertOk()->assertJsonPath('total', 26)->assertJsonCount(25, 'data');

        $next = $first->json('next_page_url');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        ksort($query);
        $this->assertSame(['label' => 'Matching', 'page' => '2', 'scheduled' => '1', 'state' => 'review'], $query);
        $this->getJson($next)->assertOk()->assertJsonPath('total', 26)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[0]);
    }

    private function field(string $key, ?array $readRoles = null): array
    {
        $field = ['key' => $key, 'label' => ucfirst($key), 'type' => 'text'];
        if ($readRoles !== null) {
            $field['private'] = true;
            $field['access'] = ['read' => $readRoles];
        }

        return $field;
    }

    private function collection(string $kind, array $fields, ?array $access = null): Collection
    {
        return Collection::create([
            'key' => $kind,
            'name' => ucfirst($kind),
            'kind' => $kind,
            'definition' => ['fields' => $fields, 'access' => $access],
        ]);
    }

    private function record(Collection $collection, User $owner, array $data, array $attributes = []): Record
    {
        $record = Record::create($attributes + ['collection_id' => $collection->id, 'owner_id' => $owner->id]);
        $draft = Revision::create(['record_id' => $record->id, 'schema_version' => $collection->version, 'data' => $data]);
        $record->update(['draft_id' => $draft->id]);

        return $record;
    }

    private function recordsUrl(Collection $collection): string
    {
        return '/formaku/api/v1/collections/'.$collection->id.'/records';
    }
}
