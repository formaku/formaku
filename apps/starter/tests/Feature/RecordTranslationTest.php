<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saves_and_unlinks_translation_metadata_with_an_independent_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $record = $this->draft($this->collection('pages'), $admin, 'legacy-page');
        $record = app(RecordService::class)->publish($record, $record->version, $admin->id);
        $url = $this->translationsUrl($record);

        $this->actingAs($admin)->getJson($url)->assertOk()->assertExactJson(['locale' => null, 'group' => null, 'version' => 0]);
        $this->putJson($url, ['locale' => 'ZH-hAnT-tw', 'group' => 'About-Us', 'version' => 0])
            ->assertOk()->assertExactJson(['locale' => 'zh-Hant-TW', 'group' => 'about-us', 'version' => 1]);
        $this->getJson($url)->assertExactJson(['locale' => 'zh-Hant-TW', 'group' => 'about-us', 'version' => 1]);
        $this->putJson($url, ['locale' => 'es-419', 'group' => 'about-us', 'version' => 0])->assertConflict();
        $this->putJson($url, ['locale' => null, 'group' => null, 'version' => 1])
            ->assertOk()->assertExactJson(['locale' => null, 'group' => null, 'version' => 2]);
        $this->putJson($url, ['locale' => 'es-419', 'group' => 'about-us', 'version' => 0])->assertConflict();
        $this->getJson($url)->assertExactJson(['locale' => null, 'group' => null, 'version' => 2]);
        $this->putJson($url, ['locale' => 'ES-419', 'group' => 'about-us', 'version' => 2])
            ->assertOk()->assertExactJson(['locale' => 'es-419', 'group' => 'about-us', 'version' => 3]);
        $this->assertDatabaseHas('formaku_records', [
            'id' => $record->id,
            'version' => $record->version,
            'draft_id' => $record->draft_id,
            'published_id' => $record->published_id,
        ]);
        $this->assertDatabaseCount('formaku_revisions', 1);
    }

    public function test_rejects_a_duplicate_language_in_one_group_and_collection_with_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pages = $this->collection('pages');
        $first = $this->translationsUrl($this->draft($pages, $admin, 'start-en'));
        $second = $this->translationsUrl($this->draft($pages, $admin, 'start-pl'));
        $other = $this->translationsUrl($this->draft($this->collection('news'), $admin, 'news-en'));

        $this->actingAs($admin)->putJson($first, ['locale' => 'en', 'group' => 'start', 'version' => 0])->assertOk();
        $this->putJson($second, ['locale' => 'EN', 'group' => 'START', 'version' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->getJson($second)->assertExactJson(['locale' => null, 'group' => null, 'version' => 0]);
        $this->putJson($second, ['locale' => 'pl', 'group' => 'start', 'version' => 0])->assertOk();
        $this->putJson($second, ['locale' => 'en', 'group' => 'start', 'version' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->getJson($second)->assertExactJson(['locale' => 'pl', 'group' => 'start', 'version' => 1]);
        $this->putJson($other, ['locale' => 'en', 'group' => 'start', 'version' => 0])->assertOk();
        $this->putJson($first, ['locale' => null, 'group' => null, 'version' => 1])->assertOk();
        $this->putJson($second, ['locale' => 'en', 'group' => 'start', 'version' => 1])->assertOk();
    }

    public function test_rejects_incomplete_or_invalid_link_payloads_with_422(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $url = $this->translationsUrl($this->draft($this->collection('pages'), $admin, 'start'));
        $this->actingAs($admin);

        $this->putJson($url, ['group' => null, 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->putJson($url, ['locale' => null, 'group' => 'start', 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->putJson($url, ['locale' => 'pl', 'group' => null, 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->putJson($url, ['locale' => 'pl_PL', 'group' => 'start', 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->putJson($url, ['locale' => 'pl', 'group' => 'two--parts', 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->putJson($url, ['locale' => 'pl', 'group' => str_repeat('a', 101), 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors('group');
        $this->putJson($url, ['locale' => 'pl', 'group' => 'start', 'version' => -1])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->assertDatabaseCount('formaku_record_translations', 0);
    }

    public function test_both_endpoints_enforce_publish_ability_collection_write_and_record_scope(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $publisher = User::factory()->create(['role' => 'publisher']);
        $pages = $this->collection('pages');
        $record = $this->draft($pages, $admin, 'admin-page');
        $own = $this->draft($pages, $publisher, 'publisher-page');
        $url = $this->translationsUrl($record);
        $input = ['locale' => 'pl', 'group' => 'start', 'version' => 0];

        $this->getJson($url)->assertUnauthorized();
        $this->putJson($url, $input)->assertUnauthorized();
        $this->actingAs($editor)->getJson($url)->assertForbidden();
        $this->putJson($url, $input)->assertForbidden();

        $definition = $pages->definition + ['access' => ['publisher' => ['scope' => 'all', 'write' => false]]];
        $pages->update(['definition' => $definition]);
        $this->actingAs($publisher)->getJson($url)->assertForbidden();
        $this->putJson($url, $input)->assertForbidden();
        $definition['access']['publisher'] = ['scope' => 'own', 'write' => true];
        $pages->update(['definition' => $definition]);
        $this->getJson($url)->assertNotFound();
        $this->putJson($url, $input)->assertNotFound();
        $this->getJson($this->translationsUrl($own))->assertOk();
        $this->putJson($this->translationsUrl($own), $input)->assertOk();

        $private = $this->draft($this->collection('private-pages', public: false), $admin, 'private-page');
        $data = $this->draft($this->collection('data', kind: 'data'), $admin, 'data-record');
        foreach ([$private, $data] as $unsupported) {
            $this->actingAs($admin)->getJson($this->translationsUrl($unsupported))->assertNotFound();
            $this->putJson($this->translationsUrl($unsupported), $input)->assertNotFound();
        }
        $this->assertDatabaseCount('formaku_record_translations', 1);
    }

    private function collection(string $key, string $kind = 'content', bool $public = true): Collection
    {
        return Collection::create([
            'key' => $key,
            'name' => ucfirst($key),
            'kind' => $kind,
            'definition' => [
                'public' => $public,
                'fields' => [
                    ['key' => 'title', 'type' => 'text', 'label' => 'Title'],
                    ['key' => 'slug', 'type' => 'text', 'label' => 'Slug'],
                ],
            ],
        ]);
    }

    private function draft(Collection $collection, User $owner, string $slug): Record
    {
        return app(RecordService::class)->save($collection, null, ['title' => 'Page title', 'slug' => $slug], 0, $owner->id);
    }

    private function translationsUrl(Record $record): string
    {
        return "/formaku/api/v1/records/{$record->id}/translations";
    }
}
