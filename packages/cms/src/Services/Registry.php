<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Contracts\ActionHandler;
use Formaku\Cms\Contracts\BlockType;
use Formaku\Cms\Contracts\FieldType;
use Formaku\Cms\Contracts\ResourceAdapter;

class Registry
{
    private array $fields = [];

    private array $blocks = [];

    private array $resources = [];

    private array $actions = [];

    public function field(FieldType $type): void
    {
        $this->fields[$type->key()] = $type;
    }

    public function block(BlockType $type): void
    {
        $this->blocks[$type->key()] = $type;
    }

    public function resource(ResourceAdapter $type): void
    {
        $this->resources[$type->key()] = $type;
    }

    public function action(string $key, ActionHandler $type): void
    {
        $this->actions[$key] = $type;
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
}
