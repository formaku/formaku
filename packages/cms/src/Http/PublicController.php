<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\FieldAccess;
use Formaku\Cms\Services\MediaVariants;
use Formaku\Cms\Services\PublicPages;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Renderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicController
{
    public function home()
    {
        $collections = PublicPages::collections()->keyBy('id');
        $restrictedTitles = $collections->filter(fn (Collection $collection) => PublicPages::isPrivate($collection->definition, 'title') || PublicPages::isPrivate($collection->definition, 'name')
        )->modelKeys();
        $records = PublicPages::publishedRecords()
            ->whereIntegerInRaw('collection_id', $collections->modelKeys())
            ->with('published:id,data');
        if ($restrictedTitles === []) {
            $records->orderBy('published_title');
        } else {
            $ids = implode(',', array_map('intval', $restrictedTitles));
            $records->orderByRaw("CASE WHEN collection_id IN ({$ids}) THEN published_slug ELSE published_title END");
        }

        $items = $records->orderBy('id')->paginate(20)->through(fn (Record $record) => (object) [
            'id' => $record->id,
            'collection_id' => $record->collection_id,
            'published_slug' => $record->published_slug,
            'published_title' => PublicPages::recordTitle($record, $collections->get($record->collection_id)->definition),
        ]);

        return view('formaku::catalog', ['items' => $items]);
    }

    public function page(Request $request, string $slug, Renderer $renderer)
    {
        $r = Record::where('published_slug', $slug)->whereNotNull('published_id')->first();
        $redirect = $r === null;
        $r ??= Record::whereIn('id', DB::table('formaku_slug_redirects')->where('slug', $slug)->select('record_id'))
            ->whereNotNull('published_id')->whereNotNull('published_slug')->firstOrFail();
        $c = Collection::findOrFail($r->collection_id);
        abort_unless(PublicPages::allowsCollection($c), 404);
        $revision = Revision::where('record_id', $r->id)->findOrFail($r->published_id);

        if ($redirect && $r->published_slug !== $slug) {
            $base = rtrim('/'.ltrim($request->getBaseUrl(), '/'), '/');
            $path = route('formaku.public.page', ['slug' => $r->published_slug], false);

            return new RedirectResponse($base.$path, 301, ['Cache-Control' => 'no-store']);
        }

        $data = PublicPages::data($c->definition, $revision->data);
        $page = $renderer->page($c->definition, $data, PublicPages::title($data, $slug), $slug);

        $translations = $this->publishedTranslations($r);
        $page['pageLanguage'] = $translations->firstWhere('record_id', $r->id)?->locale;

        if ($page['pageLanguage'] !== null && $translations->count() > 1) {
            $baseUrl = rtrim((string) config('app.url'), '/');
            $basePath = rtrim($request->getBaseUrl(), '/');
            $page['pageAlternates'] = $translations->map(function ($translation) use ($r, $baseUrl, $basePath) {
                $path = route('formaku.public.page', ['slug' => $translation->published_slug], false);

                return [
                    'locale' => $translation->locale,
                    'url' => $baseUrl.$path,
                    'path' => $basePath.$path,
                    'current' => (int) $translation->record_id === $r->id,
                ];
            });
        }

        return view('formaku::page', $page);
    }

    private function publishedTranslations(Record $record): \Illuminate\Support\Collection
    {
        return DB::table('formaku_record_translations as current_translation')
            ->join('formaku_record_translations as alternate', function ($join) {
                $join->on('alternate.collection_id', '=', 'current_translation.collection_id')
                    ->on('alternate.group_key', '=', 'current_translation.group_key');
            })
            ->joinSub(
                PublicPages::publishedRecords()->where('collection_id', $record->collection_id)->select('id', 'collection_id', 'published_slug'),
                'records',
                function ($join) {
                    $join->on('records.id', '=', 'alternate.record_id')
                        ->on('records.collection_id', '=', 'alternate.collection_id');
                },
            )
            ->where('current_translation.record_id', $record->id)
            ->where('current_translation.collection_id', $record->collection_id)
            ->orderBy('alternate.locale')
            ->get(['alternate.record_id', 'alternate.locale', 'records.published_slug']);
    }

    public function portal(Request $r)
    {
        $c = Collection::where('key', 'requests')->firstOrFail();
        $records = Record::where('collection_id', $c->id)->where('owner_id', $r->user()->id)->latest()->paginate(20);

        return view('formaku::portal', ['records' => $records]);
    }

    public function submit(Request $r, RecordService $s)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'description' => 'required|string|max:5000']);
        $c = Collection::where('key', 'requests')->firstOrFail();
        $s->save($c, null, $d, 0, $r->user()->id, actorUser: $r->user());

        return redirect('/portal')->with('status', 'Zgłoszenie zapisane.');
    }

    public function request(Request $r, Record $record, FieldAccess $fields)
    {
        abort_unless($record->owner_id === $r->user()->id, 404);
        $c = Collection::findOrFail($record->collection_id);
        $draft = Revision::where('record_id', $record->id)->findOrFail($record->draft_id);
        $data = $fields->readableData($c, $draft->data, $r->user());
        $allowed = collect($c->definition['fields'])->reject(fn ($f) => $f['private'] ?? false)->pluck('key')->all();
        $result = ['id' => $record->id, 'state' => $record->state, 'data' => array_intersect_key($data, array_flip($allowed))];

        return $r->expectsJson() ? $result : view('formaku::request', ['record' => $result]);
    }

    public function file(Request $r, Media $media)
    {
        if ($media->visibility === 'private') {
            abort_unless($r->user() && ($r->user()->id === $media->owner_id || (is_callable(config('formaku.authorize')) && (config('formaku.authorize'))($r->user(), 'work'))), 404);
        }

        return Storage::disk($media->disk)->response($media->path, $media->name, ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function mediaVariant(Media $media, int $width, MediaVariants $variants): StreamedResponse
    {
        $path = $variants->availablePath($media, $width);
        abort_if($path === null, 404);

        return Storage::disk($media->disk)->response($path, null, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
