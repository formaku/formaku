<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Contracts\PanelBlockType;
use Illuminate\Validation\ValidationException;

class Renderer
{
    /** @return array{title: ?string, description: ?string, html: string} */
    public function page(array $definition, array $data, ?string $title, string $slug): array
    {
        $publicTextKeys = SchemaValidator::publicTextKeys($definition);
        $seo = $definition['seo'] ?? [];
        $titleField = isset($data['title']) ? 'title' : 'name';
        $privateTitle = PublicPages::isPrivate($definition, $titleField);

        return [
            'title' => $this->metadataText($seo['title'] ?? null, $publicTextKeys, $data)
                ?? ($privateTitle ? $slug : $title),
            'description' => $this->metadataText($seo['description'] ?? null, $publicTextKeys, $data),
            'html' => $this->blocks($data['blocks'] ?? []),
        ];
    }

    private function metadataText(mixed $key, array $publicTextKeys, array $data): ?string
    {
        if (! in_array($key, $publicTextKeys, true) || ! is_string($data[$key] ?? null)) {
            return null;
        }

        $text = trim($data[$key]);

        return $text === '' ? null : $text;
    }

    public function rich(array $node, int $depth = 0): string
    {
        if ($depth > 20) {
            return '';
        }
        $type = $node['type'] ?? '';
        if ($type === 'text') {
            $text = e($node['text'] ?? '');
            foreach ($node['marks'] ?? [] as $mark) {
                $tag = match ($mark['type'] ?? '') {
                    'bold' => 'strong','italic' => 'em','strike' => 's','code' => 'code',default => null
                };
                abort_unless($tag, 422, 'Unsupported text mark');
                $text = "<$tag>$text</$tag>";
            }

            return $text;
        }
        $inner = implode('', array_map(fn ($n) => $this->rich($n, $depth + 1), $node['content'] ?? []));
        $tag = match ($type) {
            'doc' => 'div','paragraph' => 'p','heading' => 'h'.max(1, min(6, (int) ($node['attrs']['level'] ?? 2))),'bulletList' => 'ul','orderedList' => 'ol','listItem' => 'li','blockquote' => 'blockquote','hardBreak' => 'br',default => null
        };
        abort_unless($tag, 422, 'Unsupported rich text node');

        return $tag === 'br' ? '<br>' : "<$tag>$inner</$tag>";
    }

    public function blocks(array $blocks, int $depth = 0): string
    {
        return $this->renderBlocks($blocks, $depth, true);
    }

    public function validateBuiltInBlocks(array $blocks): void
    {
        $this->renderBlocks($blocks, 0, false);
    }

    private function renderBlocks(array $blocks, int $depth, bool $allowExtensions): string
    {
        abort_if($depth > 10, 422, 'Block nesting too deep');
        $registry = app(Registry::class);
        $types = $registry->builtInBlocks();
        if ($allowExtensions) {
            $types += $registry->blocks();
        }

        $html = '';
        foreach (array_slice($blocks, 0, 100) as $block) {
            $key = $block['type'] ?? null;
            $props = $block['props'] ?? [];
            $children = $block['children'] ?? [];
            if (! is_string($key) || ! is_array($props) || ! is_array($children)) {
                throw ValidationException::withMessages(['blocks' => 'Invalid block data']);
            }

            $type = $types[$key] ?? throw ValidationException::withMessages(['blocks' => 'Unsupported block type']);
            if ($type instanceof PanelBlockType && $type->acceptsChildren()) {
                $html .= $type->render($props, $this->renderBlocks($children, $depth + 1, $allowExtensions));
            } else {
                $html .= $type->render($props);
            }
        }

        return $html;
    }
}
