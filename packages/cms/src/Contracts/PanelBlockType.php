<?php

namespace Formaku\Cms\Contracts;

interface PanelBlockType extends BlockType
{
    public function label(): string;

    public function panelSchema(): array;

    public function acceptsChildren(): bool;

    public function render(array $props, string $children = ''): string;
}
