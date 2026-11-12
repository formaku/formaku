<?php

namespace Formaku\Cms\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SchemaValidator
{
    const TYPES = ['text', 'richtext', 'number', 'date', 'boolean', 'select', 'media', 'relation'];

    public function validate(array $input, ?array $old = null): array
    {
        $d = Validator::make($input, [
            'key' => ['required', 'regex:/^[a-z][a-z0-9_]{1,48}$/'], 'name' => 'required|string|max:100', 'kind' => 'required|in:content,data',
            'definition' => 'required|array', 'definition.fields' => 'required|array|min:1|max:50',
            'definition.fields.*.key' => ['required', 'distinct', 'regex:/^[a-z][a-z0-9_]{0,48}$/'],
            'definition.fields.*.label' => 'required|string|max:100', 'definition.fields.*.type' => 'required|in:'.implode(',', self::TYPES),
            'definition.fields.*.required' => 'sometimes|boolean', 'definition.fields.*.private' => 'sometimes|boolean',
            'definition.fields.*.options' => 'sometimes|array|max:100', 'definition.fields.*.target' => 'required_if:definition.fields.*.type,relation|integer',
            'definition.states' => 'sometimes|array', 'definition.states.*' => 'string|max:50',
            'definition.transitions' => 'sometimes|array', 'definition.transitions.*.from' => 'required|string', 'definition.transitions.*.to' => 'required|string',
            'definition.access' => 'sometimes|array',
            'definition.access.*' => 'array:scope,write',
            'definition.access.*.scope' => 'required|in:all,own,assigned,none',
            'definition.access.*.write' => 'required|boolean',
            'definition.public' => 'sometimes|boolean',
            'definition.seo' => 'sometimes|array:title,description',
            'definition.seo.title' => 'nullable|string|max:49',
            'definition.seo.description' => 'nullable|string|max:49',
        ])->validate();
        $this->validateFieldAccess($d['definition']['fields'], $input['definition']['fields'], $old['fields'] ?? []);

        if ($d['kind'] === 'content' && ($d['definition']['public'] ?? false)) {
            foreach ($d['definition']['fields'] as $index => $field) {
                if ($field['key'] === 'slug' && ($field['private'] ?? false)) {
                    throw ValidationException::withMessages([
                        "definition.fields.{$index}.private" => 'The slug of a public content collection cannot be private.',
                    ]);
                }
            }
        }
        $publicTextKeys = self::publicTextKeys($d['definition']);
        foreach (array_filter($d['definition']['seo'] ?? [], fn ($key) => $key !== null) as $attribute => $key) {
            if ($d['kind'] !== 'content' || ! ($d['definition']['public'] ?? false) || ! in_array($key, $publicTextKeys, true)) {
                throw ValidationException::withMessages([
                    'definition.seo.'.$attribute => 'SEO metadata must use a public text field in a public content collection.',
                ]);
            }
        }
        foreach ($d['definition']['transitions'] ?? [] as $transition) {
            if (! in_array($transition['from'], $d['definition']['states'] ?? []) || ! in_array($transition['to'], $d['definition']['states'] ?? [])) {
                throw ValidationException::withMessages(['definition' => 'Transition references an unknown state.']);
            }
        }
        if ($old) {
            foreach ($d['definition']['fields'] as $field) {
                if (($field['required'] ?? false) && ! collect($old['fields'])->contains('key', $field['key'])) {
                    throw ValidationException::withMessages(['definition' => 'Adding a required field needs a data migration.']);
                }
            }
            $new = collect($d['definition']['fields'])->keyBy('key');
            foreach ($old['fields'] as $f) {
                $n = $new->get($f['key']);
                if (! $n || $n['type'] !== $f['type'] || (! ($f['required'] ?? false) && ($n['required'] ?? false))) {
                    throw ValidationException::withMessages(['definition' => 'Destructive schema change requires a migration.']);
                }
            }
        }

        return $d;
    }

    private function validateFieldAccess(array &$fields, array $input, array $oldFields): void
    {
        $previous = array_column($oldFields, null, 'key');
        foreach ($fields as $index => &$field) {
            $submitted = $input[$index];
            $original = $previous[$field['key']] ?? [];
            if (! array_key_exists('access', $submitted) && ! array_key_exists('access', $original)) {
                continue;
            }

            $access = array_key_exists('access', $submitted) ? $submitted['access'] : $original['access'];
            $path = "definition.fields.{$index}.access";
            if (! is_array($access) || array_diff(array_keys($access), ['read', 'write']) !== []) {
                throw ValidationException::withMessages([$path => 'Field access must specify read or write role lists.']);
            }
            if ($access === []) {
                unset($field['access']);

                continue;
            }
            foreach ($access as $operation => $roles) {
                if (! is_array($roles) || ! array_is_list($roles)) {
                    throw ValidationException::withMessages([$path.'.'.$operation => 'Field access must use a list of roles.']);
                }

                $seen = [];
                foreach ($roles as $role) {
                    if (! is_string($role) || $role === '' || trim($role) !== $role || mb_strlen($role) > 100 || isset($seen[$role])) {
                        throw ValidationException::withMessages([$path.'.'.$operation => 'Use distinct, nonempty role names of at most 100 characters.']);
                    }
                    $seen[$role] = true;
                }
            }
            if (array_key_exists('read', $access) && (! ($field['private'] ?? false) || $field['key'] === 'slug')) {
                throw ValidationException::withMessages([$path.'.read' => 'Read access can only be limited on private fields other than slug.']);
            }
            $field['access'] = $access;
        }
    }

    /** @return list<string> */
    public static function publicTextKeys(array $definition): array
    {
        return collect($definition['fields'] ?? [])
            ->filter(fn ($field) => $field['type'] === 'text' && ! ($field['private'] ?? false))
            ->pluck('key')->values()->all();
    }

    public static function blockList(mixed $blocks, string $field, int $depth = 0): void
    {
        if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) > 100 || $depth > 10) {
            throw ValidationException::withMessages([$field => 'Invalid block list. Use at most 100 blocks per list and 10 levels of nesting.']);
        }

        foreach ($blocks as $index => $block) {
            $path = $field.'.'.$index;
            if (! is_array($block) || ! is_string($block['type'] ?? null) || ! is_array($block['props'] ?? [])) {
                throw ValidationException::withMessages([$path => 'Invalid block data.']);
            }
            if (isset($block['children'])) {
                self::blockList($block['children'], $path.'.children', $depth + 1);
            }
        }
    }

    public function record(array $schema, array $data): array
    {
        $rules = [];
        foreach ($schema['fields'] as $f) {
            $type = match ($f['type']) {
                'number' => 'numeric','boolean' => 'boolean','date' => 'date','richtext','relation' => 'array','media' => 'integer',default => 'string'
            };
            $rules[$f['key']] = [($f['required'] ?? false) ? 'required' : 'nullable', $type];
            if ($f['type'] === 'select') {
                $rules[$f['key']][] = 'in:'.implode(',', $f['options'] ?? []);
            }
        }

        $validated = Validator::make($data, $rules)->validate();
        foreach ($schema['fields'] as $f) {
            if (($f['type'] === 'richtext') && $f['key'] !== 'blocks' && isset($validated[$f['key']])) {
                app(Renderer::class)->rich($validated[$f['key']]);
            }
        }

        return $validated;
    }
}
