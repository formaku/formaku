<?php

namespace Formaku\Cms\Http;

use Closure;
use Formaku\Cms\Services\SiteNavigation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SiteNavigationController
{
    public function show(SiteNavigation $navigation): array
    {
        return $navigation->current();
    }

    public function update(Request $request, SiteNavigation $navigation): array
    {
        $rules = [
            'version' => 'required|integer|min:0',
            'footerText' => 'present|nullable|string|max:500',
            'theme' => 'sometimes|required|string|in:light,dark',
            'brand' => 'sometimes|required|array:preset,accent,font,logoId',
            'brand.preset' => 'required_with:brand|in:default,studio,journal,store',
            'brand.accent' => ['nullable', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'brand.font' => 'required_with:brand|in:default,sans,serif',
            'brand.logoId' => ['nullable', 'integer', Rule::exists('formaku_media', 'id')
                ->where('visibility', 'public')->whereIn('mime', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])],
        ];
        foreach (['header', 'footer'] as $area) {
            $rules[$area] = 'present|array|list|max:12';
            $rules["$area.*"] = 'required|array:label,url';
            $rules["$area.*.label"] = 'required|string|max:100';
            $rules["$area.*.url"] = ['bail', 'required', 'string', 'max:2048', function (string $attribute, string $url, Closure $fail): void {
                if (! $this->isAllowedUrl($url)) {
                    $fail('Use a path starting with / or a full HTTP or HTTPS URL.');
                }
            }];
        }

        $data = $request->validate($rules);

        $version = (int) $data['version'];
        unset($data['version']);
        $data['footerText'] ??= '';
        if (isset($data['brand'])) {
            $data['brand']['accent'] ??= null;
            $data['brand']['logoId'] = isset($data['brand']['logoId']) ? (int) $data['brand']['logoId'] : null;
        }

        return $navigation->save($version, $data);
    }

    private function isAllowedUrl(string $url): bool
    {
        if (str_contains($url, '\\') || preg_match('/[\p{Cc}\p{Cf}\p{Z}]/u', $url) !== 0) {
            return false;
        }
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//');
        }

        $parts = parse_url($url);

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
