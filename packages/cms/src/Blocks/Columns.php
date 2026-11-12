<?php

namespace Formaku\Cms\Blocks;

final class Columns extends Block
{
    public function key(): string
    {
        return 'columns';
    }

    public function label(): string
    {
        return 'Kolumny';
    }

    public function panelSchema(): array
    {
        return [['key' => 'columns', 'label' => 'Liczba kolumn', 'input' => 'select', 'options' => [
            ['value' => 2, 'label' => 'Dwie kolumny'],
            ['value' => 3, 'label' => 'Trzy kolumny'],
        ]]];
    }

    public function acceptsChildren(): bool
    {
        return true;
    }

    public function render(array $props, string $children = ''): string
    {
        if (in_array($props['columns'] ?? null, [2, 3], true)) {
            return '<section class="block-layout block-layout-fixed block-layout-columns-'.$props['columns'].'"><div class="block-layout-grid">'.$children.'</div></section>';
        }

        return '<section class="block-layout">'.$children.'</section>';
    }
}
