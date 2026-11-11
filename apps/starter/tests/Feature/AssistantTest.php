<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Contracts\BlockType;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = '/formaku/api/v1/assistant';

    private const API = '/formaku/assistant/v1';

    public function test_pairing_is_single_use_and_revoked_on_replacement_or_by_the_owner(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $this->getJson(self::SETTINGS)->assertUnauthorized();
        $this->deleteJson(self::SETTINGS)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson(self::SETTINGS)->assertForbidden();
        $this->actingAs($admin)->get(self::SETTINGS.'/adapter')->assertDownload('formaku-assistant.mjs');

        $code = $this->start($admin, $collection);
        $this->getJson(self::SETTINGS)->assertJsonPath('connection.status', 'pending')->assertDontSee($code);
        $this->getJson(self::API.'/connection')->assertUnauthorized();
        $token = $this->exchange($code);
        $this->postJson(self::API.'/exchange', ['code' => $code])->assertUnauthorized();
        $this->getJson(self::SETTINGS)->assertJsonPath('connection.status', 'active')->assertDontSee($token);

        $connection = DB::table('formaku_assistants')->first();
        $this->assertNull($connection->pairing_hash);
        $this->assertSame(hash('sha256', $token), $connection->token_hash);

        $this->actingAs($this->admin())->deleteJson(self::SETTINGS)->assertOk();
        $this->getJson(self::API.'/connection', $this->bearer($token))->assertOk();
        $replacement = $this->start($admin, $collection);
        $this->getJson(self::API.'/connection', $this->bearer($token))->assertUnauthorized();
        $this->deleteJson(self::SETTINGS)->assertOk();
        $this->postJson(self::API.'/exchange', ['code' => $replacement])->assertUnauthorized();
        $this->getJson(self::SETTINGS)->assertJsonPath('connection', null);
    }

    public function test_codes_and_tokens_expire_and_access_follows_current_permissions(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $expiredCode = $this->start($admin, $collection);
        $this->travel(5)->minutes();
        $this->postJson(self::API.'/exchange', ['code' => $expiredCode])->assertUnauthorized();

        $token = $this->exchange($this->start($admin, $collection, true));
        $headers = $this->bearer($token);
        $this->getJson(self::API.'/connection', $headers)->assertOk()->assertJsonPath('can_publish', true);

        config(['formaku.authorize' => fn (User $user, string $ability) => $user->role === 'admin' && $ability !== 'publish']);
        $this->getJson(self::API.'/connection', $headers)->assertOk()->assertJsonPath('can_publish', false);
        $this->postJson(self::SETTINGS.'/pairing', ['collection_id' => $collection->id, 'can_publish' => true])->assertForbidden();

        $admin->forceFill(['role' => 'editor'])->save();
        $this->getJson(self::API.'/connection', $headers)->assertUnauthorized();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->travel(30)->days();
        $this->getJson(self::API.'/connection', $headers)->assertUnauthorized();
    }

    public function test_connection_owner_can_revoke_after_losing_builder_access(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $headers = $this->bearer($this->exchange($this->start($admin, $collection)));

        $admin->forceFill(['role' => 'editor'])->save();
        $this->getJson(self::SETTINGS)->assertForbidden();
        $this->getJson(self::API.'/connection', $headers)->assertUnauthorized();
        $this->deleteJson(self::SETTINGS)->assertOk();

        $admin->forceFill(['role' => 'admin'])->save();
        $this->getJson(self::API.'/connection', $headers)->assertUnauthorized();
    }

    public function test_only_eligible_collections_and_the_paired_collection_are_accessible(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $other = $this->pages();
        $private = $this->pages(public: false);
        $internalField = $this->pages(privateField: true);
        $this->actingAs($admin)->getJson(self::SETTINGS)->assertJsonCount(2, 'collections');
        foreach ([$private, $internalField] as $denied) {
            $this->postJson(self::SETTINGS.'/pairing', ['collection_id' => $denied->id, 'can_publish' => false])->assertUnprocessable();
        }

        $otherPage = app(RecordService::class)->save($other, null, ['title' => 'Outside scope'], 0, $admin->id);
        $headers = $this->bearer($this->exchange($this->start($admin, $collection, true)));
        $this->getJson(self::API.'/pages', $headers)->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::API.'/pages/'.$otherPage->id, $headers)->assertNotFound();
        $this->putJson(self::API.'/pages/'.$otherPage->id, ['version' => 1, 'data' => ['title' => 'Outside']], $headers)->assertNotFound();
        $this->postJson(self::API.'/pages/'.$otherPage->id.'/publish', ['version' => 1], $headers)->assertNotFound();
        auth()->forgetGuards();
        $this->getJson('/formaku/api/v1/bootstrap', $headers)->assertUnauthorized();

        $collection->update(['definition' => array_replace($collection->definition, ['public' => false])]);
        $this->getJson(self::API.'/pages', $headers)->assertForbidden();
    }

    public function test_agent_creates_edits_and_publishes_versioned_content(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $headers = $this->bearer($this->exchange($this->start($admin, $collection)));
        $data = ['title' => 'Kontakt', 'slug' => 'kontakt', 'blocks' => [
            ['type' => 'hero', 'props' => ['title' => 'Pierwszy szkic']],
            ['type' => 'hero', 'props' => ['title' => 'Drugi blok']],
        ]];
        $input = ['request_id' => (string) Str::uuid(), 'data' => $data];
        $id = $this->postJson(self::API.'/pages', $input, $headers)->assertCreated()->assertJsonPath('version', 1)->json('id');
        $path = self::API.'/pages/'.$id;
        $preview = '/formaku/api/v1/records/'.$id.'/preview';
        $this->getJson($path, $headers)->assertJsonPath('draft_preview_path', $preview);
        $this->get($preview)->assertOk()->assertSee('Pierwszy szkic');
        $this->postJson(self::API.'/pages', $input, $headers)->assertOk()->assertJsonPath('id', $id);
        $this->postJson(self::API.'/pages', array_replace($input, ['data' => ['title' => 'Different']]), $headers)->assertConflict();
        $this->getJson(self::API.'/pages', $headers)->assertJsonCount(1, 'items')->assertJsonPath('items.0.draft_title', 'Kontakt');
        $response = $this->getJson($path, $headers)->assertOk();
        $this->assertEquals($data, $response->json('data'));
        $this->postJson($path.'/publish', ['version' => 1], $headers)->assertForbidden();
        $this->get('/p/kontakt')->assertNotFound();

        $headers = $this->bearer($this->exchange($this->start($admin, $collection, true)));
        $data['blocks'][0]['props']['title'] = 'Zaktualizowana treść';
        $update = ['version' => 1, 'data' => $data];
        $this->putJson($path, $update, $headers)->assertOk()->assertJsonPath('version', 2);
        $this->putJson($path, $update, $headers)->assertConflict();
        $this->postJson($path.'/publish', ['version' => 1], $headers)->assertConflict();
        $this->postJson($path.'/publish', ['version' => 2], $headers)->assertOk()
            ->assertJsonPath('public_path', '/p/kontakt')->assertJsonPath('has_unpublished_changes', false);
        $this->get('/p/kontakt')->assertOk()->assertSee('Zaktualizowana treść');
        $events = DB::table('formaku_events')->where('record_id', $id)->orderBy('id')->get();
        $this->assertSame(['saved', 'saved', 'published'], $events->pluck('type')->all());
        $this->assertSame($admin->id, $events->last()->actor_id);
        $this->assertArrayHasKey('assistant_connection_id', json_decode($events->last()->payload, true));

        auth()->forgetGuards();
        $this->getJson($preview, $headers)->assertUnauthorized();
    }

    public function test_agent_can_validate_a_draft_without_writing_records_or_running_extension_renderers(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $endpoint = self::API.'/pages/validate';
        $data = ['title' => 'Landing', 'blocks' => [['type' => 'hero', 'props' => ['title' => 'Landing']]], 'typo' => 'Ignored'];
        $this->postJson($endpoint, ['data' => $data])->assertUnauthorized();
        $headers = $this->bearer($this->exchange($this->start($admin, $collection)));
        $this->getJson(self::API.'/connection', $headers)->assertJsonPath('can_validate_draft', true);

        $this->postJson($endpoint, ['data' => $data], $headers)->assertOk()
            ->assertJsonPath('valid', true)->assertJsonPath('collection_version', $collection->version)
            ->assertJsonPath('ignored_fields', ['typo']);
        $this->postJson($endpoint, ['data' => ['slug' => 'landing']], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $extension = $this->createMock(BlockType::class);
        $extension->method('key')->willReturn('custom');
        $extension->expects($this->never())->method('render');
        app(Registry::class)->block($extension);
        $data['blocks'] = [['type' => 'container', 'children' => [['type' => 'custom']]]];
        $this->postJson($endpoint, ['data' => $data], $headers)->assertUnprocessable();

        $this->assertDatabaseCount('formaku_records', 0);
        $this->assertDatabaseCount('formaku_revisions', 0);
        $this->assertDatabaseCount('formaku_events', 0);
        $this->deleteJson(self::SETTINGS)->assertOk();
        $this->postJson($endpoint, ['data' => $data], $headers)->assertUnauthorized();
    }

    public function test_agent_discovers_the_optional_public_catalog_by_route_name(): void
    {
        $headers = $this->bearer($this->exchange($this->start($this->admin(), $this->pages())));
        $this->getJson(self::API.'/connection', $headers)->assertOk()
            ->assertJsonMissingPath('public_catalog_path')->assertJsonMissingPath('public_variants_catalog_path');

        Route::get('/custom-store/public/products', fn () => ['data' => []])->name('formaku-commerce.public.products');
        Route::getRoutes()->refreshNameLookups();
        $this->getJson(self::API.'/connection', $headers)->assertOk()
            ->assertJsonPath('public_catalog_path', '/custom-store/public/products')->assertJsonMissingPath('public_variants_catalog_path');

        Route::get('/custom-store/v2/products', fn () => ['data' => []])->name('commerce.v2.products');
        Route::getRoutes()->refreshNameLookups();
        $this->getJson(self::API.'/connection', $headers)->assertOk()
            ->assertJsonPath('public_catalog_path', '/custom-store/public/products')
            ->assertJsonPath('public_variants_catalog_path', '/custom-store/v2/products');
    }

    public function test_agent_lists_only_public_images_from_current_publications_in_the_paired_collection(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $other = $this->pages();
        $makeMedia = fn (string $name, string $visibility = 'public', string $mime = 'image/png'): Media => Media::create([
            'owner_id' => $admin->id, 'disk' => 'local', 'path' => 'files/'.$name,
            'name' => $name, 'alt' => 'Alt '.$name, 'mime' => $mime, 'visibility' => $visibility, 'size' => 1,
        ]);
        $published = $makeMedia('published.png');
        $old = $makeMedia('old.png');
        $draftOnly = $makeMedia('draft.png');
        $private = $makeMedia('private.png', 'private');
        $document = $makeMedia('document.pdf', mime: 'application/pdf');
        $outside = $makeMedia('outside.png');
        $block = fn (Media $media): array => ['type' => 'image', 'props' => ['media_id' => $media->id]];
        $records = app(RecordService::class);
        $data = ['title' => 'Page', 'slug' => 'page', 'blocks' => [$block($old)]];
        $record = $records->save($collection, null, $data, 0, $admin->id);
        $record = $records->publish($record, $record->version, $admin->id);
        $data['blocks'] = [$block($published), $block($private), $block($document), $block($published)];
        $record = $records->save($collection, $record, $data, $record->version, $admin->id);
        $record = $records->publish($record, $record->version, $admin->id);
        $data['blocks'] = [$block($draftOnly)];
        $records->save($collection, $record, $data, $record->version, $admin->id);
        $otherRecord = $records->save($other, null, ['title' => 'Other', 'blocks' => [$block($outside)]], 0, $admin->id);
        $records->publish($otherRecord, $otherRecord->version, $admin->id);

        $headers = $this->bearer($this->exchange($this->start($admin, $collection)));
        $endpoint = self::API.'/images';
        $this->getJson($endpoint)->assertUnauthorized();
        $this->getJson(self::API.'/connection', $headers)->assertJsonPath('can_list_images', true);
        $this->getJson($endpoint, $headers)->assertOk()->assertExactJson([
            'items' => [['id' => $published->id, 'alt' => $published->alt, 'public_path' => '/formaku/media/'.$published->id]],
            'next_page' => null,
        ]);
        $this->deleteJson(self::SETTINGS)->assertOk();
        $this->getJson($endpoint, $headers)->assertUnauthorized();
    }

    public function test_agent_reuses_a_panel_template_only_in_its_paired_collection(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $other = $this->pages();
        $blocks = [['type' => 'hero', 'props' => ['title' => 'Template headline']]];
        $records = app(RecordService::class);
        $source = $records->save($collection, null, ['title' => 'Private draft title', 'blocks' => $blocks], 0, $admin->id);
        $outside = $records->save($other, null, ['title' => 'Outside', 'blocks' => $blocks], 0, $admin->id);
        $template = $this->actingAs($admin)->postJson('/formaku/api/v1/records/'.$source->id.'/page-templates', [
            'name' => 'Offer layout', 'version' => 1,
        ])->assertCreated()->json('id');
        $outsideTemplate = $this->postJson('/formaku/api/v1/records/'.$outside->id.'/page-templates', [
            'name' => 'Outside layout', 'version' => 1,
        ])->assertCreated()->json('id');
        $headers = $this->bearer($this->exchange($this->start($admin, $collection)));
        $endpoint = self::API.'/templates';

        $this->getJson($endpoint)->assertUnauthorized();
        $this->getJson(self::API.'/connection', $headers)->assertJsonPath('can_read_templates', true);
        $this->getJson($endpoint, $headers)->assertOk()->assertExactJson([
            'items' => [['id' => $template, 'name' => 'Offer layout']], 'next_page' => null,
        ]);
        $this->getJson($endpoint.'/'.$outsideTemplate, $headers)->assertNotFound();
        $layout = $this->getJson($endpoint.'/'.$template, $headers)->assertOk()->assertExactJson([
            'id' => $template, 'name' => 'Offer layout', 'blocks' => $blocks,
        ])->json('blocks');

        $layout[0]['props']['title'] = 'New offer';
        $data = ['title' => 'New offer', 'slug' => 'new-offer', 'blocks' => $layout];
        $this->postJson(self::API.'/pages/validate', ['data' => $data], $headers)->assertOk()->assertJsonPath('valid', true);
        $this->postJson(self::API.'/pages', ['data' => $data, 'request_id' => (string) Str::uuid()], $headers)
            ->assertCreated()->assertJsonPath('has_unpublished_changes', true);
        $this->get('/p/new-offer')->assertNotFound();
        $this->getJson($endpoint.'/'.$template, $headers)->assertJsonPath('blocks', $blocks);

        $definition = $collection->definition;
        $definition['fields'][2]['private'] = true;
        $collection->update(['definition' => $definition]);
        $this->getJson($endpoint.'/'.$template, $headers)->assertForbidden();
        $this->deleteJson(self::SETTINGS)->assertOk();
        $this->getJson($endpoint, $headers)->assertUnauthorized();
    }

    public function test_agent_cannot_save_broken_blocks_or_publish_over_an_existing_slug(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $headers = $this->bearer($this->exchange($this->start($admin, $collection, true)));
        $input = ['request_id' => (string) Str::uuid(), 'data' => ['title' => 'Draft', 'slug' => 'kontakt']];
        $bad = $input;
        $bad['data']['blocks'] = [['type' => 'hero', 'props' => ['title' => ['invalid']]]];
        $this->postJson(self::API.'/pages', $bad, $headers)->assertUnprocessable()->assertJsonValidationErrors('data.blocks');

        $first = $this->postJson(self::API.'/pages', $input, $headers)->assertCreated()->json('id');
        $this->postJson(self::API.'/pages/'.$first.'/publish', ['version' => 1], $headers)->assertOk();
        $input['request_id'] = (string) Str::uuid();
        $second = $this->postJson(self::API.'/pages', $input, $headers)->assertCreated()->json('id');
        $this->postJson(self::API.'/pages/'.$second.'/publish', ['version' => 1], $headers)->assertConflict();
        $this->getJson(self::API.'/pages/'.$second, $headers)->assertJsonPath('published_slug', null)->assertJsonPath('version', 1);
    }

    public function test_agent_rejects_nested_block_lists_that_cannot_be_rendered_in_full(): void
    {
        $admin = $this->admin();
        $headers = $this->bearer($this->exchange($this->start($admin, $this->pages())));
        $endpoint = self::API.'/pages';
        $child = ['type' => 'hero', 'props' => ['title' => 'Child']];
        $data = ['title' => 'Landing', 'blocks' => [['type' => 'container', 'children' => array_fill(0, 100, $child)]]];
        $this->postJson($endpoint.'/validate', ['data' => $data], $headers)->assertOk()->assertJsonPath('valid', true);
        $draft = $this->postJson($endpoint, ['request_id' => (string) Str::uuid(), 'data' => $data], $headers)
            ->assertCreated()->json('id');

        $data['blocks'][0]['children'][] = $child;
        $this->postJson($endpoint.'/validate', ['data' => $data], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('data.blocks.0.children');
        $this->postJson($endpoint, ['request_id' => (string) Str::uuid(), 'data' => $data], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('data.blocks.0.children');
        $this->putJson($endpoint.'/'.$draft, ['version' => 1, 'data' => $data], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('data.blocks.0.children');
        $this->assertDatabaseCount('formaku_records', 1);
        $this->assertDatabaseCount('formaku_revisions', 1);
    }

    public function test_agent_cannot_overwrite_a_draft_scheduled_for_publication(): void
    {
        $admin = $this->admin();
        $collection = $this->pages();
        $record = app(RecordService::class)->save($collection, null, ['title' => 'Scheduled'], 0, $admin->id);
        $this->actingAs($admin)->postJson('/formaku/api/v1/records/'.$record->id.'/schedule', [
            'version' => $record->version,
            'publish_at' => now()->addDay()->toDateTimeString(),
        ])->assertOk();
        $record->refresh();

        foreach ([false, true] as $canPublish) {
            $headers = $this->bearer($this->exchange($this->start($admin, $collection, $canPublish)));
            $this->putJson(self::API.'/pages/'.$record->id, [
                'version' => $record->version,
                'data' => ['title' => 'Replacement'],
            ], $headers)->assertConflict();
        }

        $unchanged = $record->fresh();
        $this->assertEquals($record->publish_at, $unchanged->publish_at);
        $this->assertSame($record->draft_id, $unchanged->draft_id);
        $this->assertSame($record->version, $unchanged->version);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function pages(bool $public = true, bool $privateField = false): Collection
    {
        return Collection::create([
            'key' => 'pages_'.Str::lower(Str::random(8)),
            'kind' => 'content',
            'name' => 'Pages',
            'definition' => [
                'public' => $public,
                'fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Tytuł', 'required' => true, 'private' => $privateField],
                    ['key' => 'slug', 'type' => 'text', 'label' => 'Adres'],
                    ['key' => 'blocks', 'type' => 'richtext', 'label' => 'Bloki'],
                ],
            ],
        ]);
    }

    private function start(User $admin, Collection $collection, bool $publish = false): string
    {
        $response = $this->actingAs($admin)->postJson(self::SETTINGS.'/pairing', ['collection_id' => $collection->id, 'can_publish' => $publish])
            ->assertCreated();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        return $response->json('code');
    }

    private function exchange(string $code): string
    {
        return $this->postJson(self::API.'/exchange', ['code' => $code])->assertOk()->json('access_token');
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
