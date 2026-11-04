<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Database\Eloquent\Builder;

class CollectionAccess
{
    public function rule(Collection $collection, object $user): array
    {
        $authorize = config('formaku.authorize');
        if (is_callable($authorize) && $authorize($user, 'build')) {
            return ['scope' => 'all', 'write' => true];
        }
        $rules = $collection->definition['access'] ?? null;
        if ($rules === null) {
            return ['scope' => 'all', 'write' => true];
        }
        $resolver = config('formaku.role');
        $role = is_callable($resolver) ? $resolver($user) : ($user->role ?? '');

        return $rules[$role] ?? ['scope' => 'none', 'write' => false];
    }

    public function query(Collection $collection, object $user): Builder
    {
        $query = Record::where('collection_id', $collection->id);

        return match ($this->rule($collection, $user)['scope']) {
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
