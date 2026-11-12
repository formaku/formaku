<?php

namespace Formaku\Cms\Blocks;

final class Faq extends Block
{
    public function key(): string
    {
        return 'faq';
    }

    public function label(): string
    {
        return 'Pytania i odpowiedzi';
    }

    public function panelSchema(): array
    {
        return [['key' => 'items', 'label' => 'Pytania', 'input' => 'list', 'fields' => [
            ['key' => 'question', 'label' => 'Pytanie', 'input' => 'text'],
            ['key' => 'answer', 'label' => 'Odpowiedź', 'input' => 'textarea'],
        ]]];
    }

    public function render(array $props, string $children = ''): string
    {
        $html = '<section>';
        foreach (array_slice($props['items'] ?? [], 0, 30) as $item) {
            $html .= '<details class="p-4 border-b border-base-300"><summary>'.e($item['question'] ?? '').'</summary><p>'.e($item['answer'] ?? '').'</p></details>';
        }

        return $html.'</section>';
    }
}
