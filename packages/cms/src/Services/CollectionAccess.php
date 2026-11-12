<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Database\Eloquent\Builder;

class CollectionAccess
{
    public function rule(Collection $collection, object $user): array
    {
        if ($this->canBuild($user)) {
            return ['scope' => 'all', 'write' => true];
        }
        $rules = $collection->definition['access'] ?? null;
        if ($rules === null) {
            return ['scope' => 'all', 'write' => true];
        }

        return $rules[$this->role($user)] ?? ['scope' => 'none', 'write' => false];
    }

    public function canCreate(Collection $collection, object $user): bool
    {
        $rule = $this->rule($collection, $user);

        return ($rule['write'] ?? false) && in_array($rule['scope'], ['all', 'own'], true);
    }

    public function canBuild(object $user): bool
    {
        $authorize = config('formaku.authorize');

        return is_callable($authorize) && $authorize($user, 'build');
    }

    public function role(object $user): string|int
    {
        $resolver = config('formaku.role');
        $role = is_callable($resolver) ? $resolver($user) : ($user->role ?? '');

        return is_string($role) || is_int($role) ? $role : '';
    }

    public function query(Collection $collection, object $user): Builder
    {
        return $this->applyScope(
            Record::where('collection_id', $collection->id),
            $this->rule($collection, $user)['scope'],
            $user,
        );
    }

    /** @param iterable<Collection> $collections */
    public function queryAcrossCollections(iterable $collections, object $user): Builder
    {
        $collectionIdsByScope = [];
        foreach ($collections as $collection) {
            $collectionIdsByScope[$this->rule($collection, $user)['scope']][] = $collection->id;
        }

        return Record::query()->where(function (Builder $query) use ($collectionIdsByScope, $user) {
            $query->whereRaw('1 = 0');
            foreach (['all', 'own', 'assigned'] as $scope) {
                if (! empty($collectionIdsByScope[$scope])) {
                    $query->orWhere(fn (Builder $scoped) => $this->applyScope(
                        $scoped->whereIntegerInRaw('collection_id', $collectionIdsByScope[$scope]),
                        $scope,
                        $user,
                    ));
                }
            }
        });
    }

    private function applyScope(Builder $query, string $scope, object $user): Builder
    {
        return match ($scope) {
            'all' => $query,
            'own' => $query->where('owner_id', $user->getAuthIdentifier()),
            'assigned' => $query->where('assignee_id', $user->getAuthIdentifier()),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function check(Collection $collection, object $user, bool $write, ?Record $record = null): void
    {
        $rule = $this->rule($collection, $user);
        abort_if($rule['scope'] === 'none', 404);
        if ($record) {
            abort_unless($this->query($collection, $user)->whereKey($record->id)->exists(), 404);
        }
        if ($write) {
            abort_unless($rule['write'] ?? false, 403);
        }
    }
}
