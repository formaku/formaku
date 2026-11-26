<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PortalReplies
{
    public const EVENT = 'portal_reply_shared';

    public static function validate(string $key, string $kind, array $definition): void
    {
        $fields = collect($definition['fields'])->keyBy('key');
        $reply = $fields->get('customer_reply', []);
        $roles = $reply['access']['write'] ?? null;

        if (! PortalFields::supportsCollection($key, $kind)
            || ($reply['type'] ?? null) !== 'text' || ($reply['private'] ?? false) || ($reply['required'] ?? false)
            || ! is_array($roles) || array_intersect(['client', 'customer'], $roles) !== []
            || in_array('customer_reply', $definition['portal_fields'] ?? [], true)
            || ! ($fields->get('internal_note')['private'] ?? false)) {
            throw ValidationException::withMessages(['definition.portal_reply' => 'Shared replies require a staff-writable optional reply field outside the submission form and a private internal note.']);
        }
    }

    public function sharedRevision(Record $record): ?int
    {
        $event = DB::table('formaku_events')->where('record_id', $record->id)
            ->where('type', self::EVENT)->latest('id')->value('payload');

        return $event === null ? null : (int) json_decode($event, true, flags: JSON_THROW_ON_ERROR)['revision_id'];
    }

    public function share(Record $record, object $user, int $expected): int
    {
        return DB::transaction(function () use ($record, $user, $expected): int {
            $collection = Collection::lockForUpdate()->findOrFail($record->collection_id);
            $record = Record::lockForUpdate()->findOrFail($record->id);
            abort_unless(PortalFields::supportsCollection($collection->key, $collection->kind) && ($collection->definition['portal_reply'] ?? false), 404);
            app(CollectionAccess::class)->check($collection, $user, true, $record);
            $field = collect($collection->definition['fields'])->firstWhere('key', 'customer_reply');
            abort_unless($field && app(FieldAccess::class)->canWrite($collection, $field, $user), 403);
            abort_unless((int) $record->version === $expected, 409);
            $revision = Revision::where('record_id', $record->id)->findOrFail($record->draft_id);
            abort_unless(is_string($revision->data['customer_reply'] ?? null) && trim($revision->data['customer_reply']) !== '', 422, 'Save a reply before sharing it.');
            if ($this->sharedRevision($record) !== (int) $revision->id) {
                DB::table('formaku_events')->insert([
                    'type' => self::EVENT, 'record_id' => $record->id, 'actor_id' => $user->getAuthIdentifier(),
                    'payload' => json_encode(['revision_id' => $revision->id], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $expected;
        });
    }

    public function visible(Record $record): ?string
    {
        $id = $this->sharedRevision($record);
        if ($id === null) {
            return null;
        }

        return Revision::where('record_id', $record->id)->find($id)?->data['customer_reply'] ?? null;
    }
}
