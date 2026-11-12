<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Media;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SiteNavigation
{
    /** @return array{version: int, header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>, footerText: string, theme: 'light'|'dark'|'system', accent: 'formaku'|'blue'|'plum', brandName: string|null, brandLogoId: int|null} */
    public function current(): array
    {
        $row = $this->row()->first();
        $settings = isset($row->settings) ? json_decode($row->settings, true, flags: JSON_THROW_ON_ERROR) : [
            'header' => [
                ['label' => 'Portal klienta', 'url' => '/portal'],
                ['label' => 'Panel', 'url' => '/'.trim(config('formaku.prefix'), '/')],
            ],
            'footer' => [],
            'footerText' => '',
        ];

        return ['version' => (int) ($row->version ?? 0)] + $settings + ['theme' => 'light', 'accent' => 'formaku', 'brandName' => null, 'brandLogoId' => null];
    }

    /**
     * @param  array{header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>, footerText: string, theme?: 'light'|'dark'|'system', accent?: 'formaku'|'blue'|'plum', brandName?: string|null, brandLogoId?: int|null}  $settings
     * @return array{version: int, header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>, footerText: string, theme: 'light'|'dark'|'system', accent: 'formaku'|'blue'|'plum', brandName: string|null, brandLogoId: int|null}
     */
    public function save(int $version, array $settings): array
    {
        $omitted = array_diff_key(array_flip(['theme', 'accent', 'brandName', 'brandLogoId']), $settings);
        if ($omitted !== []) {
            $settings += array_intersect_key($this->current(), $omitted);
        }

        $updated = $this->row()->where('version', $version)->increment('version', 1, [
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
        abort_unless($updated === 1, 409, 'Site navigation changed. Reload before saving again.');

        return ['version' => $version + 1] + $settings;
    }

    public function publicBrandLogoUrl(?int $mediaId): ?string
    {
        if ($mediaId === null || ! Media::whereKey($mediaId)->where('visibility', 'public')
            ->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp'])->exists()) {
            return null;
        }

        $prefix = trim(config('formaku.prefix'), '/');

        return rtrim('/'.$prefix, '/').'/media/'.$mediaId;
    }

    private function row(): Builder
    {
        return DB::table('formaku_site_navigation')->where('id', 1);
    }
}
