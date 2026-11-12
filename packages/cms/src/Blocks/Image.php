<?php

namespace Formaku\Cms\Blocks;

use Formaku\Cms\Models\Media;
use Formaku\Cms\Services\MediaVariants;

final class Image extends Block
{
    public function key(): string
    {
        return 'image';
    }

    public function label(): string
    {
        return 'Obraz';
    }

    public function panelSchema(): array
    {
        return [
            ['key' => 'media_id', 'label' => 'Obraz', 'input' => 'media'],
            ['key' => 'caption', 'label' => 'Podpis obrazu', 'input' => 'text'],
        ];
    }

    public function render(array $props, string $children = ''): string
    {
        $media = Media::where('visibility', 'public')->find($props['media_id'] ?? 0);
        if (! $media) {
            return '';
        }

        $url = rtrim('/'.trim(config('formaku.prefix'), '/'), '/').'/media/'.$media->id;
        $responsive = '';
        if ($available = app(MediaVariants::class)->available($media)) {
            $sources = array_map(fn (int $width) => "$url/variants/$width {$width}w", $available['variants']);
            $sources[] = $url.' '.$available['original'].'w';
            $responsive = ' srcset="'.e(implode(', ', $sources)).'" sizes="(max-width: 1152px) 100vw, 1152px"';
        }

        return '<figure><img loading="lazy" src="'.e($url).'"'.$responsive.' alt="'.e($media->alt).'"><figcaption>'.e($props['caption'] ?? '').'</figcaption></figure>';
    }
}
