<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\PageTemplate;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldPolicySurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_field_policy_applies_to_existing_assistant_connections_and_new_pairings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $collection = Collection::create([
            'key' => 'pages',
            'name' => 'Pages',
            'kind' => 'content',
            'definition' => ['public' => true, 'fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
            ]],
        ]);
        $settings = '/formaku/api/v1/assistant';
        $api = '/formaku/assistant/v1';
        $pairing = ['collection_id' => $collection->id, 'can_publish' => false];
        $this->actingAs($admin);
        $code = $this->postJson($settings.'/pairing', $pairing)->assertCreated()->json('code');
        $token = $this->postJson($api.'/exchange', ['code' => $code])->assertOk()->json('access_token');
        $this->getJson($api.'/connection', ['Authorization' => 'Bearer '.$token])->assertOk();

        $definition = $collection->definition;
        $definition['fields'][0]['access'] = ['write' => ['editor']];
        $collection->update(['definition' => $definition]);

        $this->getJson($settings)->assertOk()->assertJsonPath('collections', []);
        $this->postJson($settings.'/pairing', $pairing)->assertUnprocessable();
        $this->getJson($api.'/connection', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_template_listing_and_preview_require_permission_to_write_blocks(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $collection = Collection::create([
            'key' => 'pages',
            'name' => 'Pages',
            'kind' => 'content',
            'definition' => ['fields' => [
                ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext', 'access' => ['write' => []]],
            ]],
        ]);
        $template = PageTemplate::create(['collection_id' => $collection->id, 'name' => 'Layout', 'blocks' => []]);
        $endpoint = "/formaku/api/v1/collections/{$collection->id}/page-templates";

        $this->actingAs($editor)->getJson($endpoint)->assertForbidden();
        $this->getJson($endpoint.'/'.$template->id)->assertForbidden();
        $this->actingAs($admin)->getJson($endpoint)->assertOk()->assertJsonPath('data.0.name', 'Layout');
        $this->getJson($endpoint.'/'.$template->id)->assertOk()->assertJsonPath('blocks', []);
    }

    public function test_media_usage_omits_collections_with_unreadable_reference_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $media = Media::create([
            'owner_id' => $admin->id,
            'disk' => 'local',
            'path' => 'synthetic.pdf',
            'name' => 'synthetic.pdf',
            'mime' => 'application/pdf',
            'visibility' => 'public',
            'size' => 1,
        ]);
        $service = app(RecordService::class);
        foreach (['visible', 'restricted'] as $key) {
            $file = ['key' => 'file', 'label' => 'File', 'type' => 'media'];
            if ($key === 'restricted') {
                $file += ['private' => true, 'access' => ['read' => []]];
            }
            $collection = Collection::create([
                'key' => $key,
                'name' => $key,
                'kind' => 'data',
                'definition' => ['fields' => [$file]],
            ]);
            $service->save($collection, null, ['file' => $media->id], 0, $admin->id, actorUser: $admin);
        }

        $endpoint = "/formaku/api/v1/media/{$media->id}/usage";
        $this->actingAs($editor)->getJson($endpoint)->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.collection', 'visible');
        $this->actingAs($admin)->getJson($endpoint)->assertOk()->assertJsonPath('total', 2);
    }

    public function test_portal_submission_checks_field_write_permission(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);
        Collection::create([
            'key' => 'requests',
            'name' => 'Requests',
            'kind' => 'data',
            'definition' => ['fields' => [
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'access' => ['write' => ['editor']]],
                ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
            ]],
        ]);

        $this->actingAs($customer)->post('/portal/requests', ['title' => 'Synthetic', 'description' => 'Synthetic'])
            ->assertForbidden();
        $this->assertDatabaseCount('formaku_records', 0);
    }
}
