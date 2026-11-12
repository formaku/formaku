<?php

namespace Formaku\Cms\Blocks;

final class Container extends Block
{
    public function key(): string
    {
        return 'container';
    }

    public function label(): string
    {
        return 'Kontener';
    }

    public function panelSchema(): array
    {
        return [['key' => 'layout', 'label' => 'Układ kontenera', 'input' => 'select', 'options' => [
            ['value' => 'stack', 'label' => 'Pionowo'],
        ]]];
    }

    public function acceptsChildren(): bool
    {
        return true;
    }

    public function render(array $props, string $children = ''): string
    {
        $class = ($props['layout'] ?? null) === 'stack' ? 'block-layout block-layout-stack' : 'block-layout';

        return '<section class="'.$class.'">'.$children.'</section>';
    }
}
