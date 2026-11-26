<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\AgencyDeployment;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AgencyPackageInstaller
{
    public function __construct(private AgencyPackage $packages, private PortalBlueprint $blueprints, private PortalProjectInstaller $projects, private RecordService $records) {}

    public function preview(array $package, array $target): array
    {
        return DB::transaction(function () use ($package, $target) {
            $plan = $this->plan($package, $target, $this->active());

            return ['token' => $plan['token'], 'changes' => $plan['changes'], 'can_apply' => $plan['can_apply']];
        });
    }

    public function apply(array $package, array $target, string $token, object $user): array
    {
        return DB::transaction(function () use ($package, $target, $token, $user) {
            $active = $this->active();
            $plan = $this->plan($package, $target, $active);
            if ($active && $plan['repeat']) {
                return ['deployment_id' => $active->id, 'changes' => [], 'page_ids' => $active->page_ids];
            }
            abort_unless(hash_equals($plan['token'], $token) && $plan['can_apply'], 409, 'Preview the current changes and resolve conflicts before applying.');
            $pageIds = $this->ownedPages($active);
            $projectId = $active?->project_id;
            if ($active) {
                $pageIds = $this->writeChanges($plan, $pageIds, $user);
            } else {
                $project = PortalProject::create(['actor_id' => $user->getAuthIdentifier(), 'provider' => 'reference', 'state' => 'review', 'version' => 1,
                    'brief' => '', 'materials' => '', 'proposal' => $plan['proposal'], 'snapshot' => $this->projects->snapshot()]);
                $project = $this->projects->apply($project, 1, $user, $plan['initial_definition']);
                $projectId = $project->id;
                $pageIds = array_column($project->pages, 'id', 'slug');
                $resources = $this->readResources($plan['intent'], $pageIds);
                $adjustments = [];
                foreach ($plan['intent'] as $key => $value) {
                    if (str_starts_with($key, 'page.') && ! $this->same($resources['values'][$key] ?? null, $value)) {
                        $adjustments[] = ['key' => $key, 'status' => 'update'];
                    }
                }
                $this->writeChanges(['intent' => $plan['intent'], 'resources' => $resources, 'changes' => $adjustments], $pageIds, $user);
                $fresh = Record::whereIntegerInRaw('id', $pageIds)->get()->keyBy('id');
                $project->update(['pages' => array_map(function ($page) use ($fresh) {
                    $record = $fresh[$page['id']];

                    return array_replace($page, ['version' => $record->version, 'draft_id' => $record->draft_id]);
                }, $project->pages)]);
            }
            $baseline = array_intersect_key($this->readResources($plan['intent'], $pageIds)['values'], $plan['intent']);
            foreach ($plan['changes'] as $change) {
                if ($change['status'] === 'local' && array_key_exists($change['key'], $active?->baseline ?? [])) {
                    $baseline[$change['key']] = $active->baseline[$change['key']];
                }
            }
            $managedIds = array_filter($pageIds, fn ($key) => isset($plan['intent']['page.'.$key]), ARRAY_FILTER_USE_KEY);
            $deployment = AgencyDeployment::create(['project_id' => $projectId, 'revision' => (int) $package['revision'], 'actor_id' => $user->getAuthIdentifier(),
                'previous_id' => $active?->id, 'digest' => $plan['digest'], 'target' => $plan['target'], 'intent' => $plan['intent'],
                'baseline' => $baseline, 'page_ids' => $managedIds, 'retained_page_ids' => array_diff_key($pageIds, $managedIds)]);
            DB::table('formaku_agency_package_head')->where('id', 1)->update(['deployment_id' => $deployment->id]);

            return ['deployment_id' => $deployment->id, 'changes' => $plan['changes'], 'page_ids' => $managedIds];
        });
    }

    public function rollback(int $deploymentId, object $user): array
    {
        return DB::transaction(function () use ($deploymentId, $user) {
            $active = $this->active();
            abort_unless($active?->id === $deploymentId && $active->previous_id !== null, 409, 'Only the active update can be rolled back.');
            $previous = AgencyDeployment::findOrFail($active->previous_id);
            $plan = $this->compare($previous->intent, $active);
            abort_unless($plan['can_apply'], 409, 'The configuration has local edits or an incompatible active workflow.');
            $owned = $this->ownedPages($active);
            $this->writeChanges($plan, $owned, $user);
            $previous->update(['retained_page_ids' => $previous->retained_page_ids + array_diff_key($owned, $previous->page_ids)]);
            $active->update(['rolled_back_at' => now()]);
            DB::table('formaku_agency_package_head')->where('id', 1)->update(['deployment_id' => $previous->id]);

            return ['rolled_back' => true, 'deployment_id' => $previous->id, 'changes' => $plan['changes']];
        });
    }

    private function ownedPages(?AgencyDeployment $active): array
    {
        return $active ? $active->page_ids + $active->retained_page_ids : [];
    }

    private function active(): ?AgencyDeployment
    {
        $head = DB::table('formaku_agency_package_head')->where('id', 1)->lockForUpdate()->firstOrFail();

        return $head->deployment_id ? AgencyDeployment::findOrFail($head->deployment_id) : null;
    }

    private function plan(array $package, array $overrides, ?AgencyDeployment $active): array
    {
        $target = array_replace($active?->target ?? [], $overrides);
        $proposal = $this->packages->proposal($package, $target);
        $definition = $this->packages->requests($proposal, $package);
        $intent = $this->flatten($definition);
        foreach ($proposal['pages'] as $i => $page) {
            $intent['page.'.$page['slug']] = $this->packages->page($page, $package['pages'][$i]);
        }
        $plan = $this->compare($intent, $active);
        $digest = AgencyPackage::digest([$package, $target]);
        $repeat = $active && $active->digest === $digest;
        if ($active && ! $repeat && (int) $package['revision'] <= $active->revision) {
            $plan['changes'][] = ['key' => 'revision', 'status' => 'conflict', 'reason' => 'Choose a higher package revision.'];
            $plan['can_apply'] = false;
        }
        if (! $active) {
            try {
                $requests = $plan['resources']['requests'];
                if ($requests && $requests->kind !== 'data') {
                    abort(409, 'A data collection is required.');
                }
                $compatible = $this->blueprints->requestsDefinition($proposal, $requests?->definition);
                if ($requests) {
                    $old = $requests->definition;
                    if (array_key_exists('access', $old) && array_key_exists('access', $definition)
                        && ! $this->same($definition['access'], $old['access'])) {
                        abort(409, 'Existing collection permissions differ from the package.');
                    }
                    foreach (array_column($old['fields'], null, 'key') as $key => $field) {
                        if (isset($field['access']) && isset($package['workflow']['field_access'][$key])
                            && ! $this->same($field['access'], $package['workflow']['field_access'][$key])) {
                            abort(409, 'Existing field permissions differ from the package.');
                        }
                    }
                }
                $declared = array_column($definition['fields'], null, 'key');
                foreach ($compatible['fields'] as &$field) {
                    $desired = $declared[$field['key']] ?? [];
                    if (isset($package['workflow']['field_access'][$field['key']])) {
                        $field['access'] = $desired['access'];
                    }
                    if ($desired['required'] ?? false) {
                        $field['required'] = true;
                    }
                }
                unset($field);
                foreach (['access', 'transitions', 'portal_attachments', 'portal_attachment_limits'] as $setting) {
                    if (array_key_exists($setting, $definition)) {
                        $compatible[$setting] = $definition[$setting];
                    }
                }
                $plan['initial_definition'] = app(SchemaValidator::class)->validate(['key' => 'requests', 'kind' => 'data', 'name' => 'Portal', 'definition' => $compatible], $requests?->definition)['definition'];
                if ($requests && $this->workflowInUse($requests, $definition)) {
                    abort(409, 'The existing workflow is in use.');
                }
            } catch (HttpException|ValidationException $error) {
                $plan['can_apply'] = false;
                $plan['changes'][] = ['key' => 'requests', 'status' => 'conflict', 'reason' => $error->getMessage()];
            }
        }
        $token = AgencyPackage::digest([$active?->id, $digest, $plan['resources']['versions'], $plan['changes']]);

        return $plan + compact('proposal', 'target', 'token', 'digest', 'repeat');
    }

    private function compare(array $intent, ?AgencyDeployment $active): array
    {
        $old = $active?->intent ?? [];
        $resources = $this->readResources($intent + $old, $this->ownedPages($active));
        $changes = [];
        foreach (array_keys($intent + $old) as $key) {
            $new = $intent[$key] ?? null;
            $local = $resources['values'][$key] ?? null;
            $baseline = $active?->baseline[$key] ?? null;
            $upstreamChanged = $active && ! $this->same($old[$key] ?? null, $new);
            $localChanged = $active && ! $this->same($local, $baseline);
            $status = match (true) {
                isset($resources['collisions'][$key]) => 'conflict',
                $new === null && isset($resources['activePages'][$key]) => 'conflict',
                $active && $localChanged && ! $upstreamChanged => 'local',
                $this->same($new, $local) => 'unchanged',
                $upstreamChanged && $localChanged => 'conflict',
                $new === null && str_starts_with($key, 'config.') => 'remove',
                $new === null && str_starts_with($key, 'field.') && ! $this->hasHistoricalValue($resources['requests'], substr($key, 6)) => 'remove',
                $new === null => 'retained',
                $local === null => 'new',
                default => 'update',
            };
            $changes[] = ['key' => $key, 'status' => $status];
        }
        $plan = compact('intent', 'resources', 'changes');
        if ($active && ! in_array('conflict', array_column($changes, 'status'), true)) {
            try {
                $next = $this->nextDefinition($plan);
                $requests = $resources['requests'];
                if (! $requests || $this->workflowInUse($requests, $next)) {
                    abort(409, 'An active request requires the current workflow.');
                }
                $oldSchema = $requests->definition;
                $retired = $this->retiredFields($plan);
                $oldSchema['fields'] = array_values(array_filter($oldSchema['fields'], fn ($field) => ! in_array($field['key'], $retired, true)));
                app(SchemaValidator::class)->validate(['key' => 'requests', 'name' => $requests->name, 'kind' => 'data', 'definition' => $next], $oldSchema);
            } catch (HttpException|ValidationException $error) {
                $plan['changes'][] = ['key' => 'requests', 'status' => 'conflict', 'reason' => $error->getMessage()];
            }
        }
        $plan['can_apply'] = ! in_array('conflict', array_column($plan['changes'], 'status'), true);

        return $plan;
    }

    private function workflowInUse(Collection $requests, array $next): bool
    {
        $inUse = Record::where('collection_id', $requests->id)->distinct()->pluck('state')->all();
        if (array_diff($inUse, $next['states'] ?? [])) {
            return true;
        }
        $oldTransitions = $requests->definition['transitions'] ?? [];
        foreach ($inUse as $state) {
            if (collect($oldTransitions)->contains('from', $state)
                && ! collect($next['transitions'] ?? [])->contains('from', $state)) {
                return true;
            }
        }

        return false;
    }

    private function readResources(array $keys, array $pageIds): array
    {
        $collections = Collection::whereIn('key', ['requests', 'agency_pages'])->orderBy('key')->lockForUpdate()->get()->keyBy('key');
        $requests = $collections->get('requests');
        $pages = $collections->get('agency_pages');
        $values = $this->flatten($requests?->definition ?? []);
        $versions = ['requests' => $requests?->version, 'agency_pages' => $pages?->version];
        $collisions = [];
        $activePages = [];
        $records = $pages ? Record::where('collection_id', $pages->id)->whereIntegerInRaw('id', array_values($pageIds))->orderBy('id')->lockForUpdate()->with('draft')->get()->keyBy('id') : collect();
        foreach (array_keys($keys) as $key) {
            if (! str_starts_with($key, 'page.')) {
                continue;
            }
            $slug = substr($key, 5);
            $record = $records->get($pageIds[$slug] ?? 0);
            if ($record) {
                $values[$key] = $record->draft?->data;
                $versions[$key] = $record->version;
                if ($record->published_id || $record->publish_at) {
                    $activePages[$key] = true;
                }
            } elseif (isset($pageIds[$slug]) || Record::where('published_slug', $slug)->exists()
                || ($pages && Record::where('collection_id', $pages->id)->whereHas('draft', fn ($query) => $query->where('data->slug', $slug))->exists())) {
                $collisions[$key] = true;
            }
        }

        return compact('requests', 'pages', 'records', 'values', 'versions', 'collisions', 'activePages');
    }

    private function flatten(array $definition): array
    {
        $values = [];
        foreach ($definition as $name => $value) {
            if ($name === 'fields') {
                foreach ($value as $field) {
                    $values['field.'.$field['key']] = $field;
                }
            } else {
                $values['config.'.$name] = $value;
            }
        }

        return $values;
    }

    private function nextDefinition(array $plan): array
    {
        $definition = $plan['resources']['requests']?->definition ?? [];
        $fields = array_column($definition['fields'] ?? [], null, 'key');
        foreach ($plan['changes'] as ['key' => $key, 'status' => $status]) {
            if ($status === 'remove') {
                if (str_starts_with($key, 'field.')) {
                    unset($fields[substr($key, 6)]);
                } elseif (str_starts_with($key, 'config.')) {
                    unset($definition[substr($key, 7)]);
                }

                continue;
            }
            if (! in_array($status, ['update', 'new'], true)) {
                continue;
            }
            if (str_starts_with($key, 'field.')) {
                $field = $plan['intent'][$key];
                $oldOptions = $fields[$field['key']]['options'] ?? [];
                $removed = array_diff($oldOptions, $field['options'] ?? []);
                if ($removed && Revision::whereIn('record_id', Record::where('collection_id', $plan['resources']['requests']->id)->select('id'))
                    ->whereIn('data->'.$field['key'], $removed)->exists()) {
                    abort(409, 'Request history refers to a removed option of field '.$field['key'].'.');
                }
                $fields[$field['key']] = $field;
            } elseif (str_starts_with($key, 'config.')) {
                $definition[substr($key, 7)] = $plan['intent'][$key];
            }
        }
        $definition['fields'] = array_values($fields);

        return $definition;
    }

    private function writeChanges(array $plan, array $pageIds, object $user): array
    {
        $requests = $plan['resources']['requests'];
        $this->projects->writeCollection($requests, 'requests', $requests->name, 'data', $this->nextDefinition($plan), $this->retiredFields($plan));
        $pages = $plan['resources']['pages'];
        abort_unless($pages && $pages->kind === 'content', 409);
        foreach ($plan['changes'] as ['key' => $key, 'status' => $status]) {
            if (! str_starts_with($key, 'page.') || ! in_array($status, ['new', 'update'], true)) {
                continue;
            }
            $slug = substr($key, 5);
            $record = $plan['resources']['records']->get($pageIds[$slug] ?? 0);
            $data = $plan['intent'][$key];
            app(Renderer::class)->blocks($data['blocks']);
            $saved = $this->records->save($pages, $record, $data, $record?->version ?? 0, $user->getAuthIdentifier(), actorUser: $user);
            $pageIds[$slug] = $saved->id;
        }

        return $pageIds;
    }

    private function retiredFields(array $plan): array
    {
        $fields = [];
        foreach ($plan['changes'] as ['key' => $key, 'status' => $status]) {
            if ($status === 'remove' && str_starts_with($key, 'field.')) {
                $fields[] = substr($key, 6);
            }
        }

        return $fields;
    }

    private function hasHistoricalValue(?Collection $requests, string $field): bool
    {
        return $requests && Revision::whereIn('record_id', Record::where('collection_id', $requests->id)->select('id'))
            ->whereNotNull('data->'.$field)->exists();
    }

    private function same(mixed $left, mixed $right): bool
    {
        return AgencyPackage::digest($left) === AgencyPackage::digest($right);
    }
}
