<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SitemapController
{
    // Allows for XML escaping even when each URL is close to the protocol's length limit.
    private const PAGE_SIZE = 4000;

    public function __invoke(?string $page = null): StreamedResponse
    {
        $baseUrl = rtrim((string) config('app.url'), '/');
        $parts = parse_url($baseUrl);
        abort_unless(
            isset($parts['host']) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
                && ! isset($parts['query']) && ! isset($parts['fragment']) && ! isset($parts['user'])
                && strlen($baseUrl) + strlen('/p/') + 255 < 2048,
            503,
            'Configure APP_URL as the public base URL.',
        );

        $publicCollections = Collection::query()->where('kind', 'content')
            ->get(['id', 'definition'])
            ->filter(fn (Collection $collection) => $collection->definition['public'] ?? false)
            ->modelKeys();
        $records = Record::query()
            ->whereIntegerInRaw('collection_id', $publicCollections)
            ->whereNotNull('published_id')
            ->whereNotNull('published_slug')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('formaku_revisions')
                ->whereColumn('formaku_revisions.id', 'formaku_records.published_id')
                ->whereColumn('formaku_revisions.record_id', 'formaku_records.id'));

        $pageCount = intdiv(max($records->count() - 1, 0), self::PAGE_SIZE) + 1;
        $pageNumber = $page === null ? 1 : (int) $page;
        abort_unless($pageNumber >= 1 && $pageNumber <= $pageCount, 404);

        if ($page === null && $pageCount > 1) {
            return $this->xml('sitemapindex', 'sitemap', $this->sitemapUrls($baseUrl, $pageCount));
        }

        return $this->xml('urlset', 'url', $this->pageUrls($baseUrl, $records, $pageNumber));
    }

    private function sitemapUrls(string $baseUrl, int $pageCount): iterable
    {
        for ($page = 1; $page <= $pageCount; $page++) {
            yield $baseUrl.route('formaku.public.sitemap', ['page' => $page], false);
        }
    }

    /** @param Builder<Record> $records */
    private function pageUrls(string $baseUrl, Builder $records, int $page): iterable
    {
        if ($page === 1) {
            yield $baseUrl.route('formaku.public.home', [], false);
        }

        foreach ($records->orderBy('id')->forPage($page, self::PAGE_SIZE)->select('published_slug')->cursor() as $record) {
            yield $baseUrl.route('formaku.public.page', ['slug' => $record->published_slug], false);
        }
    }

    private function xml(string $root, string $entry, iterable $urls): StreamedResponse
    {
        return response()->stream(function () use ($root, $entry, $urls): void {
            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            echo '<'.$root." xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
            foreach ($urls as $url) {
                echo '<'.$entry.'><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .'</loc></'.$entry.">\n";
            }
            echo '</'.$root.">\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
