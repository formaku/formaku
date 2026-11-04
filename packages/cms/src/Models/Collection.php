<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class Collection extends Model
{
    protected $table = 'formaku_collections';

    protected $guarded = [];

    protected $attributes = ['version' => 1];

    protected $casts = ['definition' => 'array'];
}
