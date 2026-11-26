<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Blocks;
use Formaku\Cms\Contracts\ActionHandler;
use Formaku\Cms\Contracts\BlockType;
use Formaku\Cms\Contracts\FieldType;
use Formaku\Cms\Contracts\PanelBlockType;
use Formaku\Cms\Contracts\PanelFieldType;
use Formaku\Cms\Contracts\ResourceAdapter;
use Formaku\Cms\Fields\Field;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class Registry
{
    public const PANEL_KEY_PATTERN = '[A-Za-z][A-Za-z0-9_-]{0,63}';

    private array $fields = [];

    private array $blocks = [];

    /** @var array<string, PanelBlockType> */
    private array $builtInBlocks = [];

    private array $resources = [];

    private array $actions = [];

    private array $panelResources = [];

    private array $panelActions = [];

    public function __construct()
    {
        foreach ([
            new Field('text', 'Text', 'text', ['string']),
            new Field('richtext', 'Rich text', 'richtext', ['array']),
            new Field('number', 'Number', 'number', ['numeric']),
            new Field('date', 'Date', 'date', ['date']),
            new Field('boolean', 'Yes / no', 'boolean', ['boolean']),
            new Field('select', 'Select', 'select', fn (array $definition) => ['string', Rule::in($definition['options'] ?? [])]),
            new Field('media', 'Media', 'media', ['integer']),
            new Field('relation', 'Relation', 'relation', ['array']),
        ] as $type) {
            $this->fields[$type->key()] = $type;
        }
        foreach ([
            new Blocks\Hero, new Blocks\Text, new Blocks\Cta, new Blocks\Image,
            new Blocks\Cards, new Blocks\Faq, new Blocks\Container, new Blocks\Columns,
        ] as $type) {
            $this->builtInBlocks[$type->key()] = $type;
        }
    }

    public function field(FieldType $type): void
    {
        $key = $type->key();
        if (in_array($key, SchemaValidator::TYPES, true)) {
            throw new InvalidArgumentException("Cannot replace built-in field type [$key].");
        }
        if ($type instanceof PanelFieldType) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,48}$/', $key)) {
                throw new InvalidArgumentException("Invalid field type key [$key].");
            }
            if (! in_array($type->input(), SchemaValidator::TYPES, true)) {
                throw new InvalidArgumentException("Field type [$key] uses an unknown panel input [{$type->input()}].");
            }
        }
        $this->fields[$key] = $type;
    }

    /** @return list<array{key: string, label: string, input: string}> */
    public function fieldTypes(): array
    {
        $types = [];
        foreach ($this->fields as $type) {
            if ($type instanceof PanelFieldType) {
                $types[] = ['key' => $type->key(), 'label' => $type->label(), 'input' => $type->input()];
            }
        }

        return $types;
    }

    public function block(BlockType $type): void
    {
        $key = $type->key();
        if (isset($this->builtInBlocks[$key])) {
            throw new InvalidArgumentException("Cannot replace built-in block type [$key].");
        }
        $this->blocks[$key] = $type;
    }

    /** @return array<string, PanelBlockType> */
    public function builtInBlocks(): array
    {
        return $this->builtInBlocks;
    }

    public function blockTypes(): array
    {
        $types = [];
        foreach ($this->builtInBlocks + $this->blocks as $type) {
            if ($type instanceof PanelBlockType) {
                $types[] = [
                    'key' => $type->key(), 'label' => $type->label(),
                    'schema' => $type->panelSchema(), 'children' => $type->acceptsChildren(),
                ];
            }
        }

        return $types;
    }

    /**
     * @param  array{
     *   label: string,
     *   columns: list<array{key: string, label: string}>,
     *   authorize: callable(object): bool,
     *   row_key?: string,
     *   row_actions?: list<array{action: string, label: string, input_key: string}>,
     * }|null  $panel
     */
    public function resource(ResourceAdapter $type, ?array $panel = null): void
    {
        $key = $type->key();
        $this->resources[$key] = $type;
        $this->panelResources[$key] = $this->panelResourceDefinition($key, $panel);
    }

    /**
     * @param  array{
     *   label: string,
     *   fields: list<array{key: string, label: string, type: 'text'|'integer'|'boolean', required?: bool}>,
     *   authorize: callable(object): bool,
     *   row_only?: bool,
     * }|null  $panel
     */
    public function action(string $key, ActionHandler $type, ?array $panel = null): void
    {
        $this->actions[$key] = $type;
        $this->panelActions[$key] = $this->panelActionDefinition($key, $panel);
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function blocks(): array
    {
        return $this->blocks;
    }

    public function resources(): array
    {
        return $this->resources;
    }

    public function actions(): array
    {
        return $this->actions;
    }

    /**
     * Lists the resources and standalone actions the user may open in the panel.
     *
     * A row action carries the fields the panel asks for before running it. The field named by
     * input_key is left out, because the row supplies its value.
     *
     * @return array{
     *   resources: list<array{
     *     key: string,
     *     label: string,
     *     columns: list<array{key: string, label: string}>,
     *     row_key?: string,
     *     row_actions?: list<array{
     *       action: string,
     *       label: string,
     *       input_key: string,
     *       fields: list<array{key: string, label: string, type: string, required: bool}>,
     *     }>,
     *   }>,
     *   actions: list<array{
     *     key: string,
     *     label: string,
     *     fields: list<array{key: string, label: string, type: string, required: bool}>,
     *   }>,
     * }
     */
    public function panelExtensions(object $user): array
    {
        $resources = [];
        foreach ($this->panelResources as $entry) {
            $visible = $this->visiblePanelEntry($entry, $user);
            if ($visible === null) {
                continue;
            }

            if (isset($visible['row_actions'])) {
                $rowActions = [];
                foreach ($visible['row_actions'] as $rowAction) {
                    $resolved = $this->resolveRowAction($rowAction, $user);
                    if ($resolved === null) {
                        continue;
                    }

                    $rowActions[] = $rowAction + ['fields' => array_values(array_filter(
                        $resolved['action']['fields'],
                        static fn (array $field): bool => $field['key'] !== $rowAction['input_key'],
                    ))];
                }
                $visible['row_actions'] = $rowActions;
            }
            $resources[] = $visible;
        }

        $actions = [];
        foreach ($this->panelActions as $entry) {
            if ($entry === null || ($entry['row_only'] ?? false) === true) {
                continue;
            }
            $visible = $this->visiblePanelEntry($entry, $user);
            if ($visible !== null) {
                $actions[] = $visible;
            }
        }

        return [
            'resources' => $resources,
            'actions' => $actions,
        ];
    }

    public function panelResource(string $key, object $user): ?array
    {
        return $this->visiblePanelEntry($this->panelResources[$key] ?? null, $user);
    }

    public function panelAction(string $key, object $user): ?array
    {
        $entry = $this->panelActions[$key] ?? null;
        if ($entry === null || ($entry['row_only'] ?? false) === true) {
            return null;
        }

        return $this->visiblePanelEntry($entry, $user);
    }

    public function panelRowAction(string $resourceKey, string $actionKey, object $user): ?array
    {
        $resource = $this->panelResource($resourceKey, $user);
        if ($resource === null || ! isset($resource['row_key'], $resource['row_actions'])) {
            return null;
        }

        foreach ($resource['row_actions'] as $rowAction) {
            if ($rowAction['action'] !== $actionKey) {
                continue;
            }

            $resolved = $this->resolveRowAction($rowAction, $user);
            if ($resolved === null) {
                return null;
            }

            return $resolved + ['row_key' => $resource['row_key']];
        }

        return null;
    }

    private function resolveRowAction(array $rowAction, object $user): ?array
    {
        $action = $this->visiblePanelEntry($this->panelActions[$rowAction['action']] ?? null, $user);
        if ($action === null) {
            return null;
        }

        foreach ($action['fields'] as $field) {
            if ($field['key'] === $rowAction['input_key'] && in_array($field['type'], ['text', 'integer'], true)) {
                return [
                    'action' => $action,
                    'input_key' => $rowAction['input_key'],
                    'input_type' => $field['type'],
                ];
            }
        }

        return null;
    }

    private function visiblePanelEntry(?array $entry, object $user): ?array
    {
        if ($entry === null || ($entry['authorize'])($user) !== true) {
            return null;
        }

        return $entry['metadata'];
    }

    private function panelResourceDefinition(string $key, ?array $panel): ?array
    {
        $base = $this->panelDefinition($key, $panel, 'columns');
        if ($base === null) {
            return null;
        }

        $hasRowKey = array_key_exists('row_key', $panel);
        $hasRowActions = array_key_exists('row_actions', $panel);
        if (! $hasRowKey && ! $hasRowActions) {
            return $base;
        }

        $rowKey = $panel['row_key'] ?? null;
        $rowActions = $panel['row_actions'] ?? null;
        $columnKeys = array_column($base['metadata']['columns'], 'key');
        if (! $hasRowKey || ! $hasRowActions || ! is_string($rowKey) || ! in_array($rowKey, $columnKeys, true)
            || ! is_array($rowActions) || ! array_is_list($rowActions) || $rowActions === []) {
            return null;
        }

        $normalizedActions = [];
        foreach ($rowActions as $item) {
            if (! is_array($item)) {
                return null;
            }
            $actionKey = $item['action'] ?? null;
            $label = $item['label'] ?? null;
            $inputKey = $item['input_key'] ?? null;
            if (! is_string($actionKey) || ! preg_match('/\A'.self::PANEL_KEY_PATTERN.'\z/', $actionKey)
                || isset($normalizedActions[$actionKey]) || ! $this->isPanelLabel($label)
                || ! $this->isValidPanelFieldKey($inputKey)) {
                return null;
            }

            $normalizedActions[$actionKey] = [
                'action' => $actionKey,
                'label' => $label,
                'input_key' => $inputKey,
            ];
        }

        $base['metadata']['row_key'] = $rowKey;
        $base['metadata']['row_actions'] = array_values($normalizedActions);

        return $base;
    }

    private function panelActionDefinition(string $key, ?array $panel): ?array
    {
        $base = $this->panelDefinition($key, $panel, 'fields');
        if ($base === null) {
            return null;
        }

        $rowOnly = array_key_exists('row_only', $panel) ? $panel['row_only'] : false;
        if (! is_bool($rowOnly)) {
            return null;
        }

        return $base + ['row_only' => $rowOnly];
    }

    private function panelDefinition(string $key, ?array $panel, string $itemsKey): ?array
    {
        $items = $panel[$itemsKey] ?? null;
        $authorize = $panel['authorize'] ?? null;
        $label = $panel['label'] ?? null;

        if (! preg_match('/\A'.self::PANEL_KEY_PATTERN.'\z/', $key)
            || ! $this->isPanelLabel($label) || ! is_callable($authorize)
            || ! is_array($items) || ! array_is_list($items)
            || ($itemsKey === 'columns' && $items === [])) {
            return null;
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                return null;
            }

            $itemKey = $item['key'] ?? null;
            $itemLabel = $item['label'] ?? null;
            if (! $this->isValidPanelFieldKey($itemKey) || isset($normalized[$itemKey]) || ! $this->isPanelLabel($itemLabel)) {
                return null;
            }

            $normalized[$itemKey] = ['key' => $itemKey, 'label' => $itemLabel];
            if ($itemsKey === 'fields') {
                $type = $item['type'] ?? null;
                $required = array_key_exists('required', $item) ? $item['required'] : false;
                if (! in_array($type, ['text', 'integer', 'boolean'], true) || ! is_bool($required)) {
                    return null;
                }

                $normalized[$itemKey] += ['type' => $type, 'required' => $required];
            }
        }

        return [
            'authorize' => $authorize,
            'metadata' => ['key' => $key, 'label' => $label, $itemsKey => array_values($normalized)],
        ];
    }

    private function isValidPanelFieldKey(mixed $key): bool
    {
        return is_string($key)
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $key) === 1
            && ! in_array($key, ['__proto__', 'constructor', 'prototype'], true);
    }

    private function isPanelLabel(mixed $label): bool
    {
        return is_string($label) && trim($label) !== '';
    }
}
