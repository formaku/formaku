<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class ScheduledRecordListTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_content_respects_collection_access_and_keeps_filters_across_pages(): void
    {
        $this->travelTo(Date::parse('2026-11-12T12:00:00Z'));
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection('content', ['editor' => ['scope' => 'own', 'write' => true]]);
        $matches = ['state' => 'review', 'published_title' => 'Scheduled guide'];
        $overdue = $this->record($collection, $editor, $matches + ['publish_at' => '2026-11-11 12:00:00']);
        $future = $matches + ['publish_at' => '2026-11-13 12:00:00'];
        $visibleIds = [];
        for ($i = 0; $i < 25; $i++) {
            $visibleIds[] = $this->record($collection, $editor, $future)->id;
        }
        $this->record($collection, $editor, $matches);
        $this->record($collection, $editor, ['state' => 'closed'] + $future);
        $this->record($collection, $editor, ['published_title' => 'Other title'] + $future);
        $this->record($collection, $other, $future);

        $url = $this->recordsUrl($collection);
        $firstPage = $this->actingAs($editor)->getJson($url.'?scheduled=1&state=review&search=Scheduled%20guide&other=discard')
            ->assertOk()
            ->assertJsonPath('total', 26)
            ->assertJsonCount(25, 'data');

        $this->assertSame(
            [$overdue->id, ...array_slice(array_reverse($visibleIds), 0, 24)],
            array_column($firstPage->json('data'), 'id'),
        );
        $firstPage->assertJsonPath('data.0.publish_at', '2026-11-11T12:00:00.000000Z');
        $nextPage = $firstPage->json('next_page_url');
        parse_str(parse_url($nextPage, PHP_URL_QUERY), $parameters);
        $this->assertSame(['scheduled' => '1', 'state' => 'review', 'search' => 'Scheduled guide', 'page' => '2'], $parameters);

        $this->getJson($nextPage)->assertOk()
            ->assertJsonPath('total', 26)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visibleIds[0]);
    }

    public function test_missing_or_zero_scheduled_filter_keeps_the_full_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('content');
        $this->record($collection, $admin, ['publish_at' => '2026-11-13 12:00:00']);
        $this->record($collection, $admin);
        $url = $this->recordsUrl($collection);

        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('total', 2);
        $this->getJson($url.'?scheduled=0')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_rejects_scheduled_filter_for_data_collections_and_non_binary_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $data = $this->collection('data');
        $content = $this->collection('content');

        $this->actingAs($admin)->getJson($this->recordsUrl($data).'?scheduled=1')
            ->assertUnprocessable()->assertJsonValidationErrors('scheduled');
        $this->getJson($this->recordsUrl($data).'?scheduled=0')->assertOk();

        foreach (['?scheduled=2', '?scheduled=true', '?scheduled=', '?scheduled%5B%5D=1'] as $query) {
            $this->getJson($this->recordsUrl($content).$query)
                ->assertUnprocessable()->assertJsonValidationErrors('scheduled');
        }
    }

    private function collection(string $kind, ?array $access = null): Collection
    {
        return Collection::create([
            'key' => $kind,
            'name' => ucfirst($kind),
            'kind' => $kind,
            'definition' => ['fields' => [], 'access' => $access],
        ]);
    }

    private function record(Collection $collection, User $owner, array $attributes = []): Record
    {
        return Record::create($attributes + [
            'collection_id' => $collection->id,
            'owner_id' => $owner->id,
        ]);
    }

    private function recordsUrl(Collection $collection): string
    {
        return '/formaku/api/v1/collections/'.$collection->id.'/records';
    }
}
