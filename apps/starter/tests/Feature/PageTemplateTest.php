<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PageTemplate;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_copies_only_saved_blocks_into_an_independent_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection('articles');
        $collection->update(['definition' => $collection->definition + [
            'access' => ['editor' => ['scope' => 'own', 'write' => true]],
        ]]);
        $record = $this->draft($collection, $admin, $this->blocks());
        $templates = $this->templatesUrl($collection);
        $templateId = $this->actingAs($admin)->postJson("/formaku/api/v1/records/{$record->id}/page-templates", [
            'name' => 'Landing page',
            'version' => $record->version,
            'blocks' => [['type' => 'hero', 'props' => ['title' => 'Unsaved request blocks']]],
        ])->assertCreated()->assertJsonStructure(['id', 'name'])->json('id');

        $this->assertNull($record->fresh()->published_id);
        $this->actingAs($editor)->getJson("/formaku/api/v1/records/{$record->id}")->assertNotFound();
        $this->getJson($templates)->assertOk()->assertExactJson([
            'data' => [['id' => $templateId, 'name' => 'Landing page']],
            'current_page' => 1,
            'last_page' => 1,
        ]);
        $template = $this->getJson("$templates/$templateId")->assertOk()->assertExactJson([
            'id' => $templateId,
            'name' => 'Landing page',
            'blocks' => $this->blocks(),
        ])->json();
        $copy = $this->postJson("/formaku/api/v1/collections/{$collection->id}/records", [
            'version' => 0,
            'data' => ['title' => 'New title', 'slug' => 'new-page', 'blocks' => $template['blocks']],
        ])->assertSuccessful()->json();
        $copyRecord = Record::findOrFail($copy['id']);
        $this->assertSame($editor->id, (int) $copyRecord->owner_id);
        $this->assertNull($copyRecord->published_id);
        $this->assertArrayNotHasKey('internal_notes', $copyRecord->draft->data);
        $this->get('/p/new-page')->assertNotFound();
        $this->postJson("/formaku/api/v1/records/{$copyRecord->id}/publish", [
            'version' => $copyRecord->version,
        ])->assertForbidden();

        $changedBlocks = $template['blocks'];
        $changedBlocks[0]['children'][0]['props']['title'] = 'Edited copy';
        $updatedCopy = $this->postJson("/formaku/api/v1/collections/{$collection->id}/records", [
            'id' => $copyRecord->id,
            'version' => $copyRecord->version,
            'data' => ['title' => 'New title', 'slug' => 'new-page', 'blocks' => $changedBlocks],
        ])->assertSuccessful()->json();
        $this->assertSame('Template heading', $record->fresh()->draft->data['blocks'][0]['children'][0]['props']['title']);
        $this->getJson("$templates/$templateId")->assertJsonPath('blocks.0.children.0.props.title', 'Template heading');

        $this->actingAs($admin)->deleteJson("$templates/$templateId")->assertOk()->assertExactJson(['deleted' => true]);
        $this->getJson("$templates/$templateId")->assertNotFound();
        $this->postJson("/formaku/api/v1/records/{$copyRecord->id}/publish", [
            'version' => $updatedCopy['version'],
        ])->assertOk();
        $this->get('/p/new-page')->assertOk()->assertSee('Edited copy');
    }

    public function test_template_routes_enforce_build_collection_and_creation_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $collection = $this->collection('articles');
        $other = $this->collection('news');
        $record = $this->draft($collection, $admin, $this->blocks());
        $templates = $this->templatesUrl($collection);
        $createUrl = "/formaku/api/v1/records/{$record->id}/page-templates";
        $input = ['name' => 'Landing page', 'version' => $record->version];

        $this->getJson($templates)->assertUnauthorized();
        $this->actingAs($editor)->postJson($createUrl, $input)->assertForbidden();
        $templateId = $this->actingAs($admin)->postJson($createUrl, $input)->assertCreated()->json('id');
        $this->getJson($this->templatesUrl($other)."/$templateId")->assertNotFound();
        $this->deleteJson($this->templatesUrl($other)."/$templateId")->assertNotFound();

        $this->actingAs($editor)->deleteJson("$templates/$templateId")->assertForbidden();
        foreach ([
            ['scope' => 'all', 'write' => false, 'status' => 403],
            ['scope' => 'assigned', 'write' => true, 'status' => 403],
            ['scope' => 'none', 'write' => false, 'status' => 404],
        ] as $rule) {
            $definition = $collection->definition;
            $definition['access'] = ['editor' => ['scope' => $rule['scope'], 'write' => $rule['write']]];
            $collection->update(['definition' => $definition]);

            $this->getJson($templates)->assertStatus($rule['status']);
            $this->getJson("$templates/$templateId")->assertStatus($rule['status']);
        }

        $definition['fields'][2]['private'] = true;
        $collection->update(['definition' => $definition]);
        $this->actingAs($admin)->getJson($templates)->assertNotFound();
        $this->getJson("$templates/$templateId")->assertNotFound();
        $this->postJson($createUrl, $input)->assertNotFound();

        $other->update(['kind' => 'data']);
        $otherRecord = $this->draft($other, $admin, $this->blocks());
        $this->getJson($this->templatesUrl($other))->assertNotFound();
        $this->postJson("/formaku/api/v1/records/{$otherRecord->id}/page-templates", [
            'name' => 'Data template',
            'version' => $otherRecord->version,
        ])->assertNotFound();
        $this->assertDatabaseCount('formaku_page_templates', 1);
    }

    public function test_template_creation_rejects_stale_empty_malformed_and_oversized_drafts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('articles');
        $record = $this->draft($collection, $admin, $this->blocks());
        $createUrl = "/formaku/api/v1/records/{$record->id}/page-templates";

        $this->actingAs($admin)->postJson($createUrl, [
            'name' => 'Landing page',
            'version' => $record->version + 1,
        ])->assertConflict();
        $this->postJson($createUrl, [
            'name' => str_repeat('a', 101),
            'version' => $record->version,
        ])->assertUnprocessable();

        foreach ([
            [],
            [['type' => 'unknown']],
            [['type' => 'container', 'children' => ['unexpected' => ['type' => 'hero']]]],
            [['type' => 'text', 'props' => ['document' => 'invalid rich-text shape']]],
            [['type' => 'text', 'props' => ['document' => ['type' => 'text', 'text' => 'Invalid marks', 'marks' => 'bold']]]],
            array_fill(0, 101, ['type' => 'hero']),
            [['type' => 'hero', 'props' => ['text' => str_repeat('a', 256 * 1024)]]],
        ] as $blocks) {
            $draft = $this->draft($collection, $admin, $blocks);
            $this->postJson("/formaku/api/v1/records/{$draft->id}/page-templates", [
                'name' => 'Invalid template',
                'version' => $draft->version,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('formaku_page_templates', 0);
    }

    public function test_template_list_is_paginated_and_omits_blocks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection('articles');
        $first = null;
        for ($index = 1; $index <= 26; $index++) {
            $template = PageTemplate::create([
                'collection_id' => $collection->id,
                'name' => "Template $index",
                'blocks' => $this->blocks(),
            ]);
            $first ??= $template;
        }

        $templates = $this->templatesUrl($collection);
        $this->actingAs($admin)->getJson($templates)
            ->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.name', 'Template 26')
            ->assertJsonMissingPath('data.0.blocks');
        $this->getJson("$templates?page=2")->assertOk()->assertExactJson([
            'data' => [['id' => $first->id, 'name' => 'Template 1']],
            'current_page' => 2,
            'last_page' => 2,
        ]);
    }

    private function collection(string $key): Collection
    {
        return Collection::create([
            'key' => $key,
            'name' => ucfirst($key),
            'kind' => 'content',
            'definition' => [
                'public' => true,
                'fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'required' => true],
                    ['key' => 'slug', 'type' => 'text', 'label' => 'Slug'],
                    ['key' => 'blocks', 'type' => 'richtext', 'label' => 'Blocks'],
                    ['key' => 'internal_notes', 'type' => 'text', 'label' => 'Notes', 'private' => true],
                ],
            ],
        ]);
    }

    private function draft(Collection $collection, User $admin, array $blocks): Record
    {
        return app(RecordService::class)->save($collection, null, [
            'title' => 'Source title',
            'slug' => 'source-page',
            'internal_notes' => 'Private source text',
            'blocks' => $blocks,
        ], 0, $admin->id);
    }

    private function blocks(): array
    {
        return [
            [
                'type' => 'container',
                'props' => ['layout' => 'stack'],
                'children' => [['type' => 'hero', 'props' => ['title' => 'Template heading']]],
            ],
        ];
    }

    private function templatesUrl(Collection $collection): string
    {
        return "/formaku/api/v1/collections/{$collection->id}/page-templates";
    }
}
