<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class PortalProjectInstaller
{
    public function __construct(private PortalBlueprint $blueprints, private SchemaValidator $schemas, private RecordService $records) {}

    public function snapshot(): array
    {
        $versions = Collection::whereIn('key', ['requests', 'agency_pages'])->get()->keyBy('key');

        return ['requests' => (int) ($versions->get('requests')?->version ?? 0), 'agency_pages' => (int) ($versions->get('agency_pages')?->version ?? 0)];
    }

    public function changes(PortalProject $project): array
    {
        $collection = Collection::where('key', 'requests')->first();
        $old = $collection?->definition ?? [];
        $next = $this->blueprints->requestsDefinition($project->proposal, $old);
        $changes = [];
        foreach ($next as $key => $value) {
            if ($key === 'fields') {
                $fields = collect($old['fields'] ?? [])->keyBy('key');
                foreach ($value as $field) {
                    $before = $fields->get($field['key']);
                    if ($before != $field) {
                        $changes[] = ['label' => 'requests.'.$field['key'], 'before' => $before, 'after' => $field];
                    }
                }
            } elseif (($old[$key] ?? null) != $value) {
                $changes[] = ['label' => 'requests.'.$key, 'before' => $old[$key] ?? null, 'after' => $value];
            }
        }
        foreach ($project->proposal['pages'] as $page) {
            $changes[] = ['label' => '/p/'.$page['slug'], 'before' => null, 'after' => $page['title']];
        }

        return ['changes' => $changes, 'stale' => $project->snapshot != $this->snapshot()];
    }

    public function apply(PortalProject $project, int $expected, object $user, ?array $preparedRequests = null): PortalProject
    {
        try {
            return DB::transaction(function () use ($project, $expected, $user, $preparedRequests) {
                $project = PortalProject::lockForUpdate()->findOrFail($project->id);
                if ($project->state === 'applied' && in_array($expected, [$project->version, $project->version - 1], true)) {
                    return $project;
                }
                abort_unless($project->state === 'review' && $project->version === $expected, 409, 'Review the current project version.');
                $proposal = $this->blueprints->validate($project->proposal);
                $schemas = Collection::whereIn('key', ['requests', 'agency_pages'])->orderBy('key')->lockForUpdate()->get()->keyBy('key');
                foreach (['agency_pages', 'requests'] as $key) {
                    abort_unless(($project->snapshot[$key] ?? null) === (int) ($schemas->get($key)?->version ?? 0), 409, 'Collection changed. Review the new configuration before applying.');
                }

                $requests = $schemas->get('requests');
                abort_if($requests && $requests->kind !== 'data', 409, 'The requests collection must be a data collection.');
                $definition = $preparedRequests ?? $this->blueprints->requestsDefinition($proposal, $requests?->definition);
                if ($requests && Record::where('collection_id', $requests->id)->exists()) {
                    $old = $requests->definition;
                    $unmapped = Record::where('collection_id', $requests->id)->whereNotIn('state', $definition['states'])->exists();
                    abort_if($unmapped || array_diff($old['states'] ?? [], $definition['states']) !== []
                        || collect($old['transitions'] ?? [])->contains(fn ($t) => ! in_array($t, $definition['transitions'])), 409, 'The existing request workflow is in use.');
                }
                $this->writeCollection($requests, 'requests', 'Zapytania ofertowe', 'data', $definition);

                $pages = $schemas->get('agency_pages');
                if ($pages) {
                    abort_unless($pages->kind === 'content' && ($pages->definition['public'] ?? false), 409, 'The portal page collection has a conflicting definition.');
                    $expectedFields = array_column($this->blueprints->pageDefinition()['fields'], 'type', 'key');
                    $installed = collect($pages->definition['fields'] ?? [])->keyBy('key');
                    foreach ($expectedFields as $key => $type) {
                        $actual = $installed->get($key);
                        abort_unless(($actual['type'] ?? null) === $type && ! ($actual['private'] ?? false), 409, 'The portal page collection has a conflicting field.');
                    }
                } else {
                    $pages = $this->writeCollection(null, 'agency_pages', 'Strony portalu', 'content', $this->blueprints->pageDefinition());
                }

                $created = [];
                foreach ($proposal['pages'] as $page) {
                    $slug = $page['slug'];
                    abort_if(Record::where('published_slug', $slug)->exists()
                        || Record::where('collection_id', $pages->id)->whereHas('draft', fn ($q) => $q->where('data->slug', $slug))->exists(), 409, 'Page address already in use: '.$slug);
                    $data = $this->blueprints->pageData($page);
                    app(Renderer::class)->blocks($data['blocks']);
                    $record = $this->records->save($pages, null, $data, 0, $user->getAuthIdentifier(), actorUser: $user);
                    $created[] = ['id' => $record->id, 'draft_id' => $record->draft_id, 'version' => $record->version, 'slug' => $slug, 'title' => $page['title'],
                        'preview_url' => url(config('formaku.prefix').'/api/v1/records/'.$record->id.'/preview')];
                }
                $project->update(['state' => 'applied', 'version' => $project->version + 1, 'pages' => $created]);

                return $project;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Another change has claimed this collection or page. Review the current configuration.');
        }
    }

    public function writeCollection(?Collection $collection, string $key, string $name, string $kind, array $definition, array $retiredFieldKeys = []): Collection
    {
        $previous = $collection?->definition;
        if ($retiredFieldKeys !== []) {
            abort_unless($collection && DB::transactionLevel() > 0, 409, 'Retire fields in a locked collection transaction.');
            $locked = Collection::lockForUpdate()->findOrFail($collection->id);
            abort_unless($locked->version === $collection->version, 409, 'Collection changed.');
            $keys = array_column($definition['fields'] ?? [], 'key');
            $known = array_column($previous['fields'] ?? [], 'key');
            foreach ($retiredFieldKeys as $keyToRetire) {
                abort_unless(is_string($keyToRetire) && preg_match('/^[a-z][a-z0-9_]{0,48}$/D', $keyToRetire)
                    && in_array($keyToRetire, $known, true) && ! in_array($keyToRetire, $keys, true), 409, 'Invalid retired field.');
                $used = Revision::whereIn('record_id', Record::where('collection_id', $collection->id)->select('id'))
                    ->whereNotNull('data->'.$keyToRetire)->exists();
                abort_if($used, 409, 'Existing records use the field: '.$keyToRetire);
            }
            $previous['fields'] = array_values(array_filter($previous['fields'], fn ($field) => ! in_array($field['key'], $retiredFieldKeys, true)));
        }
        $schema = $this->schemas->validate(['key' => $key, 'name' => $collection?->name ?? $name, 'kind' => $kind, 'definition' => $definition], $previous);
        if ($collection && $collection->definition == $schema['definition']) {
            return $collection;
        }
        if ($collection) {
            $collection->update($schema + ['version' => $collection->version + 1]);
        } else {
            $collection = Collection::create($schema);
        }
        DB::table('formaku_schema_versions')->insert(['collection_id' => $collection->id, 'version' => $collection->version, 'definition' => json_encode($schema['definition'], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);

        return $collection;
    }
}
