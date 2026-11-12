<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class PublicationRecordFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_publication_filters_follow_draft_publish_edit_and_reschedule_lifecycle(): void
    {
        $this->travelTo(Date::parse('2026-11-12T12:00:00Z'));
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('content');
        $records = app(RecordService::class);
        $draft = $records->save($collection, null, ['title' => 'First draft'], 0, $admin->id);
        $url = $this->recordsUrl($collection);
        $schedule = '/formaku/api/v1/records/'.$draft->id.'/schedule';

        $this->actingAs($admin)->getJson($url.'?publication=unpublished')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $draft->id);
        $this->getJson($url.'?publication=published')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'?publication=changed')->assertOk()->assertJsonPath('total', 0);

        $published = $records->publish($draft, $draft->version, $admin->id);
        $scheduled = $this->postJson($schedule, [
            'version' => $published->version,
            'publish_at' => '2026-11-13T12:00:00Z',
        ])->assertOk();
        $this->postJson($schedule, [
            'version' => $scheduled->json('version'),
            'publish_at' => '2026-11-14T12:00:00Z',
        ])->assertOk();
        $this->getJson($url.'?publication=published&scheduled=1')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.published_id', $published->published_id);
        $this->getJson($url.'?publication=changed&scheduled=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url.'?publication=unpublished')->assertOk()->assertJsonPath('total', 0);

        $current = $published->fresh();
        $edited = $records->save($collection, $current, ['title' => 'Later draft'], $current->version, $admin->id);
        $this->getJson($url.'?publication=published')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($url.'?publication=changed&label=Later')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.draft_id', $edited->draft_id);
        $this->getJson($url.'?publication=changed&scheduled=1')->assertOk()->assertJsonPath('total', 0);

        $this->postJson($schedule, [
            'version' => $edited->version,
            'publish_at' => '2026-11-15T12:00:00Z',
        ])->assertOk();
        $this->getJson($url.'?publication=changed&scheduled=1')->assertOk()->assertJsonPath('total', 1);

        $current = $edited->fresh();
        $records->publish($current, $current->version, $admin->id);
        $this->getJson($url.'?publication=changed')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1);
    }

    public function test_publication_filter_composes_with_label_state_schedule_and_ownership_across_pages(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection('content', ['editor' => ['scope' => 'own', 'write' => true]]);
        $ids = [];
        for ($i = 0; $i < 26; $i++) {
            $ids[] = $this->scheduledRecord($collection, $editor, 'Matching title')->id;
        }
        $this->scheduledRecord($collection, $other, 'Matching other owner');
        $this->scheduledRecord($collection, $editor, 'Unrelated title');
        $this->scheduledRecord($collection, $editor, 'Matching other state')->update(['state' => 'closed']);
        $this->scheduledRecord($collection, $editor, 'Matching no schedule')->update(['publish_at' => null]);
        $unchanged = $this->scheduledRecord($collection, $editor, 'Matching unchanged');
        $unchanged->update(['draft_id' => $unchanged->published_id]);
        $this->scheduledRecord($collection, $editor, 'Matching never published')->update(['published_id' => null]);

        $first = $this->actingAs($editor)
            ->getJson($this->recordsUrl($collection).'?publication=changed&label=Matching&state=review&scheduled=1&extra=discard')
            ->assertOk()->assertJsonPath('total', 26)->assertJsonCount(25, 'data');

        $next = $first->json('next_page_url');
        parse_str(parse_url($next, PHP_URL_QUERY), $query);
        ksort($query);
        $this->assertSame([
            'label' => 'Matching', 'page' => '2', 'publication' => 'changed', 'scheduled' => '1', 'state' => 'review',
        ], $query);
        $this->getJson($next)->assertOk()->assertJsonPath('total', 26)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ids[0]);
    }

    public function test_changed_requires_a_draft_and_publication_is_validated_only_when_supplied(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $content = $this->collection('content');
        $data = $this->collection('data');
        $this->scheduledRecord($content, $admin, 'No draft')->update(['draft_id' => null]);
        $contentUrl = $this->recordsUrl($content);
        $dataUrl = $this->recordsUrl($data);

        $this->actingAs($admin)->getJson($contentUrl.'?publication=published')->assertOk()->assertJsonPath('total', 1);
        $this->getJson($contentUrl.'?publication=changed')->assertOk()->assertJsonPath('total', 0);
        $this->getJson($dataUrl)->assertOk();
        $this->getJson($dataUrl.'?publication=published')->assertUnprocessable()->assertJsonValidationErrors('publication');
        foreach (['publication=unknown', 'publication=', 'publication%5B%5D=changed'] as $query) {
            $this->getJson($contentUrl.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors('publication');
        }
    }

    private function collection(string $kind, ?array $access = null): Collection
    {
        return Collection::create([
            'key' => $kind,
            'name' => ucfirst($kind),
            'kind' => $kind,
            'definition' => [
                'fields' => [['key' => 'title', 'label' => 'Title', 'type' => 'text']],
                'access' => $access,
            ],
        ]);
    }

    private function scheduledRecord(Collection $collection, User $owner, string $title): Record
    {
        $record = Record::create([
            'collection_id' => $collection->id,
            'owner_id' => $owner->id,
            'state' => 'review',
            'publish_at' => '2026-11-13 12:00:00',
        ]);
        $published = Revision::create(['record_id' => $record->id, 'schema_version' => $collection->version, 'data' => ['title' => $title]]);
        $draft = Revision::create(['record_id' => $record->id, 'schema_version' => $collection->version, 'data' => ['title' => $title]]);
        $record->update(['published_id' => $published->id, 'draft_id' => $draft->id]);

        return $record;
    }

    private function recordsUrl(Collection $collection): string
    {
        return '/formaku/api/v1/collections/'.$collection->id.'/records';
    }
}
