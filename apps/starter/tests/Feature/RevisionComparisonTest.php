<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

class RevisionComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_comparison_uses_the_current_schema_and_reads_only_the_two_revisions_without_mutating_them(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $collection = Collection::create([
            'key' => 'data',
            'name' => 'Data',
            'kind' => 'data',
            'definition' => ['fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                ['key' => 'price', 'label' => 'Current price label', 'type' => 'number'],
                ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean'],
                ['key' => 'added_null', 'label' => 'Added', 'type' => 'text'],
                ['key' => 'removed_null', 'label' => 'Removed', 'type' => 'text'],
                ['key' => 'options', 'label' => 'Options', 'type' => 'richtext'],
                ['key' => 'ordered', 'label' => 'Ordered', 'type' => 'richtext'],
                ['key' => 'shape', 'label' => 'Shape', 'type' => 'richtext'],
                ['key' => 'private_notes', 'label' => 'Private notes', 'type' => 'text', 'private' => true, 'access' => ['read' => ['admin']]],
            ]],
        ]);
        $record = Record::create(['collection_id' => $collection->id, 'owner_id' => $editor->id]);
        $previous = $this->revision($record, [
            'title' => 'Unchanged title',
            'price' => null,
            'enabled' => true,
            'removed_null' => null,
            'options' => ['a' => 1, 'b' => ['one' => 0, 'two' => false]],
            'ordered' => [0, false],
            'shape' => (object) [],
            'private_notes' => 'Hidden from the previous revision',
            'obsolete' => 'Removed from the current schema',
        ]);
        $this->revision($record, ['title' => 'Unrelated revision payload']);
        $draft = $this->revision($record, [
            'title' => 'Unchanged title',
            'price' => 0,
            'enabled' => false,
            'added_null' => null,
            'options' => ['b' => ['two' => false, 'one' => 0], 'a' => 1],
            'ordered' => [false, 0],
            'shape' => [],
            'private_notes' => 'Hidden from the draft',
        ]);
        $record->update([
            'draft_id' => $draft->id,
            'published_id' => $previous->id,
            'version' => 4,
            'publish_at' => now()->addDay(),
        ]);
        $recordBefore = $record->fresh()->getRawOriginal();
        $revisionsBefore = $this->revisionSnapshot($record);
        $eventCount = DB::table('formaku_events')->where('record_id', $record->id)->count();

        $this->actingAs($editor);
        DB::enableQueryLog();
        try {
            $response = $this->getJson($this->compareUrl($record, $previous->id))->assertOk();
            $queries = array_values(array_filter(
                DB::getQueryLog(),
                fn (array $entry): bool => str_contains($entry['query'], 'formaku_revisions'),
            ));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertExactJson([
            'revision' => ['id' => $previous->id, 'created_at' => $previous->created_at->toJSON()],
            'draft' => ['id' => $draft->id, 'created_at' => $draft->created_at->toJSON()],
            'changes' => [
                ['key' => 'price', 'label' => 'Current price label', 'type' => 'number', 'previous' => null, 'current' => 0],
                ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'previous' => true, 'current' => false],
                ['key' => 'added_null', 'label' => 'Added', 'type' => 'text', 'current' => null],
                ['key' => 'removed_null', 'label' => 'Removed', 'type' => 'text', 'previous' => null],
                ['key' => 'ordered', 'label' => 'Ordered', 'type' => 'richtext', 'previous' => [0, false], 'current' => [false, 0]],
                ['key' => 'shape', 'label' => 'Shape', 'type' => 'richtext', 'previous' => [], 'current' => []],
            ],
        ]);
        $raw = json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR);
        $this->assertInstanceOf(stdClass::class, $raw->changes[5]->previous);
        $this->assertSame([], $raw->changes[5]->current);
        $this->assertSame([0, false], $raw->changes[4]->previous);
        $this->assertSame([false, 0], $raw->changes[4]->current);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertCount(1, $queries);
        $this->assertStringContainsString(" in ({$previous->id}, {$draft->id})", strtolower($queries[0]['query']));
        $this->assertSame([$record->id], $queries[0]['bindings']);
        $this->assertSame($recordBefore, $record->fresh()->getRawOriginal());
        $this->assertSame($revisionsBefore, $this->revisionSnapshot($record));
        $this->assertSame($eventCount, DB::table('formaku_events')->where('record_id', $record->id)->count());
    }

    public function test_content_comparison_respects_read_scope_and_revision_ownership(): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $collection = Collection::create([
            'key' => 'content',
            'name' => 'Content',
            'kind' => 'content',
            'definition' => [
                'fields' => [['key' => 'title', 'label' => 'Title', 'type' => 'text']],
                'access' => ['editor' => ['scope' => 'own', 'write' => false]],
            ],
        ]);
        $records = app(RecordService::class);
        $mine = $records->save($collection, null, ['title' => 'My page'], 0, $owner->id);
        $theirs = $records->save($collection, null, ['title' => 'Other page'], 0, $other->id);
        $url = $this->compareUrl($mine, $mine->draft_id);

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonPath('changes', []);
        $this->getJson($this->compareUrl($theirs, $theirs->draft_id))->assertNotFound();
        $this->getJson($this->compareUrl($mine, $theirs->draft_id))->assertNotFound();
        foreach ([0, 'invalid', str_repeat('9', 20), $theirs->draft_id + 1000] as $invalidRevision) {
            $this->getJson($this->compareUrl($mine, $invalidRevision))->assertNotFound();
        }
        $mine->update(['draft_id' => $theirs->draft_id]);
        $this->getJson($url)->assertNotFound();
    }

    private function revision(Record $record, array $data): Revision
    {
        return Revision::create(['record_id' => $record->id, 'schema_version' => 1, 'data' => $data]);
    }

    private function revisionSnapshot(Record $record): array
    {
        return Revision::where('record_id', $record->id)->orderBy('id')->get()
            ->map(fn (Revision $revision) => $revision->getRawOriginal())->all();
    }

    private function compareUrl(Record $record, int|string $revisionId): string
    {
        return "/formaku/api/v1/records/{$record->id}/revisions/{$revisionId}/compare";
    }
}
