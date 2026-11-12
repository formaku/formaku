<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class PortalAttachment extends Model
{
    public $timestamps = false;

    protected $table = 'formaku_portal_attachments';

    protected $fillable = ['record_id', 'path', 'name', 'size'];

    protected $casts = ['size' => 'integer'];
}
