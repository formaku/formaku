<?php

namespace Formaku\Cms\Models;

use Illuminate\Database\Eloquent\Model;

class PageTemplate extends Model
{
    protected $table = 'formaku_page_templates';

    protected $fillable = ['collection_id', 'name', 'blocks'];

    protected $casts = ['blocks' => 'array'];

    public static function supportsCollection(Collection $collection): bool
    {
        return $collection->kind === 'content' && collect($collection->definition['fields'] ?? [])->contains(
            fn (array $field) => $field['key'] === 'blocks' && $field['type'] === 'richtext' && ! ($field['private'] ?? false),
        );
    }
}
