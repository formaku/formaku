<?php

namespace Formaku\Cms\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SiteNavigation
{
    /** @return array{version: int, header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>, footerText: string, theme: 'light'|'dark'|'system'} */
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

        return ['version' => (int) ($row->version ?? 0)] + $settings + ['theme' => 'light'];
    }

    /**
     * @param  array{header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>, footerText: string, theme?: 'light'|'dark'|'system'}  $settings
     * @return array{version: int, header: list<array{label: string, url: string}>, footer: list<array{label: string, url: string}>, footerText: string, theme: 'light'|'dark'|'system'}
     */
    public function save(int $version, array $settings): array
    {
        $settings['theme'] ??= $this->current()['theme'];

        $updated = $this->row()->where('version', $version)->increment('version', 1, [
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
        ]);
        abort_unless($updated === 1, 409, 'Site navigation changed. Reload before saving again.');

        return ['version' => $version + 1] + $settings;
    }

    private function row(): Builder
    {
        return DB::table('formaku_site_navigation')->where('id', 1);
    }
}
