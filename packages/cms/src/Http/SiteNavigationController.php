<?php

namespace Formaku\Cms\Http;

use Closure;
use Formaku\Cms\Services\SiteNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SiteNavigationController
{
    public function show(SiteNavigation $navigation): array
    {
        return $navigation->current();
    }

    public function update(Request $request, SiteNavigation $navigation): array
    {
        $brandName = $request->input('brandName');
        if (is_string($brandName)) {
            $request->merge(['brandName' => Str::trim($brandName)]);
        }

        $rules = [
            'version' => 'required|integer|min:0',
            'footerText' => 'present|nullable|string|max:500',
            'theme' => 'sometimes|required|string|in:light,dark,system',
            'accent' => 'sometimes|required|string|in:formaku,blue,plum',
            'brandName' => 'sometimes|nullable|string|max:80',
            'brandLogoId' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'min:1', function (string $attribute, int $mediaId, Closure $fail) use ($navigation): void {
                if ($navigation->publicBrandLogoUrl($mediaId) === null) {
                    $fail('Choose a public JPEG, PNG, or WebP image.');
                }
            }],
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
        if (($data['brandName'] ?? null) === '') {
            $data['brandName'] = null;
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
