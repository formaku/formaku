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

    /** @return list<string> */
    public static function publicTextKeys(array $definition): array
    {
        return collect($definition['fields'] ?? [])
            ->filter(fn ($field) => $field['type'] === 'text' && ! ($field['private'] ?? false))
            ->pluck('key')->values()->all();
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
