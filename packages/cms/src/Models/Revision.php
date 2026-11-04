<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class Revision extends Model
{
    protected $table = 'formaku_revisions';

    protected $guarded = [];

    protected $casts = ['data' => 'array'];
}
