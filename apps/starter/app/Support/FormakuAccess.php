<?php

namespace App\Support;

use App\Models\User;

class FormakuAccess
{
    public static function allows(object $user, string $ability): bool
    {
        return $user->role === 'admin' || ($ability === 'work' && in_array($user->role, ['editor', 'publisher'])) || ($ability === 'publish' && $user->role === 'publisher');
    }

    public static function staff(): array
    {
        return User::whereIn('role', ['admin', 'editor', 'publisher'])->get(['id', 'name'])->toArray();
    }
}
