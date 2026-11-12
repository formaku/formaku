<?php

namespace Tests\Feature;

use App\Formaku\NoticeBlock;
use App\Models\User;
use Formaku\Cms\Blocks\Block;
use Formaku\Cms\Blocks\Hero;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Registry;
use Formaku\Cms\Services\Renderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class BlockTypeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    private function pages(): Collection
    {
        return Collection::create(['key' => 'pages', 'name' => 'Pages', 'kind' => 'content', 'definition' => ['public' => true, 'fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
            ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'],
            ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext'],
        ]]]);
    }

    private function registerPanel(): void
    {
        app(Registry::class)->block(new class extends Block
        {
            public function key(): string
            {
                return 'panel';
            }

            public function label(): string
            {
                return 'Panel';
            }

            public function panelSchema(): array
            {
                return [['key' => 'heading', 'label' => 'Heading', 'input' => 'text']];
            }

            public function acceptsChildren(): bool
            {
                return true;
            }

            public function render(array $props, string $children = ''): string
            {
                return '<aside class="panel"><h2>'.e($props['heading'] ?? '').'</h2>'.$children.'</aside>';
            }
        });
    }

    public function test_registered_block_renders_with_nested_blocks_on_the_public_page(): void
    {
        $this->registerPanel();
        $admin = $this->admin();
        $records = app(RecordService::class);
        $record = $records->save($this->pages(), null, ['title' => 'Offer', 'slug' => 'offer', 'blocks' => [
            ['type' => 'container', 'children' => [
                ['type' => 'panel', 'props' => ['heading' => 'Tom & Co'], 'children' => [
                    ['type' => 'hero', 'props' => ['title' => 'Inside']],
                ]],
            ]],
        ]], 0, $admin->id);
        $records->publish($record, 1, $admin->id);

        $this->get('/p/offer')->assertOk()->assertSee(
            '<section class="block-layout"><aside class="panel"><h2>Tom &amp; Co</h2><section class="hero-block"><small></small><h1>Inside</h1><p></p></section></aside></section>',
            false,
        );
    }

    public function test_bootstrap_describes_registered_blocks_without_changing_the_built_in_list(): void
    {
        $this->registerPanel();

        $this->actingAs($this->admin())->getJson('/formaku/api/v1/bootstrap')->assertOk()
            ->assertJsonPath('blocks', ['hero', 'text', 'cta', 'image', 'cards', 'faq', 'container', 'columns'])
            ->assertJsonCount(9, 'blockTypes')
            ->assertJsonFragment(['key' => 'panel', 'label' => 'Panel', 'children' => true, 'schema' => [['key' => 'heading', 'label' => 'Heading', 'input' => 'text']]])
            ->assertJsonFragment(['key' => 'columns', 'label' => 'Kolumny', 'children' => true]);
    }

    public function test_images_inside_a_registered_layout_block_count_as_media_usage(): void
    {
        $this->registerPanel();
        $admin = $this->admin();
        $media = Media::create(['owner_id' => $admin->id, 'disk' => 'public', 'path' => 'photo.jpg', 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'visibility' => 'public', 'size' => 1]);

        $record = app(RecordService::class)->save($this->pages(), null, ['title' => 'Gallery', 'blocks' => [
            ['type' => 'panel', 'children' => [['type' => 'image', 'props' => ['media_id' => $media->id]]]],
        ]], 0, $admin->id);

        $this->assertDatabaseHas('formaku_media_usages', ['revision_id' => $record->draft_id, 'media_id' => $media->id]);
    }

    public function test_malformed_blocks_are_rejected_when_publishing(): void
    {
        $admin = $this->admin();
        $records = app(RecordService::class);
        $pages = $this->pages();

        foreach ([
            [['type' => 'hero', 'props' => 'title']],
            [['type' => 'container', 'children' => 'hero']],
            ['hero'],
            [['type' => 'panel']],
        ] as $index => $blocks) {
            $record = $records->save($pages, null, ['title' => 'Broken', 'slug' => 'broken-'.$index, 'blocks' => $blocks], 0, $admin->id);
            try {
                $records->publish($record, 1, $admin->id);
                $this->fail('Published malformed blocks '.$index);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('blocks', $exception->errors());
            }
            $this->get('/p/broken-'.$index)->assertNotFound();
        }
    }

    public function test_shipped_extension_renders_through_the_existing_contract(): void
    {
        require_once dirname(__DIR__, 4).'/docs/developer/examples/app/Formaku/NoticeBlock.php';
        app(Registry::class)->block(new NoticeBlock);

        $html = app(Renderer::class)->blocks([['type' => 'example_notice', 'props' => ['message' => 'A & B']]]);
        $this->assertSame('<p>A &amp; B</p>', $html);
        $this->actingAs($this->admin())->getJson('/formaku/api/v1/bootstrap')->assertOk()->assertJsonCount(8, 'blockTypes');
    }

    public function test_registry_refuses_to_replace_a_built_in_block(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(Registry::class)->block(new Hero);
    }
}
