<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    protected $table = 'formaku_records';

    protected $guarded = [];

    protected $attributes = ['version' => 0, 'state' => 'new'];

    protected $casts = ['publish_at' => 'datetime'];
}
