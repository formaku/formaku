<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class AgencyDeployment extends Model
{
    protected $table = 'formaku_agency_deployments';

    protected $guarded = [];

    protected $casts = [
        'target' => 'array', 'intent' => 'array', 'baseline' => 'array', 'page_ids' => 'array', 'retained_page_ids' => 'array',
        'previous_id' => 'integer', 'revision' => 'integer', 'rolled_back_at' => 'datetime',
    ];
}
