<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;

class FieldAccess
{
    public function __construct(private CollectionAccess $collections) {}

    public function hasRules(Collection $collection): bool
    {
        foreach ($collection->definition['fields'] ?? [] as $field) {
            if (array_key_exists('access', $field) && $field['access'] !== []) {
                return true;
            }
        }

        return false;
    }

    public function canRead(Collection $collection, array $field, ?object $user): bool
    {
        if ($user === null) {
            return ! $this->hasRules($collection);
        }

        return $this->collections->rule($collection, $user)['scope'] !== 'none'
            && $this->allows($field, 'read', $user);
    }

    public function canWrite(Collection $collection, array $field, ?object $user): bool
    {
        return $user !== null
            && $this->canRead($collection, $field, $user)
            && ($this->collections->rule($collection, $user)['write'] ?? false)
            && $this->allows($field, 'write', $user);
    }

    public function readableData(Collection $collection, array $data, ?object $user): array
    {
        if (! $this->hasRules($collection)) {
            return $data;
        }

        $keys = [];
        foreach ($collection->definition['fields'] ?? [] as $field) {
            if ($this->canRead($collection, $field, $user)) {
                $keys[$field['key']] = true;
            }
        }

        return array_intersect_key($data, $keys);
    }

    private function allows(array $field, string $operation, object $user): bool
    {
        if (! array_key_exists('access', $field) || $this->collections->canBuild($user)) {
            return true;
        }
        $access = $field['access'];
        if (! is_array($access) || array_diff(array_keys($access), ['read', 'write']) !== []) {
            return false;
        }
        if (! array_key_exists($operation, $access)) {
            return true;
        }
        $roles = $access[$operation];

        return is_array($roles) && array_is_list($roles)
            && in_array((string) $this->collections->role($user), $roles, true);
    }
}
