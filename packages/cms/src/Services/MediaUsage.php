<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MediaUsage
{
    public function __construct(private CollectionAccess $access) {}

    public function indexRevision(int $revisionId, array $definition, array $data): void
    {
        $ids = [];
        foreach ($definition['fields'] ?? [] as $field) {
            if ($field['type'] === 'media') {
                $this->addReference($ids, $data[$field['key']] ?? null);
            }
        }
        $this->collectBlockReferences($ids, $data['blocks'] ?? []);

        foreach (array_chunk(array_keys($ids), 200) as $chunk) {
            DB::table('formaku_media_usages')->upsert(
                array_map(fn (int $mediaId) => ['revision_id' => $revisionId, 'media_id' => $mediaId], $chunk),
                ['revision_id', 'media_id'],
                ['media_id'],
            );
        }
    }

    public function records(int $mediaId, object $user): LengthAwarePaginator
    {
        $fields = app(FieldAccess::class);
        // The revision-level index cannot distinguish a hidden occurrence from a readable one.
        $collections = Collection::select('id', 'name', 'kind', 'definition')->get()
            ->reject(fn (Collection $collection) => collect($collection->definition['fields'] ?? [])->contains(
                fn (array $field) => ($field['type'] === 'media' || $field['key'] === 'blocks')
                    && ! $fields->canRead($collection, $field, $user),
            ))->keyBy('id');
        $grammar = DB::connection()->getQueryGrammar();
        $revisionId = $grammar->wrap('revision.id');
        $draftId = $grammar->wrap('current_record.draft_id');
        $publishedId = $grammar->wrap('current_record.published_id');
        $matches = DB::table('formaku_media_usages as usage')
            ->join('formaku_revisions as revision', 'revision.id', '=', 'usage.revision_id')
            ->join('formaku_records as current_record', 'current_record.id', '=', 'revision.record_id')
            ->where('usage.media_id', $mediaId)
            ->where(fn ($query) => $query->whereColumn('revision.id', 'current_record.draft_id')
                ->orWhereColumn('revision.id', 'current_record.published_id'))
            ->select('current_record.id as record_id')
            ->selectRaw("max(case when $revisionId = $draftId then 1 else 0 end) as used_in_draft")
            ->selectRaw("max(case when $revisionId = $publishedId then 1 else 0 end) as used_in_publication")
            ->groupBy('current_record.id');

        return $this->access->queryAcrossCollections($collections, $user)
            ->joinSub($matches, 'matches', 'matches.record_id', '=', 'formaku_records.id')
            ->select('formaku_records.id', 'collection_id', 'draft_id', 'published_id', 'used_in_draft', 'used_in_publication')
            ->with(['draft:id,data', 'published:id,data'])
            ->orderByDesc('formaku_records.id')
            ->paginate(25)
            ->through(function (Record $record) use ($collections): array {
                $collection = $collections->get($record->collection_id);
                $draft = (bool) $record->used_in_draft;
                $published = (bool) $record->used_in_publication;
                $revision = $draft ? $record->draft : $record->published;

                return [
                    'id' => $record->id,
                    'collection' => $collection->name,
                    'label' => $this->label($record->id, $collection->definition, $revision?->data ?? []),
                    'draft' => $draft,
                    'published' => $published,
                ];
            });
    }

    private function label(int $recordId, array $definition, array $data): string
    {
        $publicKeys = SchemaValidator::publicTextKeys($definition);
        foreach (['title', 'name'] as $key) {
            if (in_array($key, $publicKeys, true) && is_string($data[$key] ?? null)) {
                $label = trim($data[$key]);
                if ($label !== '') {
                    return $label;
                }
            }
        }

        return '#'.$recordId;
    }

    /** @param array<int, true> $ids */
    private function collectBlockReferences(array &$ids, mixed $blocks, int $depth = 0): void
    {
        if ($depth > 10 || ! is_array($blocks)) {
            return;
        }
        foreach (array_slice($blocks, 0, 100) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;
            if ($type === 'image') {
                $this->addReference($ids, $block['props']['media_id'] ?? null);
            } elseif (in_array($type, ['container', 'columns'], true)) {
                $this->collectBlockReferences($ids, $block['children'] ?? [], $depth + 1);
            }
        }
    }

    /** @param array<int, true> $ids */
    private function addReference(array &$ids, mixed $value): void
    {
        if (is_string($value)) {
            $digits = trim($value);
            if (str_starts_with($digits, '+')) {
                $digits = substr($digits, 1);
            }
            $digits = ltrim($digits, '0');
            $value = ctype_digit($digits) && (string) (int) $digits === $digits ? (int) $digits : null;
        }
        if (is_int($value) && $value > 0) {
            $ids[$value] = true;
        }
    }
}
