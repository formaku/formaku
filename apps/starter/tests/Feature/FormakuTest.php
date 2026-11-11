<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\AiGateway;
use Formaku\Cms\Services\MediaVariants;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Renderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_site_navigation_defaults_and_saves_ordered_settings(): void
    {
        $endpoint = '/formaku/api/v1/site-navigation';
        $defaults = [
            'version' => 0,
            'header' => [
                ['label' => 'Portal klienta', 'url' => '/portal'],
                ['label' => 'Panel', 'url' => '/formaku'],
            ],
            'footer' => [],
            'footerText' => '',
            'theme' => 'light',
        ];
        $this->actingAs($this->admin())->getJson($endpoint)->assertOk()->assertExactJson($defaults);

        $settings = [
            'version' => 0,
            'header' => [
                ['label' => 'About & contact', 'url' => '/p/about?view=short#contact'],
                ['label' => 'Project', 'url' => 'https://formaku.example/docs'],
            ],
            'footer' => [['label' => 'Home', 'url' => '/']],
            'footerText' => 'Formaku & Strefa Badań',
        ];
        $saved = ['version' => 1, 'theme' => 'light'] + $settings;
        $this->putJson($endpoint, $settings)->assertOk()->assertExactJson($saved);
        $this->getJson($endpoint)->assertOk()->assertExactJson($saved);
        $this->putJson($endpoint, $settings)->assertConflict();
        $this->getJson($endpoint)->assertOk()->assertExactJson($saved);

        $layout = view('formaku::layout');
        app('view')->callComposer($layout);
        $this->assertEquals($saved, $layout->getData()['siteNavigation']);

        $cleared = ['version' => 1, 'header' => [], 'footer' => [], 'footerText' => ''];
        $this->putJson($endpoint, $cleared)->assertOk()->assertExactJson(['version' => 2, 'theme' => 'light'] + $cleared);
        $this->getJson($endpoint)->assertOk()->assertExactJson(['version' => 2, 'theme' => 'light'] + $cleared);
    }

    public function test_site_theme_is_shared_by_public_pages_and_previews(): void
    {
        $user = $this->admin();
        $record = $this->record($this->collection(), $user);
        app(RecordService::class)->publish($record, $record->version, $user->id);
        $endpoint = '/formaku/api/v1/site-navigation';
        $settings = ['header' => [], 'footer' => [], 'footerText' => 'Existing footer'];
        DB::table('formaku_site_navigation')->where('id', 1)->update([
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);

        $this->get('/p/published')->assertOk()->assertSeeHtml('data-theme="light"');
        $this->actingAs($user)->getJson($endpoint)->assertOk()->assertJsonPath('theme', 'light');
        $this->putJson($endpoint, ['version' => 0, 'theme' => 'unknown'] + $settings)
            ->assertUnprocessable()->assertJsonValidationErrors('theme');
        $this->putJson($endpoint, ['version' => 0, 'theme' => 'dark'] + $settings)
            ->assertOk()->assertJsonPath('theme', 'dark');
        $this->putJson($endpoint, ['version' => 1, 'footerText' => 'Updated by an older client'] + $settings)
            ->assertOk()->assertJsonPath('theme', 'dark');
        $this->get('/p/published')->assertOk()->assertSeeHtml('data-theme="dark"')
            ->assertSee('Updated by an older client');
        $this->get('/formaku/api/v1/records/'.$record->id.'/preview')->assertOk()
            ->assertSeeHtml('data-theme="dark"');
        $this->get('/formaku')->assertOk()->assertSeeHtml('data-theme="light"');
    }

    public function test_site_navigation_settings_require_builder_access(): void
    {
        $endpoint = '/formaku/api/v1/site-navigation';
        $settings = ['version' => 0, 'header' => [], 'footer' => [], 'footerText' => ''];
        $this->getJson($endpoint)->assertUnauthorized();
        $this->putJson($endpoint, $settings)->assertUnauthorized();

        foreach (['editor', 'publisher'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->getJson($endpoint)->assertForbidden();
            $this->putJson($endpoint, $settings)->assertForbidden();
        }
    }

    public function test_site_navigation_rejects_unsafe_urls_and_invalid_lists(): void
    {
        $endpoint = '/formaku/api/v1/site-navigation';
        $this->actingAs($this->admin());
        $settings = ['version' => 0, 'header' => [], 'footer' => [], 'footerText' => ''];
        foreach (['javascript:alert(1)', 'data:text/plain,example', '//example.test', '/\\example.test', "/pa\tth", 'https:///no-host', 'https://user@example.test', 'some-page'] as $url) {
            $this->putJson($endpoint, [
                'header' => [['label' => 'Unsafe', 'url' => $url]],
            ] + $settings)->assertUnprocessable()->assertJsonValidationErrors('header.0.url');
        }
        $link = ['label' => 'Example', 'url' => '/example'];
        $this->putJson($endpoint, [
            'header' => array_fill(0, 13, $link),
            'footer' => ['first' => $link],
            'footerText' => str_repeat('x', 501),
        ] + $settings)->assertUnprocessable()->assertJsonValidationErrors(['header', 'footer', 'footerText']);
        $this->getJson($endpoint)->assertOk()->assertJsonPath('version', 0);
    }

    public function test_nested_layout_settings_survive_save_preview_and_publication(): void
    {
        $user = $this->admin();
        $collection = $this->collection();
        $blocks = [
            ['type' => 'columns', 'children' => [
                ['type' => 'cta', 'props' => ['title' => 'Legacy layout']],
            ]],
            ['type' => 'container', 'props' => ['layout' => 'stack'], 'children' => [
                ['type' => 'columns', 'props' => ['columns' => 3], 'children' => [
                    ['type' => 'hero', 'props' => ['title' => 'First child']],
                    ['type' => 'cta', 'props' => ['title' => 'Second child']],
                ]],
            ]],
        ];
        $service = app(RecordService::class);
        $record = $service->save($collection, null, [
            'title' => 'Layout', 'slug' => 'layout', 'blocks' => $blocks,
        ], 0, $user->id);
        $this->assertEquals($blocks, $record->draft->data['blocks']);

        $this->get('/p/layout')->assertNotFound();
        $preview = $this->actingAs($user)->get('/formaku/api/v1/records/'.$record->id.'/preview')->assertOk();
        $service->publish($record, $record->version, $user->id);
        $public = $this->get('/p/layout')->assertOk();

        foreach ([$preview, $public] as $response) {
            $response->assertSeeHtmlInOrder([
                '<section class="block-layout">', 'Legacy layout',
                '<section class="block-layout block-layout-stack">',
                '<section class="block-layout block-layout-fixed block-layout-columns-3">',
                'First child', 'Second child',
            ]);
        }
    }

    public function test_old_published_slugs_redirect_directly_to_the_current_slug(): void
    {
        $user = $this->admin();
        $collection = $this->collection();
        $service = app(RecordService::class);
        $record = $this->record($collection, $user);
        $record = $service->publish($record, $record->version, $user->id);

        $record = $service->save($collection, $record, ['title' => 'Renamed', 'slug' => 'second'], $record->version, $user->id);
        $this->get('/p/published')->assertOk();
        $this->get('/p/second')->assertNotFound();
        $record = $service->publish($record, $record->version, $user->id);
        $this->get('/p/published?campaign=old')->assertStatus(301)->assertHeader('Location', '/p/second');

        $record = $service->save($collection, $record, ['title' => 'Renamed again', 'slug' => 'third'], $record->version, $user->id);
        $service->publish($record, $record->version, $user->id);

        $firstRedirect = $this->get('/p/published')->assertStatus(301)->assertHeader('Location', '/p/third');
        $this->assertStringContainsString('no-store', $firstRedirect->headers->get('Cache-Control'));
        $this->get('/p/second')->assertStatus(301)->assertHeader('Location', '/p/third');
        $this->get('/p/third')->assertOk()->assertSeeHtml('<title>Renamed again</title>');
        $sitemap = $this->get('/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('/p/third</loc>', $sitemap);
        $this->assertStringNotContainsString('/p/published</loc>', $sitemap);
        $this->assertStringNotContainsString('/p/second</loc>', $sitemap);
    }

    public function test_current_publication_wins_when_an_old_slug_is_reused(): void
    {
        $user = $this->admin();
        $collection = $this->collection();
        $service = app(RecordService::class);
        $first = $this->record($collection, $user);
        $first = $service->publish($first, $first->version, $user->id);
        $first = $service->save($collection, $first, ['title' => 'First', 'slug' => 'first-new'], $first->version, $user->id);
        $service->publish($first, $first->version, $user->id);
        $second = $service->save($collection, null, ['title' => 'Second', 'slug' => 'published'], 0, $user->id);
        $second = $service->publish($second, $second->version, $user->id);

        $this->get('/p/published')->assertOk()->assertSeeHtml('<title>Second</title>');

        $second = $service->save($collection, $second, ['title' => 'Second', 'slug' => 'second-new'], $second->version, $user->id);
        $service->publish($second, $second->version, $user->id);
        $this->get('/p/published')->assertStatus(301)->assertHeader('Location', '/p/second-new');
    }

    public function test_redirects_hide_private_publications_and_skip_slugs_retired_in_private(): void
    {
        $user = $this->admin();
        $collection = $this->collection();
        $service = app(RecordService::class);
        $record = $this->record($collection, $user);
        $record = $service->publish($record, $record->version, $user->id);
        $record = $service->save($collection, $record, ['title' => 'Public', 'slug' => 'public-second'], $record->version, $user->id);
        $record = $service->publish($record, $record->version, $user->id);
        $collection->update(['definition' => array_replace($collection->definition, ['public' => false])]);

        $this->get('/p/published')->assertNotFound()->assertHeaderMissing('Location');

        $record = $service->save($collection, $record, ['title' => 'Private', 'slug' => 'private-third'], $record->version, $user->id);
        $service->publish($record, $record->version, $user->id);
        $collection->update(['definition' => array_replace($collection->definition, ['public' => true])]);
        $this->get('/p/public-second')->assertNotFound()->assertHeaderMissing('Location');
        $this->get('/p/published')->assertStatus(301)->assertHeader('Location', '/p/private-third');
    }

    public function test_sitemap_lists_current_publications_on_the_configured_base_url(): void
    {
        config(['app.url' => 'https://example.test/site&docs/']);
        $user = $this->admin();
        $collection = $this->collection();
        $service = app(RecordService::class);
        $record = $this->record($collection, $user);
        $record = $service->publish($record, $record->version, $user->id);
        $service->save($collection, $record, ['title' => 'Draft edit', 'slug' => 'draft-edit'], $record->version, $user->id);
        $service->save($collection, null, ['title' => 'Draft page', 'slug' => 'draft-only'], 0, $user->id);
        $orphan = $service->save($collection, null, ['title' => 'Orphan', 'slug' => 'orphan'], 0, $user->id);
        $orphan->update(['published_id' => 99999, 'published_slug' => 'orphan']);
        $hidden = $collection->replicate();
        $hidden->key = 'hidden';
        $hidden->save();
        $hiddenPage = $service->save($hidden, null, ['title' => 'Hidden', 'slug' => 'hidden'], 0, $user->id);
        $service->publish($hiddenPage, $hiddenPage->version, $user->id);
        $hidden->update(['definition' => array_replace($hidden->definition, ['public' => false])]);
        $collection->update(['definition' => array_replace($collection->definition, ['public' => '1'])]);

        $response = $this->get('http://other.example/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $sitemap = simplexml_load_string($response->streamedContent());

        $this->assertSame('urlset', $sitemap->getName());
        $this->assertSame(['https://example.test/site&docs/', 'https://example.test/site&docs/p/published'], array_map('strval', $sitemap->xpath('//*[local-name()="loc"]')));
    }

    public function test_large_sitemaps_expose_an_index_and_bounded_parts(): void
    {
        config(['app.url' => 'https://example.test']);
        $collection = $this->collection();
        foreach (array_chunk(range(1, 4001), 250) as $ids) {
            DB::table('formaku_records')->insert(array_map(fn ($id) => [
                'id' => $id, 'collection_id' => $collection->id,
                'published_id' => $id, 'published_slug' => 'page-'.$id,
            ], $ids));
            DB::table('formaku_revisions')->insert(array_map(fn ($id) => [
                'id' => $id, 'record_id' => $id, 'schema_version' => 1, 'data' => '{}',
            ], $ids));
        }

        $index = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->streamedContent());
        $first = simplexml_load_string($this->get('/sitemap-1.xml')->assertOk()->streamedContent());
        $second = simplexml_load_string($this->get('/sitemap-2.xml')->assertOk()->streamedContent());

        $this->assertSame('sitemapindex', $index->getName());
        $this->assertSame(['https://example.test/sitemap-1.xml', 'https://example.test/sitemap-2.xml'], array_map('strval', $index->xpath('//*[local-name()="loc"]')));
        $this->assertCount(4001, $first->xpath('//*[local-name()="loc"]'));
        $this->assertSame(['https://example.test/p/page-4001'], array_map('strval', $second->xpath('//*[local-name()="loc"]')));
        $this->get('/sitemap-3.xml')->assertNotFound();
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

        $preview = $this->get('/formaku/api/v1/records/'.$record->id.'/preview')
            ->assertOk()->assertHeader('X-Robots-Tag', 'noindex')
            ->assertSeeHtml('<title>Unpublished description</title>')
            ->assertSeeHtml('<meta name="description" content="Unpublished description">');
        $this->assertStringContainsString('no-store', $preview->headers->get('Cache-Control'));

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

    public function test_public_image_upload_generates_responsive_webp_variants_and_keeps_the_original(): void
    {
        $variants = app(MediaVariants::class);
        if (! $variants->canGenerate()) {
            $this->markTestSkipped('GD with WebP support is unavailable.');
        }
        Storage::fake('public');
        $source = $this->transparentPng(1024);
        $uploaded = $this->actingAs($this->admin())->post('/formaku/api/v1/media', [
            'file' => UploadedFile::fake()->createWithContent('original.png', $source),
            'visibility' => 'public',
            'alt' => 'Test alt',
        ], ['Accept' => 'application/json'])->assertCreated();
        $media = Media::findOrFail($uploaded->json('id'));
        $url = '/formaku/media/'.$media->id;

        $this->get($url)->assertOk()->assertStreamedContent($source);
        foreach ([480 => 60, 960 => 120] as $width => $height) {
            $bytes = $this->get("$url/variants/$width")->assertOk()->assertHeader('Content-Type', 'image/webp')
                ->assertHeader('X-Content-Type-Options', 'nosniff')->streamedContent();
            $dimensions = getimagesizefromstring($bytes);
            $this->assertSame([$width, $height], array_slice($dimensions, 0, 2));
            $image = imagecreatefromstring($bytes);
            $this->assertSame(127, imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha']);
        }
        $this->get("$url/variants/720")->assertNotFound();
        $blocks = [['type' => 'image', 'props' => ['media_id' => $media->id, 'caption' => 'Test caption']]];
        $html = app(Renderer::class)->blocks($blocks);
        $this->assertStringContainsString('src="'.$url.'"', $html);
        $this->assertStringContainsString('srcset="'.$url.'/variants/480 480w, '.$url.'/variants/960 960w, '.$url.' 1024w"', $html);
        $this->assertStringContainsString('alt="Test alt"><figcaption>Test caption</figcaption>', $html);

        Storage::disk('public')->delete($variants->availablePath($media, 480), $variants->availablePath($media, 960));
        $this->assertStringNotContainsString('srcset=', app(Renderer::class)->blocks($blocks));
        $this->get("$url/variants/480")->assertNotFound();
        $this->get($url)->assertOk()->assertStreamedContent($source);
    }

    public function test_media_variants_command_backfills_without_upscaling_or_exposing_private_files(): void
    {
        $variants = app(MediaVariants::class);
        if (! $variants->canGenerate()) {
            $this->markTestSkipped('GD with WebP support is unavailable.');
        }
        Storage::fake('public');
        Storage::fake('local');
        $owner = $this->admin();
        $large = $this->transparentPng(800);
        $legacy = $this->variantSource($owner, 'legacy.png', $large);
        $small = $this->variantSource($owner, 'small.png', $this->transparentPng(320));
        $private = $this->variantSource($owner, 'private.png', $large, 'private');
        $disguised = $this->variantSource($owner, 'disguised.png', '%PDF-1.4');

        $this->artisan('formaku:media-variants')->expectsOutput('Checked 3 public images. Generated 1 WebP variants.')->assertSuccessful();
        $this->artisan('formaku:media-variants')->expectsOutput('Checked 3 public images. Generated 0 WebP variants.')->assertSuccessful();
        $this->assertSame(['original' => 800, 'variants' => [480]], $variants->available($legacy));
        $this->get('/formaku/media/'.$legacy->id.'/variants/960')->assertNotFound();
        $this->get('/formaku/media/'.$legacy->id)->assertOk()->assertStreamedContent($large);
        foreach ([$small, $private, $disguised] as $media) {
            $this->assertNull($variants->available($media));
            $this->get('/formaku/media/'.$media->id.'/variants/480')->assertNotFound();
        }
        $this->get('/formaku/media/'.$private->id)->assertNotFound();
        $this->actingAs($owner)->get('/formaku/media/'.$private->id)->assertOk()->assertStreamedContent($large);
        $this->get('/formaku/media/'.$private->id.'/variants/480')->assertNotFound();
    }

    public function test_media_variants_keep_jpegs_with_nondefault_exif_orientation_as_originals(): void
    {
        $variants = app(MediaVariants::class);
        if (! $variants->canGenerate() || ! function_exists('exif_read_data')) {
            $this->markTestSkipped('GD with WebP and EXIF support is unavailable.');
        }
        Storage::fake('public');
        $image = imagecreatetruecolor(640, 100);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        $exif = hex2bin('4578696600004d4d002a00000008000101120003000000010006000000000000');
        $rotated = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $owner = $this->admin();
        $regular = $this->variantSource($owner, 'regular.jpg', $jpeg, mime: 'image/jpeg');
        $oriented = $this->variantSource($owner, 'oriented.jpg', $rotated, mime: 'image/jpeg');

        $this->assertSame(1, $variants->generate($regular));
        $this->assertSame(0, $variants->generate($oriented));
        $this->get('/formaku/media/'.$oriented->id.'/variants/480')->assertNotFound();
        $this->get('/formaku/media/'.$oriented->id)->assertOk()->assertStreamedContent($rotated);
    }

    private function transparentPng(int $width): string
    {
        $image = imagecreatetruecolor($width, 128);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, 127, imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    private function variantSource(User $owner, string $name, string $bytes, string $visibility = 'public', string $mime = 'image/png'): Media
    {
        $disk = $visibility === 'public' ? 'public' : 'local';
        Storage::disk($disk)->put($name, $bytes);

        return Media::create(['owner_id' => $owner->id, 'disk' => $disk, 'path' => $name, 'name' => $name, 'mime' => $mime, 'visibility' => $visibility, 'size' => strlen($bytes)]);
    }

    public function test_media_usage_follows_current_revisions_and_restored_references(): void
    {
        $admin = $this->admin();
        $media = $this->mediaForUsage($admin);
        $collection = $this->collection();
        $definition = $collection->definition;
        $definition['fields'][] = ['key' => 'attachment', 'label' => 'Attachment', 'type' => 'media'];
        $collection->update(['definition' => $definition]);
        $service = app(RecordService::class);
        $first = $service->save($collection, null, [
            'title' => 'First publication',
            'slug' => 'media-usage',
            'attachment' => (string) $media->id,
            'blocks' => [
                ['type' => 'container', 'children' => [
                    ['type' => 'columns', 'children' => [
                        ['type' => 'image', 'props' => ['media_id' => '00'.$media->id]],
                    ]],
                ]],
            ],
        ], 0, $admin->id);
        $url = '/formaku/api/v1/media/'.$media->id.'/usage';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('data.0', [
            'id' => $first->id,
            'collection' => $collection->name,
            'label' => 'First publication',
            'draft' => true,
            'published' => false,
        ]);
        $this->assertDatabaseCount('formaku_media_usages', 1);

        $published = $service->publish($first, $first->version, $admin->id);
        $draft = $service->save($collection, $published, [
            'title' => 'New draft with image',
            'slug' => 'media-usage',
            'blocks' => [['type' => 'image', 'props' => ['media_id' => $media->id]]],
        ], $published->version, $admin->id);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.label', 'New draft with image')
            ->assertJsonPath('data.0.draft', true)->assertJsonPath('data.0.published', true);

        $draft = $service->save($collection, $draft, [
            'title' => 'Draft without image', 'slug' => 'media-usage',
        ], $draft->version, $admin->id);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.label', 'First publication')
            ->assertJsonPath('data.0.draft', false)->assertJsonPath('data.0.published', true);

        $published = $service->publish($draft, $draft->version, $admin->id);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/formaku/api/v1/records/'.$first->id.'/restore/'.$first->draft_id, [
            'version' => $published->version,
        ])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.draft', true)
            ->assertJsonPath('data.0.published', false);
    }

    public function test_media_usage_scope_filters_records_and_keeps_private_labels_hidden(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $media = $this->mediaForUsage($other);
        $service = app(RecordService::class);
        $records = [];
        foreach (['own', 'assigned', 'none'] as $scope) {
            $collection = Collection::create([
                'key' => $scope,
                'name' => $scope,
                'kind' => 'data',
                'definition' => [
                    'access' => ['editor' => ['scope' => $scope, 'write' => false]],
                    'fields' => [
                        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'private' => true],
                        ['key' => 'file', 'label' => 'File', 'type' => 'media'],
                    ],
                ],
            ]);
            $record = $service->save($collection, null, ['name' => 'private label', 'file' => $media->id], 0, $scope === 'own' ? $editor->id : $other->id);
            if ($scope === 'assigned') {
                $record->update(['assignee_id' => $editor->id]);
            }
            $records[$scope] = $record->id;
            $service->save($collection, null, ['name' => 'out of scope', 'file' => $media->id], 0, $other->id);
        }

        $url = '/formaku/api/v1/media/'.$media->id.'/usage';
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->actingAs($editor)->getJson($url)->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $records['assigned'])->assertJsonPath('data.0.label', '#'.$records['assigned'])
            ->assertJsonPath('data.1.id', $records['own'])->assertJsonPath('data.1.label', '#'.$records['own'])
            ->assertDontSee('private label')->assertDontSee('out of scope');
    }

    public function test_media_usage_migration_backfills_current_revisions_idempotently(): void
    {
        $admin = $this->admin();
        $media = $this->mediaForUsage($admin);
        $collection = $this->collection();
        $definition = $collection->definition;
        $definition['fields'][] = ['key' => 'attachment', 'label' => 'Attachment', 'type' => 'media'];
        $collection->update(['definition' => $definition]);
        DB::table('formaku_schema_versions')->insert([
            'collection_id' => $collection->id,
            'version' => $collection->version,
            'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
        ]);
        $service = app(RecordService::class);
        $old = $service->save($collection, null, ['title' => 'Old', 'attachment' => $media->id], 0, $admin->id);
        $active = $service->save($collection, $old, ['title' => 'Active', 'attachment' => $media->id], $old->version, $admin->id);
        array_pop($definition['fields']);
        $collection->update(['definition' => $definition, 'version' => 2]);
        DB::table('formaku_media_usages')->delete();
        $migration = require __DIR__.'/../../../../packages/cms/database/migrations/2026_11_11_210000_create_formaku_media_usages_table.php';
        $migration->up();
        $migration->up();

        $this->assertDatabaseCount('formaku_media_usages', 1);
        $this->assertDatabaseHas('formaku_media_usages', ['revision_id' => $active->draft_id, 'media_id' => $media->id]);
        $this->actingAs($admin)->getJson('/formaku/api/v1/media/'.$media->id.'/usage')
            ->assertOk()->assertJsonPath('data.0.label', 'Active');
    }

    private function mediaForUsage(User $owner): Media
    {
        return Media::create([
            'owner_id' => $owner->id,
            'disk' => 'public',
            'path' => 'formaku/image.png',
            'name' => 'image.png',
            'mime' => 'image/png',
            'visibility' => 'public',
            'size' => 100,
        ]);
    }

    public function test_publisher_updates_public_media_metadata_without_replacing_the_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('formaku/original.pdf', 'original PDF bytes');
        $owner = User::factory()->create();
        $editor = User::factory()->create(['role' => 'editor']);
        $publisher = User::factory()->create(['role' => 'publisher']);
        $media = Media::create([
            'owner_id' => $owner->id,
            'disk' => 'public',
            'path' => 'formaku/original.pdf',
            'name' => 'original.pdf',
            'mime' => 'application/pdf',
            'visibility' => 'public',
            'alt' => 'Old description',
            'size' => 18,
        ]);

        $url = '/formaku/api/v1/media/'.$media->id;
        $this->actingAs($editor)->patchJson($url, ['name' => 'updated.pdf', 'alt' => null])->assertForbidden();

        $this->actingAs($publisher)->patchJson($url, [
            'name' => 'updated.pdf',
            'alt' => null,
            'owner_id' => $editor->id,
            'disk' => 'local',
            'path' => 'other.pdf',
            'mime' => 'text/plain',
            'visibility' => 'private',
            'size' => 0,
        ])->assertOk()->assertJsonPath('name', 'updated.pdf')->assertJsonPath('alt', '');

        $this->assertDatabaseHas('formaku_media', [
            'id' => $media->id,
            'owner_id' => $owner->id,
            'disk' => 'public',
            'path' => 'formaku/original.pdf',
            'name' => 'updated.pdf',
            'mime' => 'application/pdf',
            'visibility' => 'public',
            'alt' => '',
            'size' => 18,
        ]);
        $this->get('/formaku/media/'.$media->id)->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=updated.pdf')
            ->assertStreamedContent('original PDF bytes');
    }

    public function test_media_metadata_write_requires_staff_and_valid_fields(): void
    {
        $owner = User::factory()->create();
        $media = Media::create([
            'owner_id' => $owner->id,
            'disk' => 'local',
            'path' => 'formaku/private.png',
            'name' => 'private.png',
            'mime' => 'image/png',
            'visibility' => 'private',
            'size' => 100,
        ]);
        $url = '/formaku/api/v1/media/'.$media->id;
        $missingUrl = '/formaku/api/v1/media/'.($media->id + 1);
        $metadata = ['name' => 'updated.png', 'alt' => 'Description'];

        $this->patchJson($url, $metadata)->assertUnauthorized();
        $this->patchJson($missingUrl, $metadata)->assertUnauthorized();
        $this->actingAs($owner)->patchJson($url, $metadata)->assertForbidden();
        $this->patchJson($missingUrl, $metadata)->assertForbidden();

        $this->actingAs($this->admin())->patchJson($url, ['name' => ' '])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'alt']);
        $this->patchJson($missingUrl, $metadata)->assertNotFound();
        $this->patchJson($url, ['name' => str_repeat('x', 256), 'alt' => str_repeat('x', 251)])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'alt']);
        foreach (['folder/file.png', 'folder\\file.png', "line\nbreak.png", "control\x7f.png"] as $invalidName) {
            $this->patchJson($url, ['name' => $invalidName, 'alt' => 'Description'])
                ->assertUnprocessable()->assertJsonValidationErrors('name');
        }

        $this->assertDatabaseHas('formaku_media', ['id' => $media->id, 'name' => 'private.png', 'alt' => '']);
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->patchJson($url, $metadata)->assertOk()->assertJsonPath('alt', 'Description');
    }

    public function test_media_pagination_orders_files_with_the_same_upload_time_by_id(): void
    {
        $staff = $this->admin();
        $uploadedAt = now();
        $ids = [];
        for ($index = 1; $index <= 31; $index++) {
            $ids[] = Media::create([
                'owner_id' => $staff->id,
                'disk' => 'public',
                'path' => "formaku/file-{$index}.png",
                'name' => "file-{$index}.png",
                'mime' => 'image/png',
                'visibility' => 'public',
                'size' => 100,
                'created_at' => $uploadedAt,
            ])->id;
        }
        $expectedOrder = array_reverse($ids);

        $firstPage = $this->actingAs($staff)->getJson('/formaku/api/v1/media')
            ->assertOk()->assertJsonPath('current_page', 1)->assertJsonPath('last_page', 2);
        $secondPage = $this->getJson('/formaku/api/v1/media?page=2')
            ->assertOk()->assertJsonPath('current_page', 2);

        $this->assertSame(array_slice($expectedOrder, 0, 30), array_column($firstPage->json('data'), 'id'));
        $this->assertSame(array_slice($expectedOrder, 30), array_column($secondPage->json('data'), 'id'));

        foreach ([['private', 'image/png', 'hidden.png'], ['public', 'application/pdf', 'manual.pdf']] as [$visibility, $mime, $name]) {
            Media::create([
                'owner_id' => $staff->id,
                'disk' => $visibility === 'public' ? 'public' : 'local',
                'path' => 'formaku/'.$name,
                'name' => $name,
                'mime' => $mime,
                'visibility' => $visibility,
                'size' => 100,
                'created_at' => $uploadedAt,
            ]);
        }

        $picker = '/formaku/api/v1/media?kind=public-image';
        $this->actingAs(User::factory()->create())->getJson($picker)->assertForbidden();
        $firstImagePage = $this->actingAs(User::factory()->create(['role' => 'editor']))->getJson($picker)
            ->assertOk()->assertJsonPath('total', 31)->assertJsonPath('last_page', 2);
        $this->assertStringContainsString('kind=public-image', $firstImagePage->json('next_page_url'));
        $secondImagePage = $this->getJson($picker.'&page=2')->assertOk()->assertJsonPath('current_page', 2);
        $this->assertSame(array_slice($expectedOrder, 0, 30), array_column($firstImagePage->json('data'), 'id'));
        $this->assertSame(array_slice($expectedOrder, 30), array_column($secondImagePage->json('data'), 'id'));
        $this->getJson('/formaku/api/v1/media')->assertOk()->assertJsonPath('total', 33);
    }

    public function test_relation_options_paginate_and_respect_record_and_field_access(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $collection = Collection::create([
            'key' => 'relation-target',
            'name' => 'Relation target',
            'kind' => 'data',
            'definition' => [
                'access' => ['editor' => ['scope' => 'own', 'write' => false]],
                'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'private' => true],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ],
            ],
        ]);
        $service = app(RecordService::class);
        $oldest = $service->save($collection, null, ['title' => 'Private title', 'name' => 'First record'], 0, $editor->id);
        for ($i = 2; $i <= 26; $i++) {
            $service->save($collection, null, ['title' => 'Private title'], 0, $editor->id);
        }
        $hidden = $service->save($collection, null, ['name' => 'Hidden record'], 0, $other->id);
        $url = "/formaku/api/v1/collections/{$collection->id}/relation-options";

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($url)->assertForbidden();
        $this->actingAs($editor)->getJson($url.'?'.http_build_query(['selected' => [$oldest->id, $hidden->id]]))
            ->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('total', 26)->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.label', '#'.($hidden->id - 1))
            ->assertJsonCount(1, 'selected')
            ->assertJsonPath('selected.0', ['id' => $oldest->id, 'label' => 'First record'])
            ->assertDontSee('Private title')->assertDontSee('Hidden record');
        $this->getJson($url.'?page=2')->assertOk()->assertJsonPath('data.0.id', $oldest->id);
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
