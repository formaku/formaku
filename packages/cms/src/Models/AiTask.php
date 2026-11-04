<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class AiTask extends Model
{
    protected $table = 'formaku_ai_tasks';

    protected $guarded = [];

    protected $attributes = ['state' => 'queued'];

    protected $casts = ['result' => 'array'];
}
