<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class RecordFieldPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_and_restore_preserve_protected_values_and_existing_relations(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $service = app(RecordService::class);
        $target = Collection::create([
            'key' => 'protected_targets',
            'name' => 'Protected targets',
            'kind' => 'data',
            'definition' => [
                'access' => ['editor' => ['scope' => 'own', 'write' => false]],
                'fields' => [['key' => 'title', 'type' => 'text', 'required' => true]],
            ],
        ]);
        $related = $service->save($target, null, ['title' => 'Other owner'], 0, $admin->id);
        $collection = $this->collection($target->id);
        $record = $this->createOwnedRecord($service, $collection, $admin, $editor, $related);
        $original = $record->draft;
        $data = [
            'title' => 'Admin draft',
            'secret' => 'Current secret',
            'review' => 'Current review',
            'reference' => [$related->id],
        ];
        $record = $service->save($collection, $record, $data, $record->version, $admin->id, actorUser: $admin);

        $record = $service->save($collection, $record, [
            'title' => 'Editor draft',
            'review' => 'Current review',
            'confirmed' => false,
            'quantity' => 0,
        ], $record->version, $editor->id, actorUser: $editor);
        $this->assertSame('Current secret', $record->draft->data['secret']);
        $this->assertSame('Current review', $record->draft->data['review']);
        $this->assertSame([$related->id], $record->draft->data['reference']);
        $this->assertFalse($record->draft->data['confirmed']);
        $this->assertSame(0, $record->draft->data['quantity']);
        $this->assertDatabaseHas('formaku_relations', ['revision_id' => $record->draft_id, 'field' => 'reference', 'target_id' => $related->id]);

        $restored = $service->save($collection, $record, $original->data, $record->version, $editor->id, actorUser: $editor, restore: true);
        $this->assertNotSame($record->draft_id, $restored->draft_id);
        $this->assertSame('Initial draft', $restored->draft->data['title']);
        $this->assertSame('Current secret', $restored->draft->data['secret']);
        $this->assertSame('Current review', $restored->draft->data['review']);
        $this->assertSame('Original secret', $original->fresh()->data['secret']);
    }

    public function test_unreadable_input_and_read_only_changes_are_denied_without_writing_a_revision(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $service = app(RecordService::class);
        $collection = $this->collection();
        $record = $this->createOwnedRecord($service, $collection, $admin, $editor);
        $revisions = DB::table('formaku_revisions')->count();

        foreach ([['secret' => 'Original secret'], ['secret' => null], ['review' => 'Changed review']] as $field) {
            $this->assertSaveStatus(403, fn () => $service->save(
                $collection,
                $record,
                ['title' => 'Editor draft'] + $field,
                $record->version,
                $editor->id,
                actorUser: $editor,
            ));
        }
        $this->assertSame($revisions, DB::table('formaku_revisions')->count());
        $this->assertSame($record->draft_id, $record->fresh()->draft_id);
    }

    public function test_protected_saves_use_current_rules_and_require_the_matching_actor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $service = app(RecordService::class);
        $collection = $this->collection();
        $record = $this->createOwnedRecord($service, $collection, $admin, $editor);
        $data = ['title' => 'Next draft'];

        $this->assertSaveStatus(403, fn () => $service->save($collection, $record, $data, $record->version, $editor->id));
        $this->assertSaveStatus(403, fn () => $service->save($collection, $record, $data, $record->version, $editor->id, actorUser: $admin));
        $this->assertSaveStatus(403, fn () => $service->save($collection, null, $data, 0, $editor->id, actorUser: $editor));

        $updated = $collection->fresh();
        $definition = $updated->definition;
        $definition['access']['editor']['scope'] = 'assigned';
        $updated->update(['definition' => $definition]);
        $this->assertSaveStatus(403, fn () => $service->save($collection, null, $data, 0, $editor->id, actorUser: $editor));
        $this->assertSaveStatus(404, fn () => $service->save($collection, $record, $data, $record->version, $editor->id, actorUser: $editor));

        $record->update(['assignee_id' => $editor->id]);
        $this->assertSaveStatus(409, fn () => $service->save($collection, $record, $data, 0, $editor->id, actorUser: $editor));
        $saved = $service->save($collection, $record, $data, $record->version, $editor->id, actorUser: $editor);
        $this->assertSame('Next draft', $saved->draft->data['title']);
    }

    private function createOwnedRecord(
        RecordService $service,
        Collection $collection,
        User $admin,
        User $editor,
        ?Record $related = null,
    ): Record {
        $data = ['title' => 'Initial draft', 'secret' => 'Original secret', 'review' => 'Original review'];
        if ($related !== null) {
            $data['reference'] = [$related->id];
        }
        $record = $service->save($collection, null, $data, 0, $admin->id, actorUser: $admin);
        $record->update(['owner_id' => $editor->id]);

        return $record;
    }

    private function collection(?int $relationTarget = null): Collection
    {
        $fields = [
            ['key' => 'title', 'type' => 'text', 'required' => true],
            ['key' => 'secret', 'type' => 'text', 'required' => true, 'private' => true, 'access' => ['read' => []]],
            ['key' => 'review', 'type' => 'text', 'access' => ['write' => []]],
            ['key' => 'confirmed', 'type' => 'boolean'],
            ['key' => 'quantity', 'type' => 'number'],
        ];
        if ($relationTarget !== null) {
            $fields[] = ['key' => 'reference', 'type' => 'relation', 'target' => $relationTarget, 'access' => ['write' => []]];
        }

        return Collection::create([
            'key' => 'protected_drafts',
            'name' => 'Protected drafts',
            'kind' => 'data',
            'definition' => [
                'fields' => $fields,
                'access' => ['editor' => ['scope' => 'own', 'write' => true]],
            ],
        ]);
    }

    private function assertSaveStatus(int $status, callable $save): void
    {
        try {
            $save();
            $this->fail('Expected the draft write to be rejected.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
