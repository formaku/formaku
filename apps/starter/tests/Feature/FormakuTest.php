<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\AiGateway;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Renderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FormakuTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->role = 'admin';
        $u->save();

        return $u;
    }

    private function collection(string $kind = 'content'): Collection
    {
        return Collection::create(['key' => 'test', 'name' => 'Test', 'kind' => $kind, 'definition' => ['public' => true, 'fields' => [['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true], ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'], ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext']], 'states' => ['new', 'closed'], 'transitions' => [['from' => 'new', 'to' => 'closed']]]]);
    }

    private function record(Collection $c, User $u): Record
    {
        return app(RecordService::class)->save($c, null, ['title' => 'Published title', 'slug' => 'published', 'blocks' => [['type' => 'hero', 'props' => ['title' => 'Original']]]], 0, $u->id);
    }

    public function test_page_uses_only_published_seo_metadata(): void
    {
        $user = $this->admin();
        $collection = $this->collection();
        $definition = $collection->definition;
        $definition['fields'][] = ['key' => 'summary', 'label' => 'Summary', 'type' => 'text'];
        $definition['seo'] = ['title' => 'summary', 'description' => 'summary'];
        $this->actingAs($user)->postJson('/formaku/api/v1/collections', [
            'id' => $collection->id,
            'version' => $collection->version,
            'key' => $collection->key,
            'name' => $collection->name,
            'kind' => $collection->kind,
            'definition' => $definition,
        ])->assertOk()->assertJsonPath('definition.seo.description', 'summary');
        $collection->refresh();
        $service = app(RecordService::class);
        $published = 'Oferta <publiczna> & "warunki"';
        $data = ['title' => 'Page title', 'slug' => 'metadata', 'summary' => $published];
        $record = $service->save($collection, null, $data, 0, $user->id);
        $record = $service->publish($record, $record->version, $user->id);
        $data['summary'] = 'Unpublished description';
        $record = $service->save($collection, $record, $data, $record->version, $user->id);

        $this->get('/p/metadata')->assertOk()
            ->assertSeeHtml('<title>'.e($published).'</title>')
            ->assertSeeHtml('<meta name="description" content="'.e($published).'">')
            ->assertDontSee($published, false)
            ->assertDontSee('Unpublished description');

        $record = $service->publish($record, $record->version, $user->id);
        $this->get('/p/metadata')->assertSeeHtml('<title>Unpublished description</title>');

        $data['summary'] = '   ';
        $record = $service->save($collection, $record, $data, $record->version, $user->id);
        $service->publish($record, $record->version, $user->id);
        $this->get('/p/metadata')->assertSeeHtml('<title>Page title</title>')
            ->assertDontSee('name="description"', false);
    }

    public function test_private_or_non_text_fields_cannot_supply_seo_metadata(): void
    {
        $user = $this->admin();
        $collection = $this->collection();
        $definition = $collection->definition;
        $definition['fields'][0]['private'] = true;
        $definition['seo'] = ['title' => 'title'];
        $this->actingAs($user)->postJson('/formaku/api/v1/collections', [
            'id' => $collection->id,
            'version' => $collection->version,
            'key' => $collection->key,
            'name' => $collection->name,
            'kind' => $collection->kind,
            'definition' => $definition,
        ])->assertUnprocessable()->assertJsonValidationErrors('definition.seo.title');

        $record = $this->record($collection, $user);
        app(RecordService::class)->publish($record, $record->version, $user->id);
        $this->get('/p/published')->assertSeeHtml('<title>Published title</title>')
            ->assertDontSee('name="description"', false);

        $definition['seo']['description'] = 'blocks';
        $collection->update(['definition' => $definition]);
        $this->get('/p/published')->assertSeeHtml('<title>published</title>')
            ->assertDontSee('Published title')->assertDontSee('name="description"', false);
    }

    public function test_client_and_guest_cannot_access_admin_api(): void
    {
        $this->getJson('/formaku/api/v1/bootstrap')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/formaku/api/v1/bootstrap')->assertForbidden();
    }

    public function test_draft_and_slug_do_not_change_publication_and_stale_write_is_rejected(): void
    {
        $u = $this->admin();
        $c = $this->collection();
        $r = $this->record($c, $u);
        $this->get('/p/published')->assertNotFound();
        $r = app(RecordService::class)->publish($r, 1, $u->id);
        $this->get('/p/published')->assertOk()->assertSee('Original');
        $this->actingAs($u)->postJson('/formaku/api/v1/collections/'.$c->id.'/records', ['id' => $r->id, 'version' => $r->version, 'data' => ['title' => 'Secret draft', 'slug' => 'secret', 'blocks' => [['type' => 'hero', 'props' => ['title' => 'New draft']]]]])->assertOk();
        $this->get('/p/published')->assertSee('Original')->assertDontSee('Secret draft');
        $this->get('/p/secret')->assertNotFound();
        $this->postJson('/formaku/api/v1/collections/'.$c->id.'/records', ['id' => $r->id, 'version' => 1, 'data' => ['title' => 'Lost']])->assertConflict();
    }

    public function test_failed_publication_keeps_previous_revision(): void
    {
        $u = $this->admin();
        $c = $this->collection();
        $r = $this->record($c, $u);
        $r = app(RecordService::class)->publish($r, 1, $u->id);
        $original = $r->published_id;
        $r = app(RecordService::class)->save($c, $r, ['title' => 'Bad', 'slug' => 'javascript:alert(1)'], 2, $u->id);
        $this->actingAs($u)->postJson('/formaku/api/v1/records/'.$r->id.'/publish', ['version' => 3])->assertUnprocessable();
        $this->assertEquals($original, $r->fresh()->published_id);
    }

    public function test_ownership_and_private_fields_are_enforced(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $c = $this->collection('data');
        $def = $c->definition;
        $def['fields'][] = ['key' => 'private_note', 'label' => 'Private', 'type' => 'text', 'private' => true];
        $c->update(['definition' => $def]);
        $r = app(RecordService::class)->save($c, null, ['title' => 'Mine', 'private_note' => 'Internal only'], 0, $owner->id);
        $this->actingAs($other)->getJson('/portal/requests/'.$r->id)->assertNotFound();
        $this->actingAs($owner)->getJson('/portal/requests/'.$r->id)->assertOk()->assertDontSee('Internal only');
    }

    public function test_schema_destructive_change_and_editor_access_are_rejected(): void
    {
        $u = $this->admin();
        $c = $this->collection();
        $this->actingAs($u)->postJson('/formaku/api/v1/collections', ['id' => $c->id, 'version' => 1, 'key' => 'test', 'name' => 'Test', 'kind' => 'content', 'definition' => ['fields' => [['key' => 'other', 'type' => 'text', 'label' => 'Other']]]])->assertUnprocessable();
        $u->role = 'editor';
        $u->save();
        $this->postJson('/formaku/api/v1/collections', [])->assertForbidden();
    }

    public function test_transition_checks_version_and_state(): void
    {
        $u = $this->admin();
        $c = $this->collection('data');
        $r = $this->record($c, $u);
        $this->actingAs($u)->postJson('/formaku/api/v1/records/'.$r->id.'/transition', ['version' => 1, 'state' => 'unexpected'])->assertUnprocessable();
        $this->postJson('/formaku/api/v1/records/'.$r->id.'/transition', ['version' => 1, 'state' => 'closed'])->assertOk();
        $this->postJson('/formaku/api/v1/records/'.$r->id.'/transition', ['version' => 1, 'state' => 'closed'])->assertConflict();
    }

    public function test_private_media_requires_owner_or_staff(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('secret.pdf', 'private');
        $u = User::factory()->create();
        $m = Media::create(['owner_id' => $u->id, 'disk' => 'local', 'path' => 'secret.pdf', 'name' => 'secret.pdf', 'mime' => 'application/pdf', 'visibility' => 'private', 'size' => 7]);
        $this->get('/formaku/media/'.$m->id)->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/formaku/media/'.$m->id)->assertNotFound();
        $this->actingAs($u)->get('/formaku/media/'.$m->id)->assertOk();
    }

    public function test_export_excludes_accounts_and_provider_secrets(): void
    {
        $u = $this->admin();
        $this->collection();
        config(['formaku.ai.openai.key' => 'VERY_SECRET']);
        $this->actingAs($u)->getJson('/formaku/api/v1/project')->assertOk()->assertJsonPath('format', 'formaku.project')->assertDontSee('VERY_SECRET')->assertDontSee($u->email);
    }

    public function test_openai_structured_response_and_failure(): void
    {
        config(['formaku.ai.openai' => ['key' => 'secret', 'model' => 'test']]);
        $proposal = ['title' => 'Proposal', 'blocks' => [['type' => 'hero', 'props' => ['title' => 'Safe', 'text' => 'Text', 'label' => 'Go', 'url' => '/']]]];
        $output = ['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($proposal)]]]]];
        Http::fake(['api.openai.com/*' => Http::sequence()->push($output)->push([], 429)]);
        $result = app(AiGateway::class)->generate('openai', 'Create a page');
        $this->assertSame('Proposal', $result['title']);
        Http::assertSent(fn ($r) => $r['store'] === false);
        $this->expectException(\RuntimeException::class);
        app(AiGateway::class)->generate('openai', 'Create');
    }

    public function test_gemini_refusal_does_not_create_a_proposal(): void
    {
        config(['formaku.ai.gemini' => ['key' => 'secret', 'model' => 'test']]);
        Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'SAFETY']]])]);
        $this->expectException(\RuntimeException::class);
        app(AiGateway::class)->generate('gemini', 'Create');
    }

    public function test_ai_cancel_is_owner_scoped(): void
    {
        $u = $this->admin();
        $task = AiTask::create(['user_id' => $u->id, 'provider' => 'openai', 'prompt' => 'test']);
        $other = $this->admin();
        $this->actingAs($other)->postJson('/formaku/api/v1/ai/'.$task->id.'/cancel')->assertNotFound();
        $this->actingAs($u)->postJson('/formaku/api/v1/ai/'.$task->id.'/cancel')->assertOk()->assertJsonPath('state', 'cancelled');
    }

    public function test_renderer_escapes_untrusted_text(): void
    {
        $html = app(Renderer::class)->blocks([['type' => 'hero', 'props' => ['title' => '<script>alert(1)</script>']]]);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_unexpected_customer_fields_cannot_change_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Collection::create(['key' => 'requests', 'name' => 'Requests', 'kind' => 'data', 'definition' => ['fields' => [['key' => 'title', 'label' => 'Title', 'type' => 'text'], ['key' => 'description', 'label' => 'Description', 'type' => 'text']]]]);
        $this->actingAs($owner)->post('/portal/requests', ['title' => 'Hello', 'description' => 'Test', 'owner_id' => $other->id, 'state' => 'closed', 'role' => 'admin'])->assertRedirect('/portal');
        $record = Record::firstOrFail();
        $this->assertSame($owner->id, $record->owner_id);
        $this->assertSame('new', $record->state);
    }

    public function test_private_collection_never_appears_in_public_catalog(): void
    {
        $u = $this->admin();
        $c = $this->collection();
        $definition = $c->definition;
        $definition['public'] = false;
        $c->update(['definition' => $definition]);
        $record = $this->record($c, $u);
        app(RecordService::class)->publish($record, 1, $u->id);
        $this->get('/')->assertDontSee('Published title');
        $this->get('/p/published')->assertNotFound();
    }

    public function test_unknown_block_cannot_replace_active_publication(): void
    {
        $u = $this->admin();
        $c = $this->collection();
        $record = $this->record($c, $u);
        $record = app(RecordService::class)->publish($record, 1, $u->id);
        $record = app(RecordService::class)->save($c, $record, ['title' => 'Bad', 'slug' => 'bad', 'blocks' => [['type' => 'script', 'props' => []]]], 2, $u->id);
        $this->actingAs($u)->postJson('/formaku/api/v1/records/'.$record->id.'/publish', ['version' => 3])->assertUnprocessable();
        $this->get('/p/published')->assertOk()->assertSee('Original');
    }

    public function test_record_lists_are_paginated_at_ten_thousand_records(): void
    {
        $u = $this->admin();
        $c = $this->collection('data');
        for ($batch = 0; $batch < 20; $batch++) {
            $rows = [];
            for ($i = 0; $i < 500; $i++) {
                $rows[] = ['collection_id' => $c->id, 'owner_id' => $u->id, 'version' => 0, 'state' => 'new', 'created_at' => now(), 'updated_at' => now()];
            }DB::table('formaku_records')->insert($rows);
        }
        $this->actingAs($u)->getJson('/formaku/api/v1/collections/'.$c->id.'/records')->assertOk()->assertJsonPath('total', 10000)->assertJsonCount(25, 'data');
    }

    public function test_collection_own_scope_covers_lists_direct_access_and_updates(): void
    {
        $editor = $this->admin();
        $editor->role = 'editor';
        $editor->save();
        $other = $this->admin();
        $collection = $this->collection();
        $definition = $collection->definition;
        $definition['access'] = ['editor' => ['scope' => 'own', 'write' => true]];
        $collection->update(['definition' => $definition]);
        $mine = $this->record($collection, $editor);
        $theirs = $this->record($collection, $other);
        $this->actingAs($editor)->getJson('/formaku/api/v1/collections/'.$collection->id.'/records')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/formaku/api/v1/records/'.$theirs->id)->assertNotFound();
        $this->postJson('/formaku/api/v1/collections/'.$collection->id.'/records', ['id' => $theirs->id, 'version' => 1, 'data' => ['title' => 'Hijacked']])->assertNotFound();
        $this->getJson('/formaku/api/v1/records/'.$theirs->id.'/preview')->assertNotFound();
        $this->getJson('/formaku/api/v1/bootstrap')->assertJsonPath('counts.records', 1);
    }

    public function test_assigned_scope_and_read_only_rule_are_enforced(): void
    {
        $editor = $this->admin();
        $editor->role = 'editor';
        $editor->save();
        $owner = $this->admin();
        $c = $this->collection('data');
        $definition = $c->definition;
        $definition['access'] = ['editor' => ['scope' => 'assigned', 'write' => false]];
        $c->update(['definition' => $definition]);
        $record = $this->record($c, $owner);
        $this->actingAs($editor)->getJson('/formaku/api/v1/records/'.$record->id)->assertNotFound();
        $record->update(['assignee_id' => $editor->id]);
        $this->getJson('/formaku/api/v1/records/'.$record->id)->assertOk();
        $this->postJson('/formaku/api/v1/records/'.$record->id.'/transition', ['version' => 1, 'state' => 'closed'])->assertForbidden();
        $this->postJson('/formaku/api/v1/collections/'.$c->id.'/records', ['version' => 0, 'data' => ['title' => 'New']])->assertForbidden();
    }
}
