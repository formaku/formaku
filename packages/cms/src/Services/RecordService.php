<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Illuminate\Support\Facades\DB;

class RecordService
{
    public function save(Collection $c, ?Record $record, array $data, int $expected, int $actor): Record
    {
        return DB::transaction(function () use ($c, $record, $data, $expected, $actor) {
            $record = $record ? Record::lockForUpdate()->findOrFail($record->id) : new Record(['collection_id' => $c->id, 'owner_id' => $actor, 'state' => $c->definition['states'][0] ?? 'new']);
            abort_unless((int) $record->version === $expected, 409, 'Record changed. Reload and compare.');
            $data = app(SchemaValidator::class)->record($c->definition, $data);
            foreach ($c->definition['fields'] as $f) {
                if ($f['type'] === 'relation') {
                    foreach ($data[$f['key']] ?? [] as $id) {
                        abort_unless(Record::where('collection_id', $f['target'])->whereKey($id)->exists(), 422, 'Invalid relation.');
                        if ($user = auth(config('formaku.guard'))->user()) {
                            app(CollectionAccess::class)->check(Collection::findOrFail($f['target']), $user, false, Record::findOrFail($id));
                        }
                    }
                }
            }
            $record->save();
            $rev = Revision::create(['record_id' => $record->id, 'schema_version' => $c->version, 'data' => $data, 'author_id' => $actor]);
            foreach ($c->definition['fields'] as $f) {
                if ($f['type'] === 'relation') {
                    foreach (array_unique($data[$f['key']] ?? []) as $id) {
                        DB::table('formaku_relations')->insert(['revision_id' => $rev->id, 'field' => $f['key'], 'target_id' => $id]);
                    }
                }
            }
            $record->update(['publish_at' => null, 'draft_id' => $rev->id, 'version' => $record->version + 1]);
            $this->event($record, $actor, 'saved');

            return $record->fresh();
        });
    }

    public function publish(Record $record, int $expected, int $actor): Record
    {
        return DB::transaction(function () use ($record, $expected, $actor) {
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
            $r->update(['published_id' => $rev->id, 'published_slug' => $slug, 'published_title' => $data['title'] ?? $data['name'] ?? $slug, 'search_text' => implode(' ', array_filter($data, 'is_string')), 'version' => $r->version + 1, 'publish_at' => null]);
            $this->event($r, $actor, 'published');

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

    private function event(Record $r, int $actor, string $type): void
    {
        DB::table('formaku_events')->insert(['record_id' => $r->id, 'actor_id' => $actor, 'type' => $type, 'payload' => json_encode(['version' => $r->version, 'state' => $r->state]), 'created_at' => now(), 'updated_at' => now()]);
    }
}
