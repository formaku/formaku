<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_fields_are_validated_and_saved_for_the_customer(): void
    {
        $builder = User::factory()->create(['role' => 'admin']);
        $customer = User::factory()->create(['role' => 'customer']);
        $other = User::factory()->create(['role' => 'customer']);
        $keys = ['reference', 'follow_up', 'category', 'appointment', 'estimate'];
        $definition = $this->requestDefinition($keys);
        $this->actingAs($builder)->postJson('/formaku/api/v1/collections', $definition)
            ->assertCreated()
            ->assertJsonPath('definition.portal_fields', $keys);

        $this->actingAs($customer)->get('/portal')->assertOk()
            ->assertViewHas('portalFields', fn (array $fields) => array_column($fields, 'key') === $keys)
            ->assertSeeInOrder(array_map(fn (string $key): string => 'id="portal-field-'.$key.'"', ['title', 'description', ...$keys]), false);
        $base = ['title' => 'Repair request', 'description' => 'Test description'];
        $this->postJson('/portal/requests', $base + ['estimate' => 'several', 'appointment' => 'invalid', 'follow_up' => 'maybe'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category', 'estimate', 'appointment', 'follow_up']);
        $this->postJson('/portal/requests', $base + ['category' => 'Other'])
            ->assertUnprocessable()->assertJsonValidationErrors(['category']);
        $this->assertDatabaseCount('formaku_records', 0);

        $submitted = [
            'category' => 'Delivery',
            'estimate' => '12.5',
            'appointment' => '2026-11-13',
            'follow_up' => '0',
            'reference' => 'FORM-42',
        ];
        $this->post('/portal/requests', $base + $submitted + [
            'owner_id' => $other->id,
            'state' => 'closed',
            'internal' => 'Private',
            'tracking' => 'Staff field',
        ])->assertRedirect('/portal');

        $record = Record::sole();
        $this->assertSame($customer->id, $record->owner_id);
        $this->assertSame('new', $record->state);
        $this->assertEquals($base + $submitted, $record->draft->data);
        $path = '/portal/requests/'.$record->id;
        $this->get($path)->assertOk()
            ->assertViewHas('portalFields', fn (array $fields) => array_column($fields, 'key') === $keys)
            ->assertSeeInOrder(['Reference</dt>', 'Follow up</dt>', 'Category</dt>', 'Appointment</dt>', 'Estimate</dt>'], false);
        $this->getJson($path)->assertExactJson(['id' => $record->id, 'state' => 'new', 'data' => $base + $submitted]);
        $this->actingAs($other)->get($path)->assertNotFound();
    }

    public function test_extra_fields_respect_write_permissions_and_skip_missing_values_on_details(): void
    {
        $model = $this->requestDefinition(['reference', 'tracking', 'estimate']);
        $model['definition']['fields'][2]['required'] = false;
        $model['definition']['fields'][7]['access'] = ['write' => ['editor']];
        Collection::create($model);
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)->get('/portal')->assertOk()
            ->assertViewHas('portalFields', fn (array $fields) => array_column($fields, 'key') === ['reference', 'estimate']);
        $this->post('/portal/requests', [
            'title' => 'Test',
            'description' => 'Test',
            'reference' => 'REF-9',
            'tracking' => 'Reserved for staff',
            'estimate' => null,
        ])->assertRedirect('/portal');

        $record = Record::sole();
        $this->assertArrayNotHasKey('tracking', $record->draft->data);
        $this->get('/portal/requests/'.$record->id)->assertOk()
            ->assertViewHas('portalFields', fn (array $fields) => array_column($fields, 'key') === ['reference']);
    }

    public function test_existing_collections_without_portal_fields_keep_the_basic_form(): void
    {
        $model = $this->requestDefinition([]);
        unset($model['definition']['portal_fields']);
        $model['definition']['fields'][2]['required'] = false;
        Collection::create($model);
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->get('/portal')->assertOk()->assertViewHas('portalFields', []);
        $base = ['title' => 'Test', 'description' => 'Test'];
        $this->post('/portal/requests', $base + ['reference' => 'not selected'])->assertRedirect('/portal');
        $this->assertEquals($base, Record::sole()->draft->data);
    }

    public function test_only_eligible_fields_are_accepted_in_portal_configuration(): void
    {
        $builder = User::factory()->create(['role' => 'admin']);
        $this->actingAs($builder);
        foreach ([['title'], ['description'], ['internal'], ['attachment'], ['missing']] as $keys) {
            $this->postJson('/formaku/api/v1/collections', $this->requestDefinition($keys))
                ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_fields.0']);
        }
        $this->postJson('/formaku/api/v1/collections', $this->requestDefinition(['reference', 'reference']))
            ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_fields.0']);
        $this->postJson('/formaku/api/v1/collections', $this->requestDefinition(['label' => 'reference']))
            ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_fields']);

        $otherCollection = $this->requestDefinition(['reference']);
        $otherCollection['key'] = 'tickets';
        $this->postJson('/formaku/api/v1/collections', $otherCollection)
            ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_fields']);
        $contentCollection = $this->requestDefinition(['reference']);
        $contentCollection['kind'] = 'content';
        $this->postJson('/formaku/api/v1/collections', $contentCollection)
            ->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_fields']);

        $brokenSelect = $this->requestDefinition(['category']);
        $brokenSelect['definition']['fields'][2]['options'] = ['Support', 'Retail, B2B'];
        $this->postJson('/formaku/api/v1/project/import', [
            'format' => 'formaku.project', 'version' => 1, 'collections' => [$brokenSelect],
        ])->assertUnprocessable()->assertJsonValidationErrors(['definition.portal_fields.0']);
        $this->assertDatabaseCount('formaku_collections', 0);

        $keys = ['reference', 'category'];
        $this->postJson('/formaku/api/v1/project/import', [
            'format' => 'formaku.project', 'version' => 1, 'collections' => [$this->requestDefinition($keys)],
        ])->assertOk()->assertJsonPath('imported', 1);
        $this->getJson('/formaku/api/v1/project')->assertOk()
            ->assertJsonPath('collections.0.definition.portal_fields', $keys);
    }

    public function test_portal_ignores_ineligible_existing_settings(): void
    {
        $model = $this->requestDefinition([null, 'missing', 'internal', 'attachment', 'title', 'category', 'reference']);
        $model['definition']['fields'][2]['required'] = false;
        $model['definition']['fields'][2]['options'] = [];
        Collection::create($model);
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->get('/portal')->assertOk()
            ->assertViewHas('portalFields', fn (array $fields) => array_column($fields, 'key') === ['reference']);
    }

    private function requestDefinition(array $keys): array
    {
        return [
            'key' => 'requests',
            'name' => 'Requests',
            'kind' => 'data',
            'definition' => [
                'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'required' => true],
                    ['key' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => ['Support', 'Delivery'], 'required' => true],
                    ['key' => 'estimate', 'label' => 'Estimate', 'type' => 'number'],
                    ['key' => 'appointment', 'label' => 'Appointment', 'type' => 'date'],
                    ['key' => 'follow_up', 'label' => 'Follow up', 'type' => 'boolean'],
                    ['key' => 'reference', 'label' => 'Reference', 'type' => 'text'],
                    ['key' => 'tracking', 'label' => 'Tracking', 'type' => 'text'],
                    ['key' => 'internal', 'label' => 'Internal', 'type' => 'text', 'private' => true],
                    ['key' => 'attachment', 'label' => 'Attachment', 'type' => 'media'],
                ],
                'portal_fields' => $keys,
            ],
        ];
    }
}
