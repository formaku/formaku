<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Illuminate\Support\Facades\DB;

class RecordService
{
    public function save(
        Collection $c,
        ?Record $record,
        array $data,
        int $expected,
        int $actor,
        ?string $assistantId = null,
        ?object $actorUser = null,
        bool $restore = false,
    ): Record {
        return DB::transaction(function () use ($c, $record, $data, $expected, $actor, $assistantId, $actorUser, $restore) {
            $c = Collection::lockForUpdate()->findOrFail($c->id);
            $record = $record
                ? Record::where('collection_id', $c->id)->lockForUpdate()->findOrFail($record->id)
                : new Record(['collection_id' => $c->id, 'owner_id' => $actor, 'state' => $c->definition['states'][0] ?? 'new']);
            $fields = app(FieldAccess::class);
            $protected = $fields->hasRules($c);
            $preserved = [];
            if ($protected) {
                abort_unless(
                    $actorUser !== null && method_exists($actorUser, 'getAuthIdentifier')
                        && (string) $actorUser->getAuthIdentifier() === (string) $actor,
                    403,
                );
                $collections = app(CollectionAccess::class);
                $collections->check($c, $actorUser, true, $record->exists ? $record : null);
                abort_if(! $record->exists && $collections->rule($c, $actorUser)['scope'] === 'assigned', 403);
            }
            abort_unless((int) $record->version === $expected, 409, 'Record changed. Reload and compare.');
            abort_if($restore && ! $record->exists, 422);
            if ($protected) {
                [$data, $preserved] = $this->prepareProtectedData($c, $record, $data, $actorUser, $restore, $fields);
            } else {
                $data = app(SchemaValidator::class)->record($c->definition, $data);
            }

            foreach ($c->definition['fields'] as $f) {
                if ($f['type'] !== 'relation' || array_key_exists($f['key'], $preserved)) {
                    continue;
                }

                foreach ($data[$f['key']] ?? [] as $id) {
                    abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 422, 'Invalid relation.');
                    $related = Record::where('collection_id', $f['target'])->find($id);
                    abort_if($related === null, 422, 'Invalid relation.');
                    if ($user = $actorUser ?? auth(config('formaku.guard'))->user()) {
                        app(CollectionAccess::class)->check(Collection::findOrFail($f['target']), $user, false, $related);
                    }
                }
            }
            $record->save();
            $rev = Revision::create(['record_id' => $record->id, 'schema_version' => $c->version, 'data' => $data, 'author_id' => $actor]);
            app(MediaUsage::class)->indexRevision($rev->id, $c->definition, $data);
            foreach ($c->definition['fields'] as $f) {
                if ($f['type'] === 'relation') {
                    foreach (array_unique($data[$f['key']] ?? []) as $id) {
                        DB::table('formaku_relations')->insert(['revision_id' => $rev->id, 'field' => $f['key'], 'target_id' => $id]);
                    }
                }
            }
            $record->update(['publish_at' => null, 'draft_id' => $rev->id, 'version' => $record->version + 1]);
            $this->event($record, $actor, 'saved', $assistantId);

            return $record->fresh();
        });
    }

    /** @return array{array, array} */
    private function prepareProtectedData(
        Collection $collection,
        Record $record,
        array $submitted,
        object $user,
        bool $restore,
        FieldAccess $access,
    ): array {
        $definition = $collection->definition;
        $current = $record->draft_id === null ? [] : Revision::where('record_id', $record->id)->findOrFail($record->draft_id)->data;
        $writable = [];
        $preserved = [];
        foreach ($definition['fields'] as $field) {
            $key = $field['key'];
            if ($access->canWrite($collection, $field, $user)) {
                $writable[] = $field;

                continue;
            }

            if (! $restore && array_key_exists($key, $submitted)) {
                abort_unless($access->canRead($collection, $field, $user), 403);
                abort_unless(array_key_exists($key, $current) && $submitted[$key] === $current[$key], 403);
            }
            abort_if(! $record->exists && ($field['required'] ?? false), 403);
            if (array_key_exists($key, $current)) {
                $preserved[$key] = $current[$key];
            }
        }

        $definition['fields'] = $writable;
        $validated = app(SchemaValidator::class)->record($definition, $submitted);

        return [$validated + $preserved, $preserved];
    }

    public function publish(Record $record, int $expected, int $actor, ?string $assistantId = null): Record
    {
        return DB::transaction(function () use ($record, $expected, $actor, $assistantId) {
            $r = Record::lockForUpdate()->findOrFail($record->id);
            abort_unless((int) $r->version === $expected, 409);
            $c = Collection::findOrFail($r->collection_id);
            abort_unless($c->kind === 'content', 422);
            $rev = Revision::findOrFail($r->draft_id);
            $data = app(SchemaValidator::class)->record($c->definition, $rev->data);
            if (isset($data['blocks'])) {
                app(Renderer::class)->blocks($data['blocks']);
            }
            $slug = $data['slug'] ?? ('page-'.$r->id);
            abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug), 422, 'Invalid slug');
            $previousSlug = $r->published_id !== null ? $r->published_slug : null;
            $r->update(['published_id' => $rev->id, 'published_slug' => $slug, 'published_title' => $data['title'] ?? $data['name'] ?? $slug, 'search_text' => implode(' ', array_filter($data, 'is_string')), 'version' => $r->version + 1, 'publish_at' => null]);
            if (($c->definition['public'] ?? false) && $previousSlug !== null && $previousSlug !== $slug) {
                DB::table('formaku_slug_redirects')->upsert(
                    ['slug' => $previousSlug, 'record_id' => $r->id],
                    ['slug'],
                    ['record_id'],
                );
            }
            $this->event($r, $actor, 'published', $assistantId);

            return $r->fresh();
        });
    }

    public function transition(Record $record, int $expected, string $to, int $actor, ?int $assignee): Record
    {
        return DB::transaction(function () use ($record, $expected, $to, $actor, $assignee) {
            $r = Record::lockForUpdate()->findOrFail($record->id);
            abort_unless((int) $r->version === $expected, 409);
            $c = Collection::findOrFail($r->collection_id);
            abort_unless(collect($c->definition['transitions'] ?? [])->contains(fn ($t) => $t['from'] === $r->state && $t['to'] === $to), 422, 'Transition not allowed');
            if ($assignee !== null) {
                $staff = is_callable(config('formaku.staff')) ? (config('formaku.staff'))() : [];
                abort_unless(collect($staff)->pluck('id')->contains($assignee), 422, 'Invalid staff assignment');
            }
            $r->update(['state' => $to, 'assignee_id' => $assignee, 'version' => $r->version + 1]);
            $this->event($r, $actor, 'transitioned');

            return $r;
        });
    }

    private function event(Record $r, int $actor, string $type, ?string $assistantId = null): void
    {
        $payload = ['version' => $r->version, 'state' => $r->state];
        if ($assistantId !== null) {
            $payload['assistant_connection_id'] = $assistantId;
        }

        DB::table('formaku_events')->insert(['record_id' => $r->id, 'actor_id' => $actor, 'type' => $type, 'payload' => json_encode($payload), 'created_at' => now(), 'updated_at' => now()]);
    }
}
