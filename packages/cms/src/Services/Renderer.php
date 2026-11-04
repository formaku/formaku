<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\Record;
use Illuminate\Validation\ValidationException;

class Renderer
{
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
                'container','columns' => '<section class="block-layout">'.$this->blocks($b['children'] ?? [], $depth + 1).'</section>',
                'cta' => '<section class="cta-block"><h2>'.e($p['title'] ?? '').'</h2><a href="'.$this->url($p['url'] ?? '/').'">'.e($p['label'] ?? 'Dalej').'</a></section>',
                default => isset(app(Registry::class)->blocks()[$type]) ? app(Registry::class)->blocks()[$type]->render($p) : throw ValidationException::withMessages(['blocks' => 'Unsupported block type'])
            };
        }

        return $html;
    }

    private function image(array $p): string
    {
        $m = Media::where('visibility', 'public')->find($p['media_id'] ?? 0);
        if (! $m) {
            return '';
        }

        return '<figure><img loading="lazy" src="/'.e(trim(config('formaku.prefix'), '/')).'/media/'.$m->id.'" alt="'.e($m->alt).'"><figcaption>'.e($p['caption'] ?? '').'</figcaption></figure>';
    }

    private function cards(array $p): string
    {
        $c = Collection::find($p['collection_id'] ?? 0);
        if (! $c || $c->kind !== 'content' || ! ($c->definition['public'] ?? false)) {
            return '';
        }
        $html = '<section class="grid md:grid-cols-3 gap-4">';
        foreach (Record::where('collection_id', $c->id)->whereNotNull('published_id')->limit(20)->get() as $record) {
            $html .= '<a class="p-6 border border-base-300 rounded-xl" href="/p/'.e($record->published_slug).'">'.e($record->published_title).'</a>';
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
