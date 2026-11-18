<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Contracts\BlockType;
use Formaku\Cms\Contracts\PanelBlockType;
use Formaku\Cms\Contracts\ValidatedBlockType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Renderer
{
    /** @return array{title: ?string, description: ?string, html: string} */
    public function page(array $definition, array $data, ?string $title, string $slug, bool $tolerateMissingBlocks = false, ?int $recordId = null): array
    {
        $publicTextKeys = SchemaValidator::publicTextKeys($definition);
        $seo = $definition['seo'] ?? [];
        $titleField = isset($data['title']) ? 'title' : 'name';
        $privateTitle = PublicPages::isPrivate($definition, $titleField);

        return [
            'title' => $this->metadataText($seo['title'] ?? null, $publicTextKeys, $data)
                ?? ($privateTitle ? $slug : $title),
            'description' => $this->metadataText($seo['description'] ?? null, $publicTextKeys, $data),
            'html' => $this->renderBlocks($data['blocks'] ?? [], 0, true, false, $tolerateMissingBlocks, $recordId),
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
        return $this->renderBlocks($blocks, $depth, true, true, false, null);
    }

    public function validateBuiltInBlocks(array $blocks): void
    {
        $this->renderBlocks($blocks, 0, false, false, false, null);
    }

    private function renderBlocks(
        array $blocks,
        int $depth,
        bool $allowExtensions,
        bool $validateExtensionProps,
        bool $tolerateMissingBlocks,
        ?int $recordId,
        string $path = 'blocks',
    ): string {
        abort_if($depth > 10, 422, 'Block nesting too deep');
        $registry = app(Registry::class);
        $builtIn = $registry->builtInBlocks();
        $types = $allowExtensions ? $builtIn + $registry->blocks() : $builtIn;

        $html = '';
        foreach (array_slice(array_values($blocks), 0, 100) as $index => $block) {
            if (! is_array($block)) {
                throw ValidationException::withMessages(['blocks' => 'Invalid block data']);
            }

            $key = $block['type'] ?? null;
            $props = $block['props'] ?? [];
            $children = $block['children'] ?? [];
            if (! is_string($key) || ! is_array($props) || ! is_array($children)) {
                throw ValidationException::withMessages(['blocks' => 'Invalid block data']);
            }

            $type = $types[$key] ?? null;
            if ($type === null) {
                if ($tolerateMissingBlocks) {
                    report(new RuntimeException(sprintf(
                        'Missing Formaku block type [%s] on published record [%s].',
                        $key,
                        $recordId ?? 'unknown',
                    )));
                    continue;
                }

                throw ValidationException::withMessages(['blocks' => 'Unsupported block type']);
            }

            if ($validateExtensionProps && ! isset($builtIn[$key])) {
                $this->validateExtensionProps($type, $props, $path.'.'.$index.'.props');
            }

            if ($type instanceof PanelBlockType && $type->acceptsChildren()) {
                $childHtml = $this->renderBlocks(
                    $children,
                    $depth + 1,
                    $allowExtensions,
                    $validateExtensionProps,
                    $tolerateMissingBlocks,
                    $recordId,
                    $path.'.'.$index.'.children',
                );
                $html .= $type->render($props, $childHtml);
            } else {
                $html .= $type->render($props);
            }
        }

        return $html;
    }

    private function validateExtensionProps(BlockType $type, array $props, string $path): void
    {
        if ($type instanceof PanelBlockType) {
            foreach ($type->panelSchema() as $field) {
                if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                    continue;
                }
                $fieldKey = $field['key'];
                if (! array_key_exists($fieldKey, $props) || $props[$fieldKey] === null) {
                    continue;
                }

                $value = $props[$fieldKey];
                $valid = match ($field['input'] ?? null) {
                    'text', 'textarea' => is_string($value),
                    'number' => ! is_bool($value) && ! is_array($value) && is_numeric($value),
                    'boolean' => is_bool($value),
                    'select' => $this->matchesSelectOption($value, $field['options'] ?? []),
                    default => true,
                };
                if (! $valid) {
                    throw ValidationException::withMessages([
                        $path.'.'.$fieldKey => ['The '.$fieldKey.' field has an invalid value.'],
                    ]);
                }
            }
        }

        if ($type instanceof ValidatedBlockType) {
            $validator = Validator::make($props, $type->rules());
            if ($validator->fails()) {
                $messages = [];
                foreach ($validator->errors()->messages() as $fieldKey => $errors) {
                    $messages[$path.'.'.$fieldKey] = $errors;
                }
                throw ValidationException::withMessages($messages);
            }
        }
    }

    private function matchesSelectOption(mixed $value, mixed $options): bool
    {
        if (! is_array($options)) {
            return false;
        }
        $allowed = [];
        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $allowed[] = $option['value'];
            }
        }

        return in_array($value, $allowed, true);
    }
}
