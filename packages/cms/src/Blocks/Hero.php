<?php

namespace Formaku\Cms\Blocks;

final class Hero extends Block
{
    public function key(): string
    {
        return 'hero';
    }

    public function label(): string
    {
        return 'Sekcja główna';
    }

    public function panelSchema(): array
    {
        return [
            ['key' => 'eyebrow', 'label' => 'Nadtytuł', 'input' => 'text'],
            ['key' => 'title', 'label' => 'Tytuł', 'input' => 'text'],
            ['key' => 'text', 'label' => 'Tekst', 'input' => 'textarea'],
        ];
    }

    public function render(array $props, string $children = ''): string
    {
        return '<section class="hero-block"><small>'.e($props['eyebrow'] ?? '').'</small><h1>'.e($props['title'] ?? '').'</h1><p>'.e($props['text'] ?? '').'</p></section>';
    }
}
