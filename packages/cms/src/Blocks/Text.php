<?php

namespace Formaku\Cms\Blocks;

use Formaku\Cms\Services\Renderer;

final class Text extends Block
{
    public function key(): string
    {
        return 'text';
    }

    public function label(): string
    {
        return 'Tekst';
    }

    public function panelSchema(): array
    {
        return [['key' => 'document', 'label' => 'Treść', 'input' => 'richtext']];
    }

    public function render(array $props, string $children = ''): string
    {
        return '<section class="text-block">'.app(Renderer::class)->rich($props['document'] ?? ['type' => 'doc', 'content' => []]).'</section>';
    }
}
