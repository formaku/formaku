<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecordHistoryPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_revision_metadata_is_paginated_in_descending_id_order_without_loading_payloads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection();
        $record = Record::create(['collection_id' => $collection->id, 'owner_id' => $admin->id]);
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            $ids[] = Revision::create([
                'record_id' => $record->id,
                'schema_version' => $collection->version,
                'author_id' => $admin->id,
                'data' => ['title' => 'Historical payload '.$i],
            ])->id;
        }

        $this->actingAs($admin);
        DB::enableQueryLog();
        try {
            $first = $this->getJson($this->recordUrl($record).'/revisions')->assertOk();
            $queries = $this->revisionQueries();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $first->assertJsonPath('total', 27)
            ->assertJsonPath('per_page', 25)
            ->assertJsonPath('last_page', 2)
            ->assertJsonCount(25, 'data')
            ->assertDontSee('Historical payload');
        $this->assertSame(array_slice(array_reverse($ids), 0, 25), array_column($first->json('data'), 'id'));
        foreach ($first->json('data') as $revision) {
            $this->assertSame(['id', 'created_at'], array_keys($revision));
            $this->assertIsString($revision['created_at']);
        }
        $this->assertCount(2, $queries);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('select *', $sql);
            $this->assertStringNotContainsString('data', $sql);
        }

        $next = $this->getJson($first->json('next_page_url'))->assertOk()->assertJsonPath('current_page', 2)
            ->assertJsonCount(2, 'data')->assertJsonPath('next_page_url', null)->assertDontSee('Historical payload');
        $this->assertSame([$ids[1], $ids[0]], array_column($next->json('data'), 'id'));
    }

    public function test_record_history_can_be_omitted_without_reading_old_revisions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection();
        $records = app(RecordService::class);
        $old = $records->save($collection, null, ['title' => 'Historical payload'], 0, $admin->id);
        $current = $records->save($collection, $old, ['title' => 'Current draft'], $old->version, $admin->id);
        $current->update(['publish_at' => now()->addDay()]);
        $url = $this->recordUrl($current);
        $before = $current->fresh()->getRawOriginal();
        $revisionCount = Revision::where('record_id', $current->id)->count();
        $eventCount = DB::table('formaku_events')->where('record_id', $current->id)->count();

        $legacy = $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonCount(2, 'history')
            ->assertJsonPath('history.0.id', $current->draft_id)
            ->assertJsonPath('history.1.id', $old->draft_id)
            ->assertJsonPath('history.1.data.title', 'Historical payload')->json();
        $this->getJson($url.'?history=1')->assertOk()->assertExactJson($legacy);

        DB::enableQueryLog();
        try {
            $without = $this->getJson($url.'?history=0')->assertOk();
            $queries = $this->revisionQueries();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $without->assertJsonMissingPath('history')->assertJsonPath('data.title', 'Current draft')->assertDontSee('Historical payload');
        unset($legacy['history']);
        $without->assertExactJson($legacy);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('data', $queries[0]);
        $this->assertSame($before, $current->fresh()->getRawOriginal());
        $this->assertSame($revisionCount, Revision::where('record_id', $current->id)->count());
        $this->assertSame($eventCount, DB::table('formaku_events')->where('record_id', $current->id)->count());
    }

    public function test_data_history_obeys_read_scope_and_rejects_invalid_explicit_history_options(): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection('data', ['editor' => ['scope' => 'own', 'write' => false]]);
        $records = app(RecordService::class);
        $mine = $records->save($collection, null, ['title' => 'Owned record'], 0, $owner->id);
        $theirs = $records->save($collection, null, ['title' => 'Other record'], 0, $other->id);
        $mineUrl = $this->recordUrl($mine);

        $this->getJson($mineUrl.'/revisions')->assertUnauthorized();
        $this->actingAs($owner)->getJson($mineUrl.'/revisions')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine->draft_id)
            ->assertJsonMissingPath('data.0.data');
        $this->getJson($this->recordUrl($theirs).'/revisions')->assertNotFound();
        $this->getJson($this->recordUrl($theirs).'?history=0')->assertNotFound();
        foreach (['history=2', 'history=true', 'history=', 'history%5B%5D=0'] as $query) {
            $this->getJson($mineUrl.'?'.$query)->assertUnprocessable()->assertJsonValidationErrors('history');
        }
    }

    /** @return list<string> */
    private function revisionQueries(): array
    {
        return array_values(array_filter(
            array_map(fn (array $query): string => strtolower($query['query']), DB::getQueryLog()),
            fn (string $sql): bool => str_contains($sql, 'formaku_revisions'),
        ));
    }

    private function collection(string $kind = 'content', ?array $access = null): Collection
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

    private function recordUrl(Record $record): string
    {
        return '/formaku/api/v1/records/'.$record->id;
    }
}
