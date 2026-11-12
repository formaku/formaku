<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Illuminate\Support\Str;

class PortalFields
{
    private const TYPES = ['text', 'number', 'date', 'boolean', 'select'];

    public static function supportsCollection(string $key, string $kind): bool
    {
        return $key === 'requests' && $kind === 'data';
    }

    /** @return array<string, array> */
    public static function eligibleFields(array $fields): array
    {
        $eligible = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)
                || ! preg_match('/^[a-z][a-z0-9_]{0,48}$/D', $field['key'])
                || ! is_string($field['label'] ?? null) || Str::trim($field['label']) === ''
                || in_array($field['key'], ['title', 'description'], true)
                || ($field['private'] ?? false)
                || ! in_array($field['type'] ?? null, self::TYPES, true)) {
                continue;
            }

            if ($field['type'] === 'select' && ! self::validOptions($field['options'] ?? null)) {
                continue;
            }

            $eligible[$field['key']] = $field;
        }

        return $eligible;
    }

    /** @return list<array> */
    public static function configured(Collection $collection): array
    {
        $keys = $collection->definition['portal_fields'] ?? [];
        $fields = $collection->definition['fields'] ?? [];
        if (! self::supportsCollection($collection->key, $collection->kind)
            || ! is_array($keys) || ! array_is_list($keys) || $keys === [] || ! is_array($fields)) {
            return [];
        }

        $eligible = self::eligibleFields($fields);
        $configured = [];
        foreach ($keys as $key) {
            if (is_string($key) && isset($eligible[$key])) {
                $configured[$key] = $eligible[$key];
            }
        }

        return array_values($configured);
    }

    private static function validOptions(mixed $options): bool
    {
        if (! is_array($options) || ! array_is_list($options) || $options === []) {
            return false;
        }

        foreach ($options as $option) {
            if (! is_string($option) || $option === '' || Str::trim($option) !== $option
                || str_contains($option, ',') || str_starts_with($option, '"')) {
                return false;
            }
        }

        return count($options) === count(array_unique($options));
    }
}
