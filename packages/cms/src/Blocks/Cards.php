<?php

namespace Formaku\Cms\Blocks;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Services\PublicPages;

final class Cards extends Block
{
    public function key(): string
    {
        return 'cards';
    }

    public function label(): string
    {
        return 'Karty';
    }

    public function panelSchema(): array
    {
        return [['key' => 'collection_id', 'label' => 'Kolekcja', 'input' => 'collection']];
    }

    public function render(array $props, string $children = ''): string
    {
        $collection = Collection::find($props['collection_id'] ?? 0);
        if (! $collection || ! PublicPages::allowsCollection($collection)) {
            return '';
        }
        $html = '<section class="grid md:grid-cols-3 gap-4">';
        $records = PublicPages::publishedRecords()->where('collection_id', $collection->id)->with('published:id,data')->limit(20)->get();
        foreach ($records as $record) {
            $title = PublicPages::recordTitle($record, $collection->definition);
            $html .= '<a class="p-6 border border-base-300 rounded-xl" href="/p/'.e($record->published_slug).'">'.e($title).'</a>';
        }

        return $html.'</section>';
    }
}
