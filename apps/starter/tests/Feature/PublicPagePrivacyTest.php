<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_public_page_and_its_listings_use_only_the_fields_that_are_currently_public(): void
    {
        $editor = User::factory()->create(['role' => 'admin']);
        $pages = $this->pages('articles');
        $record = $this->publish($pages, $editor, [
            'slug' => 'alpha',
            'title' => 'Synthetic title Z',
            'name' => 'Visible name one',
            'blocks' => [['type' => 'hero', 'props' => ['title' => 'Synthetic private body']]],
        ]);
        $this->publish($pages, $editor, [
            'slug' => 'beta',
            'title' => 'Synthetic title A',
            'name' => 'Visible name two',
        ]);
        $this->publishCards($pages, $editor);

        $this->get('/p/alpha')->assertOk()
            ->assertSeeHtml('<title>Synthetic title Z</title>')
            ->assertSee('Synthetic private body');
        $this->get('/')->assertOk()->assertSeeInOrder(['Synthetic title A', 'Synthetic title Z']);
        $this->get('/p/showcase')->assertOk()->assertSee('Synthetic title Z');

        $pages = $this->setPrivate($pages, 'title', 'blocks');
        $this->get('/p/alpha')->assertOk()->assertSeeHtml('<title>Visible name one</title>')
            ->assertDontSee('Synthetic title Z')->assertDontSee('Synthetic private body');
        $this->get('/')->assertOk()->assertSeeInOrder(['Visible name one', 'Visible name two'])
            ->assertDontSee('Synthetic title Z')->assertDontSee('Synthetic title A');
        $this->get('/p/showcase')->assertOk()->assertSee('Visible name one')
            ->assertDontSee('Synthetic title Z')->assertDontSee('Synthetic title A');

        $this->actingAs($editor)->get('/formaku/api/v1/records/'.$record->id.'/preview')
            ->assertOk()->assertSee('Synthetic private body');
        $this->setPrivate($pages, 'name');
        $this->get('/p/alpha')->assertOk()->assertSeeHtml('<title>alpha</title>')->assertDontSee('Visible name one');
        $this->get('/')->assertOk()->assertSeeHtml('>alpha</h2>')->assertDontSee('Visible name one');
        $this->get('/p/showcase')->assertOk()->assertSeeHtml('>alpha</a>')->assertDontSee('Visible name one');
        $this->assertSame('Synthetic title Z', $record->fresh()->published_title);
    }

    public function test_legacy_private_slug_hides_the_collection_from_public_page_surfaces(): void
    {
        config(['app.url' => 'https://example.test']);
        $editor = User::factory()->create(['role' => 'admin']);
        $pages = $this->pages('legacy');
        $service = app(RecordService::class);
        $first = $this->publish($pages, $editor, ['slug' => 'legacy-old', 'title' => 'Legacy first page']);
        $first = $service->save($pages, $first, ['slug' => 'legacy-first', 'title' => 'Legacy first page'], $first->version, $editor->id);
        $first = $service->publish($first, $first->version, $editor->id);
        $second = $this->publish($pages, $editor, ['slug' => 'legacy-second', 'title' => 'Legacy second page']);
        $this->publishCards($pages, $editor);

        $this->actingAs($editor);
        foreach ([[$first, 'en'], [$second, 'pl']] as [$record, $locale]) {
            $this->putJson('/formaku/api/v1/records/'.$record->id.'/translations', [
                'group' => 'legacy', 'locale' => $locale, 'version' => 0,
            ])->assertOk();
        }
        $this->get('/p/legacy-first')->assertOk()
            ->assertSeeHtml('<link rel="alternate" hreflang="pl" href="https://example.test/p/legacy-second">');
        $this->get('/p/legacy-old')->assertRedirect('/p/legacy-first');
        $this->get('/')->assertSee('/p/legacy-first');
        $this->get('/p/showcase')->assertSee('/p/legacy-second');
        $this->assertStringContainsString('/p/legacy-first', $this->get('/sitemap.xml')->streamedContent());

        $this->setPrivate($pages, 'slug');
        $this->get('/p/legacy-first')->assertNotFound();
        $this->get('/p/legacy-second')->assertNotFound();
        $this->get('/p/legacy-old')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('/p/legacy-first')->assertDontSee('/p/legacy-second');
        $this->get('/p/showcase')->assertOk()->assertDontSee('/p/legacy-first')->assertDontSee('/p/legacy-second');
        $sitemap = $this->get('/sitemap.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString('/p/showcase', $sitemap);
        $this->assertStringNotContainsString('/p/legacy-', $sitemap);

        $this->assertSame('legacy-first', $first->fresh()->published_slug);
        $this->assertDatabaseHas('formaku_slug_redirects', ['slug' => 'legacy-old', 'record_id' => $first->id]);
    }

    public function test_a_schema_can_keep_a_private_slug_only_while_the_collection_is_not_public_content(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $input = [
            'key' => 'private_addresses',
            'name' => 'Private addresses',
            'kind' => 'content',
            'definition' => [
                'public' => true,
                'fields' => [
                    ['key' => 'slug', 'label' => 'Slug', 'type' => 'text', 'private' => true],
                ],
            ],
        ];

        $this->postJson('/formaku/api/v1/collections', $input)->assertUnprocessable()
            ->assertJsonValidationErrors('definition.fields.0.private');

        $input['definition']['public'] = false;
        $this->postJson('/formaku/api/v1/collections', $input)->assertCreated()
            ->assertJsonPath('definition.fields.0.private', true);
    }

    private function pages(string $key): Collection
    {
        return Collection::create([
            'key' => $key,
            'name' => ucfirst($key),
            'kind' => 'content',
            'definition' => [
                'public' => true,
                'fields' => [
                    ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                    ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext'],
                ],
            ],
        ]);
    }

    private function publish(Collection $collection, User $editor, array $data): Record
    {
        $service = app(RecordService::class);
        $record = $service->save($collection, null, $data, 0, $editor->id);

        return $service->publish($record, $record->version, $editor->id);
    }

    private function publishCards(Collection $collection, User $editor): void
    {
        $this->publish($this->pages('showcases'), $editor, [
            'slug' => 'showcase',
            'title' => 'Showcase',
            'blocks' => [['type' => 'cards', 'props' => ['collection_id' => $collection->id]]],
        ]);
    }

    private function setPrivate(Collection $collection, string ...$keys): Collection
    {
        $definition = $collection->definition;
        foreach ($definition['fields'] as &$field) {
            if (in_array($field['key'], $keys, true)) {
                $field['private'] = true;
            }
        }
        $collection->update(['definition' => $definition]);

        return $collection->fresh();
    }
}
