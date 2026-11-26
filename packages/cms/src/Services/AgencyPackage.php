<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Contracts\PanelBlockType;
use Formaku\Cms\Models\AgencyDeployment;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgencyPackage
{
    private const SUITE = ['id' => 'b2b-rfq-v1', 'actors' => ['customer-a', 'customer-b', 'editor'],
        'checks' => ['service-cta', 'submit', 'status', 'private-note', 'send-reply', 'ownership', 'mobile-keyboard', 'accessibility']];

    public function __construct(private PortalBlueprint $blueprints) {}

    /** Export only approved configuration, never request records, brief, sources, or block text. */
    public function export(PortalProject $project, int $revision, bool $includeFieldText = false): array
    {
        $approved = $this->blueprints->validate($project->proposal);
        $reference = $this->blueprints->reference();
        $knownFields = array_column($reference['fields'], 'label', 'key');
        $definition = Collection::where('key', 'requests')->where('kind', 'data')->first()?->definition;
        $sourceFields = $definition ? PortalFields::configured(new Collection(['key' => 'requests', 'kind' => 'data', 'definition' => $definition])) : $approved['fields'];
        $fields = [];
        $fieldAccess = [];
        $required = [];
        foreach ($sourceFields as $field) {
            if (in_array($field['key'], ['customer_reply', 'internal_note'], true)) {
                continue;
            }
            if ($field['required'] ?? false) {
                $required[] = $field['key'];
            }
            $options = $field['options'] ?? [];
            $fields[] = ['key' => $field['key'], 'type' => $field['type'],
                'label' => $includeFieldText ? $field['label'] : ($knownFields[$field['key']] ?? ucfirst(str_replace('_', ' ', $field['key']))),
                'options' => $includeFieldText ? $options : array_map(fn ($i) => 'Opcja '.($i + 1), array_keys($options))];
        }
        $states = $definition['states'] ?? $approved['states'];
        $defaults = $this->blueprints->requestsDefinition($approved);
        $workflow = ['transitions' => $definition['transitions'] ?? $defaults['transitions']];
        if ($required) {
            $workflow['required_fields'] = $required;
        }
        foreach (['portal_attachments', 'portal_attachment_limits'] as $setting) {
            if (array_key_exists($setting, $definition ?? [])) {
                $workflow[$setting] = $definition[$setting];
            }
        }
        if (isset($definition['access'])) {
            $workflow['access'] = $definition['access'];
        }
        $managed = array_column($fields, 'key');
        foreach ($definition['fields'] ?? [] as $field) {
            if (isset($field['access']) && in_array($field['key'], [...$managed, 'title', 'description', 'internal_note', 'customer_reply'], true)) {
                $fieldAccess[$field['key']] = $field['access'];
            }
        }
        if ($fieldAccess !== []) {
            $workflow['field_access'] = $fieldAccess;
        }
        $pages = [];
        $active = AgencyDeployment::where('project_id', $project->id)->find(DB::table('formaku_agency_package_head')->where('id', 1)->value('deployment_id'));
        $pageIds = $active ? array_values($active->page_ids) : array_column($project->pages ?? [], 'id');
        $collection = Collection::where('key', 'agency_pages')->where('kind', 'content')->first();
        $records = $collection ? Record::where('collection_id', $collection->id)->whereIntegerInRaw('id', $pageIds)->with('draft')->get()->keyBy('id') : collect();
        foreach ($pageIds ?: $approved['pages'] as $i => $_) {
            if (isset($pageIds[$i])) {
                $record = $records->get($pageIds[$i]);
                abort_unless($record && $record->draft, 409, 'A managed page is unavailable.');
                $blocks = $this->layout($record->draft->data['blocks'] ?? [], true);
            } else {
                $blocks = ['hero', 'cta'];
            }
            $pages[] = ['key' => $i === 0 ? 'oferta-b2b' : 'usluga-'.($i + 1), 'blocks' => $blocks];
        }

        return ['format' => 'formaku.agency-package', 'version' => 1, 'revision' => $revision,
            'pages' => $pages, 'fields' => $fields, 'states' => $states, 'workflow' => $workflow, 'acceptance' => self::SUITE];
    }

    public function proposal(array $package, array $target): array
    {
        $data = Validator::make(['package' => $package], [
            'package' => 'required|array:format,version,revision,pages,fields,states,workflow,acceptance',
            'package.format' => 'required|in:formaku.agency-package', 'package.version' => 'required|integer|in:1',
            'package.revision' => 'required|integer|min:1|max:2147483647',
            'package.pages' => 'required|array|list|min:1|max:8', 'package.pages.*' => 'required|array:key,blocks',
            'package.pages.*.key' => 'required|string|max:100|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D|distinct:strict',
            'package.pages.*.blocks' => 'required|array|list|min:1|max:100', 'package.fields' => 'present|array|list|max:12',
            'package.states' => 'required|array|list|min:2|max:8', 'package.workflow' => 'sometimes|array:transitions,access,field_access,required_fields,portal_attachments,portal_attachment_limits', 'package.acceptance' => 'required|array',
        ])->validate()['package'];
        if (($data['acceptance']['id'] ?? '') !== self::SUITE['id']
            || self::digest($data['acceptance']) !== self::digest(self::SUITE)) {
            throw ValidationException::withMessages(['package.acceptance' => 'Use the built-in b2b-rfq-v1 acceptance suite.']);
        }
        $reference = $this->blueprints->reference();
        $name = $target['name'] ?? $reference['name'];
        $body = $target['body'] ?? $reference['pages'][0]['body'];
        $pages = [];
        foreach ($data['pages'] as $index => $page) {
            $this->layout($page['blocks'], false);
            $pages[] = ['slug' => $page['key'], 'title' => $index === 0 ? $name : $name.' – '.($index + 1),
                'body' => $body, 'cta' => $reference['pages'][0]['cta']];
        }

        return $this->blueprints->validate(['name' => $name, 'pages' => $pages, 'fields' => $data['fields'], 'states' => $data['states'], 'questions' => []]);
    }

    /** Safe configuration extracted from approved forms. Field policies can only narrow built-in staff restrictions. */
    public function requests(array $proposal, array $package): array
    {
        $definition = $this->blueprints->requestsDefinition($proposal);
        $workflow = $package['workflow'] ?? [];
        if (array_key_exists('transitions', $workflow)) {
            $definition['transitions'] = $workflow['transitions'];
        }
        if (array_key_exists('access', $workflow)) {
            $definition['access'] = $workflow['access'];
        }
        $required = $workflow['required_fields'] ?? [];
        if (! is_array($required) || ! array_is_list($required) || count(array_filter($required, 'is_string')) !== count($required)
            || array_diff($required, array_column($proposal['fields'], 'key'))) {
            throw ValidationException::withMessages(['package.workflow' => 'Unknown required portal field.']);
        }
        foreach (['portal_attachments', 'portal_attachment_limits'] as $setting) {
            if (array_key_exists($setting, $workflow)) {
                $definition[$setting] = $workflow[$setting];
            }
        }
        $policies = $workflow['field_access'] ?? [];
        if (! is_array($policies) || array_diff(array_keys($policies), array_column($definition['fields'], 'key'))) {
            throw ValidationException::withMessages(['package.workflow' => 'Unknown field policy.']);
        }
        foreach ($definition['fields'] as &$field) {
            if (in_array($field['key'], $required, true)) {
                $field['required'] = true;
            }
            if (! array_key_exists($field['key'], $policies)) {
                continue;
            }
            $policy = $policies[$field['key']];
            if (! is_array($policy)) {
                throw ValidationException::withMessages(['package.workflow' => 'Invalid field policy.']);
            }
            foreach ($field['access'] ?? [] as $operation => $roles) {
                if (! isset($policy[$operation]) || ! is_array($policy[$operation])
                    || count(array_filter($policy[$operation], 'is_string')) !== count($policy[$operation])
                    || array_diff($policy[$operation], $roles)) {
                    throw ValidationException::withMessages(['package.workflow' => 'The package must retain staff restrictions.']);
                }
            }
            $field['access'] = $policy;
        }
        unset($field);

        return app(SchemaValidator::class)->validate(['key' => 'requests', 'kind' => 'data', 'name' => 'Portal', 'definition' => $definition])['definition'];
    }

    public function page(array $page, array $portable): array
    {
        $data = $this->blueprints->pageData($page);
        $data['blocks'] = $this->blocks($this->layout($portable['blocks'], false), $page);

        return $data;
    }

    /** Only block keys, child ordering and recognized layout variants cross site boundaries. */
    private function layout(array $blocks, bool $exporting, int $depth = 0): array
    {
        if (! array_is_list($blocks) || $depth > 10 || count($blocks) > 100) {
            throw ValidationException::withMessages(['package.pages' => 'Invalid block tree.']);
        }
        $registry = app(Registry::class);
        $types = $registry->builtInBlocks() + $registry->blocks();
        $out = [];
        foreach ($blocks as $block) {
            $key = is_string($block) ? $block : ($block['type'] ?? null);
            if (! is_string($key) || ! isset($types[$key])) {
                throw ValidationException::withMessages(['package.pages' => 'Install the registered block required by this layout.']);
            }
            if (! $exporting && is_array($block) && array_diff(array_keys($block), ['type', 'children', 'variant'])) {
                throw ValidationException::withMessages(['package.pages' => 'Block layouts contain only a type, variant and children.']);
            }
            $shape = ['type' => $key];
            $variant = $exporting ? ($block['props'][$key === 'columns' ? 'columns' : 'layout'] ?? null) : ($block['variant'] ?? null);
            if (($key === 'columns' && in_array($variant, [2, 3], true)) || ($key === 'container' && $variant === 'stack')) {
                $shape['variant'] = $variant;
            }
            $children = is_array($block) ? ($block['children'] ?? []) : [];
            if ($children !== []) {
                if (! is_array($children) || ! ($types[$key] instanceof PanelBlockType) || ! $types[$key]->acceptsChildren()) {
                    throw ValidationException::withMessages(['package.pages' => 'Invalid block children.']);
                }
                $shape['children'] = $this->layout($children, $exporting, $depth + 1);
            }
            $out[] = count($shape) === 1 ? $key : $shape;
        }

        return $out;
    }

    private function blocks(array $layout, array $page): array
    {
        $base = $this->blueprints->pageData($page)['blocks'];
        $out = [];
        foreach ($layout as $element) {
            $key = is_string($element) ? $element : $element['type'];
            $props = match ($key) {
                'hero' => $base[0]['props'], 'cta' => $base[1]['props'],
                'text' => ['document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $page['body']]]]]]],
                'container' => is_array($element) && isset($element['variant']) ? ['layout' => $element['variant']] : [],
                'columns' => is_array($element) && isset($element['variant']) ? ['columns' => $element['variant']] : [],
                default => $this->extensionDefaults($key, $page),
            };
            $block = ['type' => $key, 'props' => $props];
            if (is_array($element) && isset($element['children'])) {
                $block['children'] = $this->blocks($element['children'], $page);
            }
            $out[] = $block;
        }

        return $out;
    }

    private function extensionDefaults(string $key, array $page): array
    {
        $extension = app(Registry::class)->blocks()[$key] ?? null;
        if (! $extension) {
            return [];
        }
        $props = [];
        foreach ($extension->schema() as $key => $rules) {
            $rules = is_string($rules) ? explode('|', $rules) : $rules;
            if (is_array($rules) && in_array('required', $rules, true) && in_array('string', $rules, true)) {
                $props[$key] = $key === 'title' ? $page['title'] : $page['body'];
            }
        }
        Validator::make($props, $extension->schema())->validate();

        return $props;
    }

    /** Stable across JSON key ordering and databases, while retaining ordered lists. */
    public static function digest(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }

        return $value;
    }
}
