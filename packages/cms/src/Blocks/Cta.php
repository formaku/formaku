<?php

namespace Formaku\Cms\Blocks;

final class Cta extends Block
{
    public function key(): string
    {
        return 'cta';
    }

    public function label(): string
    {
        return 'Wezwanie do działania';
    }

    public function panelSchema(): array
    {
        return [
            ['key' => 'title', 'label' => 'Tytuł', 'input' => 'text'],
            ['key' => 'label', 'label' => 'Etykieta przycisku', 'input' => 'text'],
            ['key' => 'url', 'label' => 'Adres odnośnika', 'input' => 'text'],
        ];
    }

    public function render(array $props, string $children = ''): string
    {
        $url = $props['url'] ?? '/';

        return '<section class="cta-block"><h2>'.e($props['title'] ?? '').'</h2><a href="'.e(preg_match('~^(https?://|/(?!/))~', $url) ? $url : '#').'">'.e($props['label'] ?? 'Dalej').'</a></section>';
    }
}
