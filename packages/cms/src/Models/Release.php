<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class Release extends Model
{
    protected $table = 'formaku_releases';

    protected $guarded = [];

    protected $casts = ['manifest' => 'array'];

    public $incrementing = false;

    protected $keyType = 'string';
}
