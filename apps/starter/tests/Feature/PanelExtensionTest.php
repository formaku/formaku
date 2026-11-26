<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Contracts\ActionHandler;
use Formaku\Cms\Contracts\ResourceAdapter;
use Formaku\Cms\Http\PanelExtensionController;
use Formaku\Cms\Models\Release;
use Formaku\Cms\Services\Registry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelExtensionTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/formaku/api/v1/';

    public function test_panel_resources_page_only_the_adapters_scoped_and_projected_attributes(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $prefix = 'Team '.$viewer->id.' ';
        for ($index = 1; $index <= 26; $index++) {
            User::factory()->create(['name' => $prefix.$index]);
        }
        User::factory()->create(['name' => 'Unrelated user']);

        app(Registry::class)->resource($this->resource(), $this->resourceDefinition());
        $url = self::PREFIX.'extensions/resources/team-members';

        $this->actingAs($viewer)->getJson(self::PREFIX.'bootstrap')
            ->assertOk()
            ->assertJsonPath('extensions.resources', [[
                'key' => 'team-members',
                'label' => 'Team members',
                'columns' => $this->resourceDefinition()['columns'],
            ]]);
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0', ['display_name' => $prefix.'1', 'missing' => null])
            ->assertJsonPath('data.24', ['display_name' => $prefix.'25', 'missing' => null])
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('has_more', true);
        $this->getJson($url.'?page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => [['display_name' => $prefix.'26', 'missing' => null]],
                'current_page' => 2,
                'has_more' => false,
            ]);
        $this->getJson($url.'?page=3')
            ->assertOk()
            ->assertExactJson(['data' => [], 'current_page' => 3, 'has_more' => false]);
        $this->getJson($url.'?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
    }

    public function test_resource_pagination_preserves_bounds_added_by_the_adapter_scope(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $prefix = 'Team '.$viewer->id.' ';
        for ($index = 1; $index <= 29; $index++) {
            User::factory()->create(['name' => $prefix.$index]);
        }

        $bounds = static fn (Builder $query) => $query->offset(1)->limit(26);
        app(Registry::class)->resource($this->resource($bounds), $this->resourceDefinition());
        $url = self::PREFIX.'extensions/resources/team-members';

        $this->actingAs($viewer)->getJson($url)
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('data.0.display_name', $prefix.'2')
            ->assertJsonPath('data.24.display_name', $prefix.'26')
            ->assertJsonPath('has_more', true);
        $this->getJson($url.'?page=2')
            ->assertOk()
            ->assertExactJson([
                'data' => [['display_name' => $prefix.'27', 'missing' => null]],
                'current_page' => 2,
                'has_more' => false,
            ]);
        $this->getJson($url.'?page=3')
            ->assertOk()
            ->assertExactJson(['data' => [], 'current_page' => 3, 'has_more' => false]);

        $perGroup = static fn (Builder $query) => $query->groupLimit(1, 'role');
        app(Registry::class)->resource($this->resource($perGroup), $this->resourceDefinition());
        $this->getJson($url)->assertUnprocessable();
    }

    public function test_resource_does_not_bypass_adapter_post_query_filters(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $prefix = 'Team '.$viewer->id.' ';
        User::factory()->create(['name' => $prefix.'hidden']);
        User::factory()->create(['name' => $prefix.'visible']);

        $filter = static fn (Builder $query) => $query->afterQuery(
            static fn (Collection $rows): Collection => $rows->filter(
                static fn (User $user): bool => $user->getRawOriginal('display_name') === $prefix.'visible',
            ),
        );
        app(Registry::class)->resource($this->resource($filter), $this->resourceDefinition());

        $this->actingAs($viewer)->getJson(self::PREFIX.'extensions/resources/team-members')
            ->assertOk()
            ->assertExactJson([
                'data' => [['display_name' => $prefix.'visible', 'missing' => null]],
                'current_page' => 1,
                'has_more' => false,
            ]);
    }

    public function test_work_and_extension_permissions_gate_discovery_and_direct_requests(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $permitted = static fn (User $user): bool => $user->role === 'admin';
        $registry = app(Registry::class);
        $resource = $this->resource();
        $action = $this->action();
        $registry->resource($resource, array_replace($this->resourceDefinition(), ['authorize' => $permitted]));
        $registry->action('notify_team', $action, array_replace($this->actionDefinition(), ['authorize' => $permitted]));
        $resourceUrl = self::PREFIX.'extensions/resources/team-members';
        $actionUrl = self::PREFIX.'extensions/actions/notify_team';
        $input = ['input' => ['count' => 0, 'confirm' => false]];

        $this->getJson(self::PREFIX.'bootstrap')->assertUnauthorized();
        $this->getJson($resourceUrl)->assertUnauthorized();
        $this->postJson($actionUrl, $input)->assertUnauthorized();

        $this->actingAs($customer)->getJson(self::PREFIX.'bootstrap')->assertForbidden();
        $this->getJson($resourceUrl)->assertForbidden();
        $this->postJson($actionUrl, $input)->assertForbidden();

        $this->actingAs($editor)->getJson(self::PREFIX.'bootstrap')
            ->assertOk()->assertJsonPath('extensions', ['resources' => [], 'actions' => []]);
        $this->getJson($resourceUrl)->assertNotFound();
        $this->postJson($actionUrl, $input)->assertNotFound();
        $this->assertSame(0, $resource->calls);
        $this->assertSame([], $action->calls);

        $this->actingAs($admin)->getJson(self::PREFIX.'bootstrap')
            ->assertOk()
            ->assertJsonPath('extensions.resources.0.key', 'team-members')
            ->assertJsonPath('extensions.actions', [[
                'key' => 'notify_team',
                'label' => 'Notify team',
                'fields' => [
                    ['key' => 'message', 'label' => 'Message', 'type' => 'text', 'required' => false],
                    ['key' => 'count', 'label' => 'Count', 'type' => 'integer', 'required' => true],
                    ['key' => 'confirm', 'label' => 'Confirm', 'type' => 'boolean', 'required' => true],
                ],
            ]]);
        $this->getJson($resourceUrl)->assertOk();
        $this->postJson($actionUrl, $input)->assertOk();
        $this->assertSame(1, $resource->calls);
        $this->assertSame([[$admin->id, $input['input']]], $action->calls);
    }

    public function test_action_validates_input_and_executes_only_once_for_valid_fields(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $action = $this->action();
        app(Registry::class)->action('notify_team', $action, $this->actionDefinition());
        $url = self::PREFIX.'extensions/actions/notify_team';
        $valid = ['count' => 0, 'confirm' => false];

        $this->actingAs($viewer);
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->postJson($url, ['input' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->postJson($url, ['input' => $valid + ['unexpected' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->postJson($url, ['input' => ['count' => '0', 'confirm' => '0', 'message' => str_repeat('a', 2001)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['input.count', 'input.confirm', 'input.message']);
        $this->postJson($url, ['input' => []])->assertUnprocessable()
            ->assertJsonValidationErrors(['input.count', 'input.confirm']);
        $this->assertSame([], $action->calls);

        $this->postJson($url, ['input' => $valid + ['message' => '   ']])
            ->assertOk()->assertExactJson(['result' => $valid]);
        $this->assertSame([[$viewer->id, $valid]], $action->calls);
    }

    public function test_panel_opt_in_is_explicit_and_removed_when_registration_is_replaced(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $registry = app(Registry::class);
        $resource = $this->resource();
        $action = $this->action();
        $registry->resource($resource, $this->resourceDefinition());
        $registry->resource($resource);
        $registry->action('notify_team', $action, $this->actionDefinition());
        $registry->action('notify_team', $action, array_replace($this->actionDefinition(), ['authorize' => null]));
        $registry->action('invalid.key', $action, $this->actionDefinition());
        $registry->action('invalid-field', $action, array_replace($this->actionDefinition(), [
            'fields' => [['key' => '__proto__', 'label' => 'Bad field', 'type' => 'text']],
        ]));

        $this->actingAs($viewer)->getJson(self::PREFIX.'bootstrap')
            ->assertOk()->assertJsonPath('extensions', ['resources' => [], 'actions' => []]);
        $this->getJson(self::PREFIX.'extensions/resources/team-members')->assertNotFound();
        foreach (['notify_team', 'invalid.key', 'invalid-field'] as $key) {
            $this->postJson(self::PREFIX.'extensions/actions/'.$key, ['input' => []])->assertNotFound();
        }
        $this->assertSame($resource, $registry->resources()['team-members']);
        $this->assertSame($action, $registry->actions()['notify_team']);
    }

    public function test_action_can_opt_in_with_no_inputs(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $action = $this->action();
        app(Registry::class)->action('refresh-status', $action, [
            'label' => 'Refresh status',
            'authorize' => static fn (User $user): bool => true,
            'fields' => [],
        ]);
        $url = self::PREFIX.'extensions/actions/refresh-status';

        $this->actingAs($viewer)->postJson($url, ['input' => ['unrequested' => 'value']])
            ->assertUnprocessable()->assertJsonValidationErrors('input');
        $this->postJson($url, ['input' => (object) []])->assertOk()->assertExactJson(['result' => []]);
        $this->assertSame([[$viewer->id, []]], $action->calls);
    }

    public function test_row_only_action_registration_and_bootstrap_behavior(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $target = User::factory()->create(['name' => 'Team '.$viewer->id.' Target']);
        $registry = app(Registry::class);
        $resource = $this->resource();
        $rowOnlyAction = $this->action();
        $sharedAction = $this->action();

        $registry->resource($resource, $this->rowResourceDefinition([
            ['action' => 'row_only_action', 'label' => 'Row only', 'input_key' => 'member_id'],
            ['action' => 'shared_action', 'label' => 'Shared', 'input_key' => 'member_id'],
            ['action' => 'missing_action', 'label' => 'Missing', 'input_key' => 'member_id'],
        ]));
        $this->assertSame(0, $resource->calls);

        $registry->action('row_only_action', $rowOnlyAction, [
            'label' => 'Row only action',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [
                ['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true],
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
            ],
        ]);
        $registry->action('shared_action', $sharedAction, [
            'label' => 'Shared action',
            'authorize' => static fn (User $user): bool => true,
            'fields' => [
                ['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true],
            ],
        ]);

        $this->actingAs($viewer)->getJson(self::PREFIX.'bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'extensions.actions')
            ->assertJsonPath('extensions.actions.0.key', 'shared_action')
            ->assertJsonPath('extensions.resources.0.row_key', 'id')
            ->assertJsonPath('extensions.resources.0.row_actions', [
                [
                    'action' => 'row_only_action', 'label' => 'Row only', 'input_key' => 'member_id',
                    'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text', 'required' => false]],
                ],
                ['action' => 'shared_action', 'label' => 'Shared', 'input_key' => 'member_id', 'fields' => []],
            ]);

        $this->postJson(self::PREFIX.'extensions/actions/row_only_action', ['input' => ['member_id' => $target->id]])
            ->assertNotFound();
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/missing_action', ['row' => $target->id, 'input' => (object) []])
            ->assertNotFound();

        $this->postJson(self::PREFIX.'extensions/actions/shared_action', ['input' => ['member_id' => $target->id]])
            ->assertOk();
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/shared_action', ['row' => $target->id, 'input' => (object) []])
            ->assertOk();
        $this->assertCount(2, $sharedAction->calls);
    }

    public function test_row_action_uses_adapter_row_identifier_overwrites_client_input_and_validates_fields(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $target = User::factory()->create(['name' => 'Team '.$viewer->id.' Target']);
        $registry = app(Registry::class);
        $action = $this->action();

        $registry->resource($this->resource(), $this->rowResourceDefinition([
            ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id'],
        ]));
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [
                ['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true],
                ['key' => 'count', 'label' => 'Count', 'type' => 'integer', 'required' => true],
                ['key' => 'note', 'label' => 'Note', 'type' => 'text'],
            ],
        ]);
        $url = self::PREFIX.'extensions/resources/team-members/actions/mark_member';

        $this->actingAs($viewer);
        $this->postJson($url, ['row' => ['bad'], 'input' => ['count' => 1]])->assertUnprocessable()->assertJsonValidationErrors('row');
        $this->postJson($url, ['row' => '', 'input' => ['count' => 1]])->assertUnprocessable()->assertJsonValidationErrors('row');
        $this->postJson($url, ['row' => str_repeat('a', 256), 'input' => ['count' => 1]])->assertUnprocessable()->assertJsonValidationErrors('row');
        $this->postJson($url, ['row' => $target->id, 'input' => ['count' => 'not-int']])->assertUnprocessable()->assertJsonValidationErrors('input.count');
        $this->postJson($url, ['row' => $target->id, 'input' => []])->assertUnprocessable()->assertJsonValidationErrors('input.count');
        $this->assertSame([], $action->calls);

        $this->postJson($url, [
            'row' => $target->id,
            'input' => ['member_id' => 999999, 'count' => 3, 'note' => 'ok'],
        ])->assertOk()->assertExactJson([
            'result' => ['member_id' => $target->id, 'count' => 3, 'note' => 'ok'],
        ]);
        $this->assertSame([[$viewer->id, ['member_id' => $target->id, 'count' => 3, 'note' => 'ok']]], $action->calls);
    }

    public function test_row_action_rejects_bounded_post_filtered_and_ambiguous_resources(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $first = User::factory()->create(['name' => 'Team '.$viewer->id.' First', 'role' => 'customer']);
        User::factory()->create(['name' => 'Team '.$viewer->id.' Second', 'role' => 'customer']);
        $registry = app(Registry::class);
        $action = $this->action();
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);
        $url = self::PREFIX.'extensions/resources/team-members/actions/mark_member';

        foreach ([
            static fn (Builder $query) => $query->limit(5),
            static fn (Builder $query) => $query->offset(1),
            static fn (Builder $query) => $query->groupLimit(1, 'role'),
            static fn (Builder $query) => $query->afterQuery(static fn (Collection $rows): Collection => $rows),
        ] as $scope) {
            $registry->resource($this->resource($scope), $this->rowResourceDefinition([
                ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id'],
            ]));
            $this->actingAs($viewer)->postJson($url, ['row' => $first->id, 'input' => (object) []])->assertUnprocessable();
        }

        $duplicateRoleResource = new class implements ResourceAdapter
        {
            public function key(): string
            {
                return 'team-members';
            }

            public function query(object $user): Builder
            {
                return User::query()->where('name', 'like', 'Team '.$user->id.' %')->select(['id', 'role'])->orderBy('id');
            }
        };
        $registry->resource($duplicateRoleResource, [
            'label' => 'Team members',
            'authorize' => static fn (User $user): bool => true,
            'columns' => [['key' => 'id', 'label' => 'ID'], ['key' => 'role', 'label' => 'Role']],
            'row_key' => 'role',
            'row_actions' => [['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id']],
        ]);
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Role', 'type' => 'text', 'required' => true]],
        ]);
        $this->actingAs($viewer)->postJson($url, ['row' => 'customer', 'input' => (object) []])->assertUnprocessable();
        $this->assertSame([], $action->calls);
    }

    public function test_row_action_enforces_permissions_row_scope_and_definition_constraints(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $customer = User::factory()->create(['role' => 'customer']);
        $owned = User::factory()->create(['name' => 'Team '.$admin->id.' Owned']);
        $other = User::factory()->create(['name' => 'Other user']);
        $registry = app(Registry::class);
        $action = $this->action();
        $url = self::PREFIX.'extensions/resources/team-members/actions/mark_member';

        $registry->resource($this->resource(), array_replace($this->rowResourceDefinition([
            ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id'],
        ]), ['authorize' => static fn (User $user): bool => $user->role === 'admin']));
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => $user->role === 'admin',
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);

        $this->postJson($url, ['row' => $owned->id, 'input' => (object) []])->assertUnauthorized();
        $this->actingAs($customer)->postJson($url, ['row' => $owned->id, 'input' => (object) []])->assertForbidden();
        $this->actingAs($editor)->postJson($url, ['row' => $owned->id, 'input' => (object) []])->assertNotFound();

        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => false,
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);
        $this->actingAs($admin)->getJson(self::PREFIX.'bootstrap')
            ->assertOk()->assertJsonPath('extensions.resources.0.row_actions', []);
        $this->postJson($url, ['row' => $owned->id, 'input' => (object) []])->assertNotFound();

        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => $user->role === 'admin',
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);
        $this->actingAs($admin)->postJson($url, ['row' => $other->id, 'input' => (object) []])->assertNotFound();
        $this->postJson($url, ['row' => 999999, 'input' => (object) []])->assertNotFound();

        $registry->resource($this->resource(), array_replace($this->rowResourceDefinition([
            ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id'],
        ]), ['row_key' => 'undeclared']));
        $this->getJson(self::PREFIX.'bootstrap')->assertOk()->assertJsonPath('extensions.resources', []);
        $this->postJson($url, ['row' => $owned->id, 'input' => (object) []])->assertNotFound();

        $registry->resource($this->resource(), $this->rowResourceDefinition([
            ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'confirm'],
        ]));
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'confirm', 'label' => 'Confirm', 'type' => 'boolean', 'required' => true]],
        ]);
        $this->getJson(self::PREFIX.'bootstrap')->assertOk()->assertJsonPath('extensions.resources.0.row_actions', []);
        $this->postJson($url, ['row' => $owned->id, 'input' => (object) []])->assertNotFound();
        $this->assertSame([], $action->calls);
    }

    public function test_row_action_returns_404_for_mismatched_numeric_and_uuid_identifiers_without_500(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $target = User::factory()->create(['name' => 'Team '.$viewer->id.' Numeric']);
        $releaseId = (string) Str::uuid();
        Release::create([
            'id' => $releaseId,
            'commit' => str_repeat('a', 40),
            'state' => 'ready',
            'manifest' => ['tag' => 'v0.1.0-alpha.80'],
        ]);

        $registry = app(Registry::class);
        $action = $this->action();

        $registry->resource($this->resource(), $this->rowResourceDefinition([
            ['action' => 'mark_int', 'label' => 'Mark int', 'input_key' => 'member_id'],
            ['action' => 'mark_text', 'label' => 'Mark text', 'input_key' => 'member_ref'],
        ]));
        $registry->action('mark_int', $action, [
            'label' => 'Mark int',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);
        $registry->action('mark_text', $action, [
            'label' => 'Mark text',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'member_ref', 'label' => 'Member Ref', 'type' => 'text', 'required' => true]],
        ]);

        $this->actingAs($viewer);
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/mark_int', ['row' => 'not-a-number', 'input' => (object) []])
            ->assertNotFound();
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/mark_int', ['row' => $target->id.'abc', 'input' => (object) []])
            ->assertNotFound();
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/mark_text', ['row' => 'not-a-number', 'input' => (object) []])
            ->assertNotFound();
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/mark_text', ['row' => $target->id.'abc', 'input' => (object) []])
            ->assertNotFound();
        $this->postJson(self::PREFIX.'extensions/resources/team-members/actions/mark_int', ['row' => $target->id, 'input' => (object) []])
            ->assertOk()
            ->assertExactJson(['result' => ['member_id' => $target->id]]);

        $releaseResource = new class implements ResourceAdapter
        {
            public function key(): string
            {
                return 'releases';
            }

            public function query(object $user): Builder
            {
                return Release::query()->select(['id', 'state'])->orderBy('id');
            }
        };
        $registry->resource($releaseResource, [
            'label' => 'Releases',
            'authorize' => static fn (User $user): bool => true,
            'columns' => [['key' => 'id', 'label' => 'ID'], ['key' => 'state', 'label' => 'State']],
            'row_key' => 'id',
            'row_actions' => [['action' => 'release_note', 'label' => 'Note', 'input_key' => 'release_id']],
        ]);
        $registry->action('release_note', $action, [
            'label' => 'Release note',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'release_id', 'label' => 'Release ID', 'type' => 'text', 'required' => true]],
        ]);

        $this->postJson(self::PREFIX.'extensions/resources/releases/actions/release_note', ['row' => 'not-a-uuid', 'input' => (object) []])
            ->assertNotFound();
        $this->postJson(self::PREFIX.'extensions/resources/releases/actions/release_note', ['row' => $releaseId, 'input' => (object) []])
            ->assertOk()
            ->assertExactJson(['result' => ['release_id' => $releaseId]]);

        $this->assertSame([
            [$viewer->id, ['member_id' => $target->id]],
            [$viewer->id, ['release_id' => $releaseId]],
        ], $action->calls);
    }

    public function test_row_action_qualifies_row_key_for_joined_resources_with_duplicate_id_columns(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $target = User::factory()->create(['name' => 'Team '.$viewer->id.' Joined']);
        $registry = app(Registry::class);
        $action = $this->action();

        $joinedResource = new class implements ResourceAdapter
        {
            public function key(): string
            {
                return 'joined-members';
            }

            public function query(object $user): Builder
            {
                return User::query()
                    ->leftJoin('users as peers', 'peers.id', '=', 'users.id')
                    ->where('users.name', 'like', 'Team '.$user->id.' %')
                    ->select(['users.id', 'users.name as display_name'])
                    ->orderBy('users.id');
            }
        };

        $registry->resource($joinedResource, $this->rowResourceDefinition([
            ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id'],
        ]));
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);

        $this->actingAs($viewer)
            ->postJson(self::PREFIX.'extensions/resources/joined-members/actions/mark_member', [
                'row' => $target->id,
                'input' => (object) [],
            ])
            ->assertOk()
            ->assertExactJson(['result' => ['member_id' => $target->id]]);
        $this->assertSame([[$viewer->id, ['member_id' => $target->id]]], $action->calls);
    }

    public function test_row_action_fails_closed_with_422_when_after_query_property_is_missing(): void
    {
        $viewer = User::factory()->create(['role' => 'editor']);
        $target = User::factory()->create(['name' => 'Team '.$viewer->id.' FailClosed']);
        $registry = app(Registry::class);
        $action = $this->action();

        $registry->resource($this->resource(), $this->rowResourceDefinition([
            ['action' => 'mark_member', 'label' => 'Mark', 'input_key' => 'member_id'],
        ]));
        $registry->action('mark_member', $action, [
            'label' => 'Mark member',
            'authorize' => static fn (User $user): bool => true,
            'row_only' => true,
            'fields' => [['key' => 'member_id', 'label' => 'Member ID', 'type' => 'integer', 'required' => true]],
        ]);

        $this->app->bind(PanelExtensionController::class, static fn () => new class extends PanelExtensionController
        {
            protected string $afterQueryCallbacksProperty = 'missingAfterQueryCallbacks';
        });

        $this->actingAs($viewer)
            ->postJson(self::PREFIX.'extensions/resources/team-members/actions/mark_member', [
                'row' => $target->id,
                'input' => (object) [],
            ])
            ->assertUnprocessable();
        $this->assertSame([], $action->calls);
    }

    private function rowResourceDefinition(array $rowActions): array
    {
        return [
            'label' => 'Team members',
            'authorize' => static fn (User $user): bool => true,
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'display_name', 'label' => 'Name'],
            ],
            'row_key' => 'id',
            'row_actions' => $rowActions,
        ];
    }

    private function resourceDefinition(): array
    {
        return [
            'label' => 'Team members',
            'authorize' => static fn (User $user): bool => true,
            'columns' => [
                ['key' => 'display_name', 'label' => 'Name'],
                ['key' => 'missing', 'label' => 'Missing'],
            ],
        ];
    }

    private function actionDefinition(): array
    {
        return [
            'label' => 'Notify team',
            'authorize' => static fn (User $user): bool => true,
            'fields' => [
                ['key' => 'message', 'label' => 'Message', 'type' => 'text'],
                ['key' => 'count', 'label' => 'Count', 'type' => 'integer', 'required' => true],
                ['key' => 'confirm', 'label' => 'Confirm', 'type' => 'boolean', 'required' => true],
            ],
        ];
    }

    /** @return ResourceAdapter&object{calls: int} */
    private function resource(?\Closure $scope = null): ResourceAdapter
    {
        return new class($scope) implements ResourceAdapter
        {
            public int $calls = 0;

            public function __construct(private ?\Closure $scope) {}

            public function key(): string
            {
                return 'team-members';
            }

            public function query(object $user): Builder
            {
                $this->calls++;

                $query = User::query()->where('name', 'like', 'Team '.$user->id.' %')
                    ->select(['name as display_name', 'email', 'id'])->orderBy('id');
                if ($this->scope !== null) {
                    $query->withGlobalScope('test-bounds', $this->scope);
                }

                return $query;
            }
        };
    }

    /** @return ActionHandler&object{calls: array} */
    private function action(): ActionHandler
    {
        return new class implements ActionHandler
        {
            public array $calls = [];

            public function execute(object $user, array $input): array
            {
                $this->calls[] = [$user->id, $input];

                return $input;
            }
        };
    }
}
