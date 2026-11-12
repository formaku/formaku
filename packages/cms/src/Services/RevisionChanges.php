<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Revision;

final class RevisionChanges
{
    public static function data(?Revision $revision): array
    {
        // The model cast would conflate empty JSON objects and lists.
        return $revision === null ? [] : (array) json_decode($revision->getRawOriginal('data'), flags: JSON_THROW_ON_ERROR);
    }

    public static function between(array $fields, array $before, array $after): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $hasPrevious = array_key_exists($key, $before);
            $hasCurrent = array_key_exists($key, $after);
            if ($hasPrevious === $hasCurrent && self::sameValue($before[$key] ?? null, $after[$key] ?? null)) {
                continue;
            }

            $change = ['key' => $key, 'label' => $field['label'], 'type' => $field['type']];
            if ($hasPrevious) {
                $change['previous'] = $before[$key];
            }
            if ($hasCurrent) {
                $change['current'] = $after[$key];
            }
            $changes[] = $change;
        }

        return $changes;
    }

    private static function sameValue(mixed $previous, mixed $current): bool
    {
        if (gettype($previous) !== gettype($current)) {
            return false;
        }
        if (! is_array($previous) && ! is_object($previous)) {
            return $previous === $current;
        }

        $previous = (array) $previous;
        $current = (array) $current;
        if (count($previous) !== count($current)) {
            return false;
        }
        foreach ($previous as $key => $value) {
            if (! array_key_exists($key, $current) || ! self::sameValue($value, $current[$key])) {
                return false;
            }
        }

        return true;
    }
}
