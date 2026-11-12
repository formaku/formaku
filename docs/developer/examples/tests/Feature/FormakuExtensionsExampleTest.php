<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormakuExtensionsExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_block_reaches_preview_and_publication(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $collection = $this->postJson('/formaku/api/v1/collections', [
            'key' => 'example_pages',
            'name' => 'Example pages',
            'kind' => 'content',
            'definition' => [
                'public' => true,
                'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                    ['key' => 'slug', 'label' => 'Slug', 'type' => 'text', 'required' => true],
                    ['key' => 'blocks', 'label' => 'Blocks', 'type' => 'richtext'],
                ],
            ],
        ])->assertSuccessful()->json('id');

        $draft = $this->postJson("/formaku/api/v1/collections/{$collection}/records", [
            'version' => 0,
            'data' => [
                'title' => 'Extension example',
                'slug' => 'extension-example',
                'blocks' => [
                    ['type' => 'example_notice', 'props' => ['message' => 'Notes <b>for editors</b>']],
                ],
            ],
        ])->assertSuccessful();

        $id = $draft->json('id');
        $this->get('/p/extension-example')->assertNotFound();
        $this->get("/formaku/api/v1/records/{$id}/preview")->assertOk()
            ->assertSeeHtml('<p>Notes &lt;b&gt;for editors&lt;/b&gt;</p>');

        $this->postJson("/formaku/api/v1/records/{$id}/publish", [
            'version' => $draft->json('version'),
        ])->assertSuccessful();

        $this->app['auth']->forgetGuards();
        $this->get('/p/extension-example')->assertOk()
            ->assertSeeHtml('<p>Notes &lt;b&gt;for editors&lt;/b&gt;</p>')
            ->assertDontSeeHtml('Notes <b>for editors</b>');
    }

    public function test_host_routes_require_authentication_and_only_expose_the_callers_profile(): void
    {
        $this->getJson('/extension-examples/profile')->assertUnauthorized();
        $this->postJson('/extension-examples/preview-notice', ['message' => 'Draft'])->assertUnauthorized();

        User::factory()->create(['name' => 'Other profile']);
        $user = User::factory()->create(['name' => 'Own profile']);
        $this->actingAs($user)->getJson('/extension-examples/profile?id=1')
            ->assertOk()->assertExactJson(['id' => $user->id, 'name' => 'Own profile']);

        $this->postJson('/extension-examples/preview-notice', ['message' => '  Draft note  '])
            ->assertOk()->assertExactJson(['message' => 'Draft note']);
        $this->postJson('/extension-examples/preview-notice', ['message' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
    }

    public function test_panel_uses_the_registered_resource_and_action_for_the_signed_in_user(): void
    {
        $base = '/formaku/api/v1';
        $resource = $base.'/extensions/resources/example_own_profile';
        $action = $base.'/extensions/actions/example_preview_notice';
        $this->getJson($resource)->assertUnauthorized();
        $this->postJson($action, ['input' => ['message' => 'Draft']])->assertUnauthorized();

        User::factory()->create(['name' => 'Other profile']);
        $user = User::factory()->create(['name' => 'Own profile', 'role' => 'admin']);
        $this->actingAs($user)->getJson($base.'/bootstrap')->assertOk()
            ->assertJsonPath('extensions.resources.0.key', 'example_own_profile')
            ->assertJsonPath('extensions.actions.0.key', 'example_preview_notice');
        $this->getJson($resource)->assertOk()->assertExactJson([
            'data' => [['id' => $user->id, 'name' => 'Own profile']],
            'current_page' => 1,
            'has_more' => false,
        ]);

        $this->postJson($action, ['input' => ['message' => '  Panel notice  ']])
            ->assertOk()->assertExactJson(['result' => ['message' => 'Panel notice']]);
        $this->postJson($action, ['input' => ['message' => str_repeat('x', 161)]])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
    }
}
