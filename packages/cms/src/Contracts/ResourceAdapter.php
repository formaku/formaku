<?php

namespace Formaku\Cms\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface ResourceAdapter
{
    public function key(): string;

    public function query(object $user): Builder;
}
