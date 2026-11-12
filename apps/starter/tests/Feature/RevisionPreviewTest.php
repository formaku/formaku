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

class RevisionPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_historical_preview_renders_the_requested_revision_without_changing_the_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection();
        $records = app(RecordService::class);
        $old = $records->save($collection, null, $this->page('Old draft', 'old-draft'), 0, $admin->id);
        $public = $records->save($collection, $old, $this->page('Public page', 'public-page'), $old->version, $admin->id);
        $public = $records->publish($public, $public->version, $admin->id);
        $current = $records->save($collection, $public, $this->page('Current draft', 'current-draft'), $public->version, $admin->id);
        $current->update(['publish_at' => now()->addDay()]);
        $before = $current->fresh()->getRawOriginal();
        $history = Revision::where('record_id', $current->id)->count();
        $events = DB::table('formaku_events')->where('record_id', $current->id)->count();

        $oldPreview = $this->actingAs($admin)->get($this->revisionUrl($current, $old->draft_id))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSeeHtml('<h1>Old draft</h1>')
            ->assertDontSee('Current draft')
            ->assertDontSee('Public page');
        $this->assertStringContainsString('no-store', $oldPreview->headers->get('Cache-Control'));

        $currentPreview = $this->get('/formaku/api/v1/records/'.$current->id.'/preview')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->assertSeeHtml('<h1>Current draft</h1>');
        $this->assertStringContainsString('no-store', $currentPreview->headers->get('Cache-Control'));
        $this->get($this->revisionUrl($current, $current->draft_id))
            ->assertOk()->assertSeeHtml('<h1>Current draft</h1>');
        $this->get('/p/public-page')->assertOk()->assertSeeHtml('<h1>Public page</h1>')->assertDontSee('Old draft');
        $this->get('/p/old-draft')->assertNotFound();
        $this->get('/p/current-draft')->assertNotFound();

        $this->assertSame($before, $current->fresh()->getRawOriginal());
        $this->assertSame($history, Revision::where('record_id', $current->id)->count());
        $this->assertSame($events, DB::table('formaku_events')->where('record_id', $current->id)->count());
    }

    public function test_revision_preview_requires_read_scope_and_the_revision_must_belong_to_a_content_record(): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('content', ['editor' => ['scope' => 'own', 'write' => false]]);
        $records = app(RecordService::class);
        $mine = $records->save($collection, null, $this->page('My page', 'mine'), 0, $owner->id);
        $theirs = $records->save($collection, null, $this->page('Other page', 'theirs'), 0, $other->id);
        $data = $records->save($this->collection('data'), null, ['title' => 'Data record'], 0, $admin->id);
        $preview = $this->revisionUrl($mine, $mine->draft_id);

        $this->getJson($preview)->assertUnauthorized();
        $this->actingAs($owner)->get($preview)->assertOk()->assertSeeHtml('<h1>My page</h1>');
        $this->get($this->revisionUrl($theirs, $theirs->draft_id))->assertNotFound();
        $this->get($this->revisionUrl($mine, $theirs->draft_id))->assertNotFound();
        $this->get($this->revisionUrl($mine, $data->draft_id))->assertNotFound();
        $this->get($this->revisionUrl($mine, $data->draft_id + 1000))->assertNotFound();
        $this->actingAs($admin)->get($this->revisionUrl($data, $data->draft_id))->assertNotFound();
    }

    public function test_historical_preview_omits_removed_fields_and_applies_current_field_access(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection();
        $record = app(RecordService::class)->save(
            $collection, null, $this->page('Removed title', 'legacy-page', 'Private block content') + ['name' => 'Visible label'], 0, $editor->id,
        );
        $url = $this->revisionUrl($record, $record->draft_id);
        $definition = $collection->definition;
        $definition['fields'] = array_slice($definition['fields'], 1);
        $collection->update(['definition' => $definition]);

        $this->actingAs($editor)->get($url)->assertOk()
            ->assertSeeHtml('<title>Visible label</title>')
            ->assertSeeHtml('<h1>Private block content</h1>')
            ->assertDontSee('Removed title');
        $this->get('/formaku/api/v1/records/'.$record->id.'/preview')
            ->assertOk()->assertSeeHtml('<title>Removed title</title>');

        $definition['fields'][2]['private'] = true;
        $definition['fields'][2]['access'] = ['read' => []];
        $collection->update(['definition' => $definition]);
        $this->get($url)->assertOk()
            ->assertSeeHtml('<title>Visible label</title>')
            ->assertDontSee('Private block content')
            ->assertDontSee('Removed title');
    }

    private function collection(string $kind = 'content', ?array $access = null): Collection
    {
        return Collection::create([
            'key' => $kind,
            'name' => ucfirst($kind),
            'kind' => $kind,
            'definition' => [
                'public' => $kind === 'content',
                'access' => $access,
                'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                    ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext'],
                ],
            ],
        ]);
    }

    private function page(string $title, string $slug, ?string $heading = null): array
    {
        return [
            'title' => $title,
            'slug' => $slug,
            'blocks' => [['type' => 'hero', 'props' => ['title' => $heading ?? $title]]],
        ];
    }

    private function revisionUrl(Record $record, int $revisionId): string
    {
        return "/formaku/api/v1/records/{$record->id}/revisions/{$revisionId}/preview";
    }
}
