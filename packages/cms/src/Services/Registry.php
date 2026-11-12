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
     * @param  array{label: string, columns: list<array{key: string, label: string}>, authorize: callable(object): bool}|null  $panel
     */
    public function resource(ResourceAdapter $type, ?array $panel = null): void
    {
        $key = $type->key();
        $this->resources[$key] = $type;
        $this->panelResources[$key] = $this->panelDefinition($key, $panel, 'columns');
    }

    /**
     * @param  array{label: string, fields: list<array{key: string, label: string, type: 'text'|'integer'|'boolean', required?: bool}>, authorize: callable(object): bool}|null  $panel
     */
    public function action(string $key, ActionHandler $type, ?array $panel = null): void
    {
        $this->actions[$key] = $type;
        $this->panelActions[$key] = $this->panelDefinition($key, $panel, 'fields');
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

    public function panelExtensions(object $user): array
    {
        return [
            'resources' => $this->visiblePanelEntries($this->panelResources, $user),
            'actions' => $this->visiblePanelEntries($this->panelActions, $user),
        ];
    }

    public function panelResource(string $key, object $user): ?array
    {
        return $this->visiblePanelEntry($this->panelResources[$key] ?? null, $user);
    }

    public function panelAction(string $key, object $user): ?array
    {
        return $this->visiblePanelEntry($this->panelActions[$key] ?? null, $user);
    }

    private function visiblePanelEntries(array $entries, object $user): array
    {
        return array_values(array_filter(array_map(
            fn (?array $entry) => $this->visiblePanelEntry($entry, $user),
            $entries,
        )));
    }

    private function visiblePanelEntry(?array $entry, object $user): ?array
    {
        if ($entry === null || ($entry['authorize'])($user) !== true) {
            return null;
        }

        return $entry['metadata'];
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
            if (! is_string($itemKey) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $itemKey)
                || in_array($itemKey, ['__proto__', 'constructor', 'prototype'], true)
                || isset($normalized[$itemKey]) || ! $this->isPanelLabel($itemLabel)) {
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

    private function isPanelLabel(mixed $label): bool
    {
        return is_string($label) && trim($label) !== '';
    }
}
