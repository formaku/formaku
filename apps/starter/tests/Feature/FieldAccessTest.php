<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Services\FieldAccess;
use Formaku\Cms\Services\SchemaValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FieldAccessTest extends TestCase
{
    public function test_field_roles_intersect_collection_rules_and_inherit_by_default(): void
    {
        $inherited = ['key' => 'title'];
        $restricted = ['key' => 'review', 'private' => true, 'access' => ['read' => ['desk', 'publisher'], 'write' => ['desk']]];
        $buildersOnly = ['key' => 'internal', 'private' => true, 'access' => ['read' => []]];
        $collection = new Collection(['definition' => [
            'fields' => [$inherited, $restricted, $buildersOnly],
            'access' => ['desk' => ['scope' => 'own', 'write' => true], 'publisher' => ['scope' => 'all', 'write' => false]],
        ]]);
        config(['formaku.role' => fn (User $user) => $user->role === 'editor' ? 'desk' : $user->role]);
        $editor = (new User)->forceFill(['role' => 'editor']);
        $publisher = (new User)->forceFill(['role' => 'publisher']);
        $admin = (new User)->forceFill(['role' => 'admin']);
        $outside = (new User)->forceFill(['role' => 'customer']);
        $access = app(FieldAccess::class);

        $this->assertTrue($access->hasRules($collection));
        $this->assertTrue($access->canWrite($collection, $inherited, $editor));
        $this->assertTrue($access->canWrite($collection, $restricted, $editor));
        $this->assertTrue($access->canRead($collection, $restricted, $publisher));
        $this->assertFalse($access->canWrite($collection, $restricted, $publisher));
        $this->assertFalse($access->canRead($collection, $buildersOnly, $editor));
        $this->assertTrue($access->canWrite($collection, $buildersOnly, $admin));
        $this->assertFalse($access->canRead($collection, $inherited, $outside));
        $data = ['title' => 'Draft', 'review' => null, 'internal' => 'Private', 'retired' => 'Legacy'];
        $this->assertSame(['title' => 'Draft', 'review' => null], $access->readableData($collection, $data, $editor));
        $this->assertSame([], $access->readableData($collection, $data, null));

        $legacy = new Collection(['definition' => ['fields' => [$inherited]]]);
        $this->assertFalse($access->hasRules($legacy));
        $this->assertSame($data, $access->readableData($legacy, $data, $editor));
    }

    public function test_schema_preserves_omitted_access_and_accepts_explicit_removal(): void
    {
        $validator = app(SchemaValidator::class);
        $input = $this->schema();
        $input['definition']['fields'][0]['access'] = ['read' => [], 'write' => ['editor']];
        $created = $validator->validate($input);

        $this->assertSame(['read' => [], 'write' => ['editor']], $created['definition']['fields'][0]['access']);
        unset($input['definition']['fields'][0]['access']);
        $updated = $validator->validate($input, $created['definition']);
        $this->assertSame($created['definition']['fields'][0]['access'], $updated['definition']['fields'][0]['access']);

        $input['definition']['fields'][0]['access'] = [];
        $updated = $validator->validate($input, $created['definition']);
        $this->assertArrayNotHasKey('access', $updated['definition']['fields'][0]);
    }

    public function test_schema_validates_each_role_list_and_keeps_public_slugs_readable(): void
    {
        $validator = app(SchemaValidator::class);
        $schema = $this->schema();
        $field = $schema['definition']['fields'][0];
        foreach ([
            [array_replace($field, ['access' => ['read' => ['editor', 'editor']]]), 'read'],
            [array_replace($field, ['access' => ['read' => ['named' => 'editor']]]), 'read'],
            [array_replace($field, ['access' => ['write' => [true]]]), 'write'],
            [array_replace($field, ['access' => ['read' => [' ']]]), 'read'],
            [array_replace($field, ['access' => ['read' => []], 'private' => false]), 'read'],
            [array_replace($field, ['access' => ['read' => []], 'key' => 'slug']), 'read'],
        ] as [$invalid, $operation]) {
            $schema['definition']['fields'][0] = $invalid;
            try {
                $validator->validate($schema);
                $this->fail('Expected field role validation to reject the schema.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('definition.fields.0.access.'.$operation, $exception->errors());
            }
        }
    }

    private function schema(): array
    {
        return [
            'key' => 'field_access',
            'name' => 'Field access',
            'kind' => 'data',
            'definition' => ['fields' => [['key' => 'internal', 'label' => 'Internal', 'type' => 'text', 'private' => true]]],
        ];
    }
}
