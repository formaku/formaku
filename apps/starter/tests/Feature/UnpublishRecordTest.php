<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnpublishRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpublishing_hides_every_public_address_until_the_page_is_republished(): void
    {
        $this->travelTo(Date::parse('2026-11-12T12:00:00Z'));
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = $this->collection();
        $records = app(RecordService::class);
        $first = $records->save($collection, null, ['title' => 'First page', 'slug' => 'first'], 0, $admin->id);
        $first = $records->publish($first, $first->version, $admin->id);
        $current = $records->save($collection, $first, ['title' => 'Withdrawn live title', 'slug' => 'current'], $first->version, $admin->id);
        $current = $records->publish($current, $current->version, $admin->id);
        $draft = $records->save($collection, $current, ['title' => 'New draft title', 'slug' => 'new-address'], $current->version, $admin->id);
        $other = $records->save($collection, null, ['title' => 'Alternate page', 'slug' => 'alternate'], 0, $admin->id);
        $other = $records->publish($other, $other->version, $admin->id);
        $api = '/formaku/api/v1/records/'.$draft->id;
        $this->actingAs($admin);
        foreach ([[$draft, 'pl'], [$other, 'en']] as [$record, $locale]) {
            $this->putJson("/formaku/api/v1/records/{$record->id}/translations", [
                'group' => 'about',
                'locale' => $locale,
                'version' => 0,
            ])->assertOk();
        }
        $scheduled = $this->postJson($api.'/schedule', [
            'version' => $draft->version,
            'publish_at' => '2026-11-13T12:00:00Z',
        ])->assertOk();

        $this->get('/p/first')->assertStatus(301)->assertHeader('Location', '/p/current');
        $this->get('/p/current')->assertOk()->assertSeeHtml('<title>Withdrawn live title</title>');
        $this->get('/p/alternate')->assertOk()->assertSee('rel="alternate" hreflang="pl"', false);

        $withdrawn = $this->deleteJson($api.'/publish', ['version' => $scheduled->json('version')])
            ->assertOk()
            ->assertJsonPath('id', $draft->id)
            ->assertJsonPath('draft_id', $draft->draft_id)
            ->assertJsonPath('published_id', null)
            ->assertJsonPath('published_slug', null)
            ->assertJsonPath('published_title', null)
            ->assertJsonPath('search_text', null)
            ->assertJsonPath('publish_at', null)
            ->assertJsonPath('version', $scheduled->json('version') + 1);

        $this->assertSame(
            [$first->published_id, $current->published_id, $draft->draft_id],
            Revision::where('record_id', $draft->id)->orderBy('id')->pluck('id')->all(),
        );
        $this->getJson($api)->assertOk()->assertJsonPath('data.title', 'New draft title')->assertJsonCount(3, 'history');
        $this->assertDatabaseHas('formaku_record_translations', ['record_id' => $draft->id, 'group_key' => 'about', 'locale' => 'pl']);
        $events = DB::table('formaku_events')->where('record_id', $draft->id)->where('type', 'unpublished');
        $this->assertSame(1, $events->count());
        $this->assertDatabaseHas('formaku_events', ['record_id' => $draft->id, 'type' => 'unpublished', 'actor_id' => $admin->id]);
        foreach (['first', 'current'] as $slug) {
            $this->assertDatabaseHas('formaku_slug_redirects', ['slug' => $slug, 'record_id' => $draft->id]);
            $this->get('/p/'.$slug)->assertNotFound()->assertHeaderMissing('Location');
        }
        $this->get('/p/new-address')->assertNotFound();
        $this->get('/p/alternate')->assertOk()->assertDontSee('rel="alternate" hreflang="pl"', false);
        $this->get('/')->assertOk()->assertSee('Alternate page')->assertDontSee('Withdrawn live title');
        $sitemap = $this->get('/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('/p/alternate</loc>', $sitemap);
        $this->assertStringNotContainsString('/p/current</loc>', $sitemap);
        $this->assertStringNotContainsString('/p/first</loc>', $sitemap);

        $this->travelTo(Date::parse('2026-11-13T13:00:00Z'));
        $this->artisan('formaku:publish-scheduled')->assertSuccessful();
        $this->get('/p/new-address')->assertNotFound();
        $this->postJson($api.'/publish', ['version' => $scheduled->json('version')])->assertConflict();
        $this->deleteJson($api.'/publish', ['version' => $withdrawn->json('version')])
            ->assertOk()->assertJsonPath('version', $withdrawn->json('version'));
        $this->assertSame(1, $events->count());

        $this->postJson($api.'/publish', ['version' => $withdrawn->json('version')])
            ->assertOk()->assertJsonPath('published_id', $draft->draft_id);
        $this->get('/p/new-address')->assertOk()->assertSeeHtml('<title>New draft title</title>');
        $this->get('/p/alternate')->assertOk()->assertSee('rel="alternate" hreflang="pl"', false)->assertSee('/p/new-address', false);
        foreach (['first', 'current'] as $slug) {
            $this->get('/p/'.$slug)->assertStatus(301)->assertHeader('Location', '/p/new-address');
        }

        $reused = $records->save($collection, null, ['title' => 'Another page', 'slug' => 'current'], 0, $admin->id);
        $records->publish($reused, $reused->version, $admin->id);
        $this->get('/p/current')->assertOk()->assertSeeHtml('<title>Another page</title>');
    }

    public function test_unpublishing_an_unpublished_page_keeps_its_first_publication_scheduled(): void
    {
        $this->travelTo(Date::parse('2026-11-12T12:00:00Z'));
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = app(RecordService::class)->save($this->collection(), null, ['title' => 'First draft'], 0, $admin->id);
        $api = '/formaku/api/v1/records/'.$draft->id;
        $scheduled = $this->actingAs($admin)->postJson($api.'/schedule', [
            'version' => $draft->version,
            'publish_at' => '2026-11-13T12:00:00Z',
        ])->assertOk();

        $this->deleteJson($api.'/publish', ['version' => $draft->version])->assertConflict();
        $this->deleteJson($api.'/publish', ['version' => $scheduled->json('version')])
            ->assertOk()
            ->assertJsonPath('version', $scheduled->json('version'))
            ->assertJsonPath('publish_at', '2026-11-13T12:00:00.000000Z');
        $this->assertDatabaseMissing('formaku_events', ['record_id' => $draft->id, 'type' => 'unpublished']);
    }

    public function test_unpublish_requires_current_version_publish_permission_and_collection_write_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $owner = User::factory()->create(['role' => 'publisher']);
        $other = User::factory()->create(['role' => 'publisher']);
        $collection = $this->collection('content', [
            'editor' => ['scope' => 'all', 'write' => true],
            'publisher' => ['scope' => 'own', 'write' => false],
        ]);
        $records = app(RecordService::class);
        $record = $records->save($collection, null, ['title' => 'Published', 'slug' => 'protected'], 0, $owner->id);
        $record = $records->publish($record, $record->version, $admin->id);
        $api = '/formaku/api/v1/records/'.$record->id.'/publish';
        $version = ['version' => $record->version];

        $this->deleteJson($api, $version)->assertUnauthorized();
        $this->actingAs($editor)->deleteJson($api, $version)->assertForbidden();
        $this->actingAs($other)->deleteJson($api, $version)->assertNotFound();
        $this->actingAs($owner)->deleteJson($api, $version)->assertForbidden();
        $this->actingAs($admin)->deleteJson($api, ['version' => $record->version - 1])->assertConflict();
        $this->deleteJson($api)->assertUnprocessable()->assertJsonValidationErrors('version');

        $data = $records->save($this->collection('data'), null, ['title' => 'Data'], 0, $admin->id);
        $this->deleteJson('/formaku/api/v1/records/'.$data->id.'/publish', ['version' => $data->version])->assertUnprocessable();
        $this->assertSame($record->published_id, $record->fresh()->published_id);
        $this->assertDatabaseMissing('formaku_events', ['record_id' => $record->id, 'type' => 'unpublished']);

        $definition = $collection->definition;
        $definition['access']['publisher']['write'] = true;
        $collection->update(['definition' => $definition]);
        $this->actingAs($owner)->deleteJson($api, $version)->assertOk()->assertJsonPath('published_id', null);
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
                    ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                ],
            ],
        ]);
    }
}
