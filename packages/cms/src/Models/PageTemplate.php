<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class PageTemplate extends Model
{
    protected $table = 'formaku_page_templates';

    protected $fillable = ['collection_id', 'name', 'blocks'];

    protected $casts = ['blocks' => 'array'];
}
