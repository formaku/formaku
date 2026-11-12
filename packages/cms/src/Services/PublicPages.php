<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as ModelCollection;

class PublicPages
{
    public static function allowsCollection(Collection $collection): bool
    {
        return $collection->kind === 'content' && ($collection->definition['public'] ?? false)
            && ! self::isPrivate($collection->definition, 'slug');
    }

    public static function isPrivate(array $definition, string $key): bool
    {
        return collect($definition['fields'] ?? [])->contains(
            fn (array $field) => $field['key'] === $key && ($field['private'] ?? false),
        );
    }

    /** @return ModelCollection<int, Collection> */
    public static function collections(): ModelCollection
    {
        return Collection::where('kind', 'content')->get(['id', 'kind', 'definition'])
            ->filter(self::allowsCollection(...));
    }

    /** @return Builder<Record> */
    public static function publishedRecords(): Builder
    {
        return Record::query()
            ->whereNotNull('published_id')
            ->whereNotNull('published_slug')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('formaku_revisions')
                ->whereColumn('formaku_revisions.id', 'formaku_records.published_id')
                ->whereColumn('formaku_revisions.record_id', 'formaku_records.id'));
    }

    public static function data(array $definition, array $revisionData): array
    {
        $allowed = collect($definition['fields'] ?? [])->reject(fn (array $field) => $field['private'] ?? false)
            ->pluck('key')->all();

        return array_intersect_key($revisionData, array_flip($allowed));
    }

    public static function title(array $publicData, string $slug): string
    {
        foreach (['title', 'name'] as $key) {
            $value = $publicData[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return (string) $value;
            }
        }

        return $slug;
    }

    public static function recordTitle(Record $record, array $definition): string
    {
        return self::title(self::data($definition, $record->published?->data ?? []), $record->published_slug);
    }
}
