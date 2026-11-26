<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class PortalProject extends Model
{
    protected $table = 'formaku_portal_projects';

    protected $guarded = [];

    protected $attributes = ['version' => 1, 'budget_cents' => 0];

    protected $casts = ['version' => 'integer', 'proposal' => 'array', 'snapshot' => 'array', 'pages' => 'array', 'budget_cents' => 'integer', 'cost_cents' => 'integer'];
}
