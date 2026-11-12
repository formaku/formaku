<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Contracts\FieldType;
use Formaku\Cms\Fields\Field;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Services\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FieldTypeTest extends TestCase
{
    use RefreshDatabase;

    private const COLLECTIONS = '/formaku/api/v1/collections';

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->role = 'admin';
        $user->save();

        return $user;
    }

    private function schema(string $type): array
    {
        return ['key' => 'offers', 'name' => 'Offers', 'kind' => 'data', 'definition' => ['fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
            ['key' => 'price', 'label' => 'Price', 'type' => $type],
        ]]];
    }

    public function test_registered_field_type_validates_schema_and_record_values(): void
    {
        app(Registry::class)->field(new Field('money', 'Kwota', 'number', ['integer', 'min:0']));
        $this->actingAs($this->admin());

        $this->getJson('/formaku/api/v1/bootstrap')->assertOk()
            ->assertJsonFragment(['key' => 'money', 'label' => 'Kwota', 'input' => 'number'])
            ->assertJsonFragment(['key' => 'text', 'label' => 'Text', 'input' => 'text'])
            ->assertJsonCount(9, 'fieldTypes');

        $id = $this->postJson(self::COLLECTIONS, $this->schema('money'))->assertSuccessful()->json('id');
        $records = self::COLLECTIONS.'/'.$id.'/records';

        $this->postJson($records, ['version' => 0, 'data' => ['title' => 'A', 'price' => 1999]])
            ->assertSuccessful()->assertJsonPath('data.price', 1999);
        $this->postJson($records, ['version' => 0, 'data' => ['title' => 'B']])->assertSuccessful();
        $this->postJson($records, ['version' => 0, 'data' => ['title' => 'C', 'price' => -1]])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
        $this->postJson($records, ['version' => 0, 'data' => ['title' => 'D', 'price' => 'free']])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_field_without_panel_metadata_uses_the_existing_contract(): void
    {
        app(Registry::class)->field(new class implements FieldType
        {
            public function key(): string
            {
                return 'money';
            }

            public function rules(array $definition): array
            {
                return ['integer', 'min:0'];
            }
        });
        $this->actingAs($this->admin());

        $this->getJson('/formaku/api/v1/bootstrap')->assertOk()->assertJsonCount(8, 'fieldTypes');

        $id = $this->postJson(self::COLLECTIONS, $this->schema('money'))->assertSuccessful()->json('id');
        $this->postJson(self::COLLECTIONS.'/'.$id.'/records', ['version' => 0, 'data' => ['title' => 'A', 'price' => 1999]])
            ->assertSuccessful()->assertJsonPath('data.price', 1999);
    }

    public function test_unregistered_field_type_is_rejected(): void
    {
        $this->actingAs($this->admin());

        $this->postJson(self::COLLECTIONS, $this->schema('money'))
            ->assertUnprocessable()->assertJsonValidationErrors('definition.fields.1.type');

        // A definition stored while an extension was installed must not accept data once it is gone.
        $collection = Collection::create($this->schema('money'));
        $this->postJson(self::COLLECTIONS.'/'.$collection->id.'/records', ['version' => 0, 'data' => ['title' => 'A', 'price' => 5]])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_select_options_containing_commas_are_matched_whole(): void
    {
        $this->actingAs($this->admin());
        $schema = $this->schema('select');
        $schema['definition']['fields'][1]['options'] = ['Warszawa, Mokotów', 'Kraków'];
        $records = self::COLLECTIONS.'/'.$this->postJson(self::COLLECTIONS, $schema)->assertSuccessful()->json('id').'/records';

        $this->postJson($records, ['version' => 0, 'data' => ['title' => 'A', 'price' => 'Warszawa, Mokotów']])->assertSuccessful();
        $this->postJson($records, ['version' => 0, 'data' => ['title' => 'B', 'price' => 'Warszawa']])
            ->assertUnprocessable()->assertJsonValidationErrors('price');
    }

    public function test_registry_refuses_replaced_built_ins_and_unknown_inputs(): void
    {
        $registry = app(Registry::class);

        foreach ([
            new Field('relation', 'Other', 'text', ['string']),
            new Field('Money', 'Bad key', 'number', ['integer']),
            new Field('colour', 'No such control', 'colorpicker', ['string']),
        ] as $type) {
            try {
                $registry->field($type);
                $this->fail('Registered '.$type->key());
            } catch (InvalidArgumentException) {
                $this->assertCount(8, $registry->fields());
            }
        }
    }
}
