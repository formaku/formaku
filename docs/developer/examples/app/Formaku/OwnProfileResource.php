<?php

namespace App\Formaku;

use App\Models\User;
use Formaku\Cms\Contracts\ResourceAdapter;
use Illuminate\Database\Eloquent\Builder;

final class OwnProfileResource implements ResourceAdapter
{
    public function key(): string
    {
        return 'example_own_profile';
    }

    public function query(object $user): Builder
    {
        abort_unless($user instanceof User && $user->exists, 403);

        return User::query()->whereKey($user->getKey())->select('id', 'name');
    }
}
