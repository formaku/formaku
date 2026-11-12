<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
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
        $html = '';
        foreach (array_slice($blocks, 0, 100) as $b) {
            $type = $b['type'] ?? '';
            $p = $b['props'] ?? [];
            $html .= match ($type) {
                'hero' => '<section class="hero-block"><small>'.e($p['eyebrow'] ?? '').'</small><h1>'.e($p['title'] ?? '').'</h1><p>'.e($p['text'] ?? '').'</p></section>',
                'text' => '<section class="text-block">'.$this->rich($p['document'] ?? ['type' => 'doc', 'content' => []]).'</section>',
                'image' => $this->image($p),
                'cards' => $this->cards($p),
                'faq' => $this->faq($p),
                'container','columns' => $this->layout($type, is_array($p) ? $p : [], $b['children'] ?? [], $depth, $allowExtensions),
                'cta' => '<section class="cta-block"><h2>'.e($p['title'] ?? '').'</h2><a href="'.$this->url($p['url'] ?? '/').'">'.e($p['label'] ?? 'Dalej').'</a></section>',
                default => $allowExtensions && isset(app(Registry::class)->blocks()[$type]) ? app(Registry::class)->blocks()[$type]->render($p) : throw ValidationException::withMessages(['blocks' => 'Unsupported block type'])
            };
        }

        return $html;
    }

    private function layout(string $type, array $props, array $children, int $depth, bool $allowExtensions): string
    {
        $content = $allowExtensions
            ? $this->blocks($children, $depth + 1)
            : $this->renderBlocks($children, $depth + 1, false);
        if ($type === 'columns' && in_array($props['columns'] ?? null, [2, 3], true)) {
            $columns = $props['columns'];

            return '<section class="block-layout block-layout-fixed block-layout-columns-'.$columns.'"><div class="block-layout-grid">'.$content.'</div></section>';
        }

        $class = $type === 'container' && ($props['layout'] ?? null) === 'stack' ? 'block-layout block-layout-stack' : 'block-layout';

        return '<section class="'.$class.'">'.$content.'</section>';
    }

    private function image(array $p): string
    {
        $m = Media::where('visibility', 'public')->find($p['media_id'] ?? 0);
        if (! $m) {
            return '';
        }

        $url = rtrim('/'.trim(config('formaku.prefix'), '/'), '/').'/media/'.$m->id;
        $responsive = '';
        if ($available = app(MediaVariants::class)->available($m)) {
            $sources = array_map(fn (int $width) => "$url/variants/$width {$width}w", $available['variants']);
            $sources[] = $url.' '.$available['original'].'w';
            $responsive = ' srcset="'.e(implode(', ', $sources)).'" sizes="(max-width: 1152px) 100vw, 1152px"';
        }

        return '<figure><img loading="lazy" src="'.e($url).'"'.$responsive.' alt="'.e($m->alt).'"><figcaption>'.e($p['caption'] ?? '').'</figcaption></figure>';
    }

    private function cards(array $p): string
    {
        $c = Collection::find($p['collection_id'] ?? 0);
        if (! $c || ! PublicPages::allowsCollection($c)) {
            return '';
        }
        $html = '<section class="grid md:grid-cols-3 gap-4">';
        $records = PublicPages::publishedRecords()->where('collection_id', $c->id)->with('published:id,data')->limit(20)->get();
        foreach ($records as $record) {
            $title = PublicPages::recordTitle($record, $c->definition);
            $html .= '<a class="p-6 border border-base-300 rounded-xl" href="/p/'.e($record->published_slug).'">'.e($title).'</a>';
        }

        return $html.'</section>';
    }

    private function faq(array $p): string
    {
        $html = '<section>';
        foreach (array_slice($p['items'] ?? [], 0, 30) as $item) {
            $html .= '<details class="p-4 border-b border-base-300"><summary>'.e($item['question'] ?? '').'</summary><p>'.e($item['answer'] ?? '').'</p></details>';
        }

        return $html.'</section>';
    }

    private function url(string $url): string
    {
        return e(preg_match('~^(https?://|/(?!/))~', $url) ? $url : '#');
    }
}
