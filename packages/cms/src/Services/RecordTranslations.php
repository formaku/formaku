<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordTranslations
{
    public function __construct(private CollectionAccess $access) {}

    /** @return array{locale: ?string, group: ?string, version: int} */
    public function current(Record $record, object $user): array
    {
        $collection = Collection::findOrFail($record->collection_id);
        $this->checkAccess($collection, $record, $user);
        $row = $this->row($record)->first();

        return [
            'locale' => $row->locale ?? null,
            'group' => $row->group_key ?? null,
            'version' => (int) ($row->version ?? 0),
        ];
    }

    /** @return array{locale: ?string, group: ?string, version: int} */
    public function save(Record $record, object $user, int $version, ?string $locale, ?string $group): array
    {
        try {
            return DB::transaction(function () use ($record, $user, $version, $locale, $group) {
                $collection = Collection::lockForUpdate()->findOrFail($record->collection_id);
                $current = Record::where('collection_id', $collection->id)->lockForUpdate()->findOrFail($record->id);
                $this->checkAccess($collection, $current, $user);
                $query = $this->row($current);
                $row = $query->first();
                abort_unless((int) ($row->version ?? 0) === $version, 409, 'Translation link changed. Reload before saving again.');

                $values = ['group_key' => $group, 'locale' => $locale, 'version' => $version + 1];
                if ($row === null) {
                    $query->insert($values + ['record_id' => $current->id, 'collection_id' => $collection->id]);
                } else {
                    $query->update($values);
                }

                return ['locale' => $locale, 'group' => $group, 'version' => $version + 1];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['locale' => 'This group already has a page in that language.']);
        }
    }

    private function checkAccess(Collection $collection, Record $record, object $user): void
    {
        abort_unless($collection->kind === 'content' && ($collection->definition['public'] ?? false), 404);
        $this->access->check($collection, $user, true, $record);
    }

    private function row(Record $record): Builder
    {
        return DB::table('formaku_record_translations')->where('record_id', $record->id);
    }
}
