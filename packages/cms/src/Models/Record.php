<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Record extends Model
{
    protected $table = 'formaku_records';

    protected $guarded = [];

    protected $attributes = ['version' => 0, 'state' => 'new'];

    protected $casts = ['publish_at' => 'datetime'];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(Revision::class, 'draft_id');
    }
}
