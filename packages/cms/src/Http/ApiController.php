<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\CollectionAccess;
use Formaku\Cms\Services\FieldAccess;
use Formaku\Cms\Services\MediaUsage;
use Formaku\Cms\Services\MediaVariants;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Registry;
use Formaku\Cms\Services\Renderer;
use Formaku\Cms\Services\SchemaValidator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiController
{
    public function __construct(private FieldAccess $fieldAccess) {}

    public function bootstrap(Request $r)
    {
        return ['staff' => is_callable(config('formaku.staff')) ? (config('formaku.staff'))() : [], 'abilities' => collect(['work', 'build', 'publish'])->mapWithKeys(fn ($ability) => [$ability => (config('formaku.authorize'))($r->user(), $ability)])->all(), 'user' => ['name' => $r->user()->name, 'id' => $r->user()->id], 'collections' => $this->collections($r), 'counts' => ['records' => $this->visibleCount($r->user()), 'published' => $this->visibleCount($r->user(), true), 'media' => Media::count()], 'providers' => collect(config('formaku.ai'))->map(fn ($p) => ['configured' => ! empty($p['key']) && ! empty($p['model']), 'model' => $p['model']]), 'blocks' => ['hero', 'text', 'cta', 'image', 'cards', 'faq', 'container', 'columns'], 'extensions' => app(Registry::class)->panelExtensions($r->user())];
    }

    public function collections(Request $request)
    {
        return $this->visibleCollections($request->user())
            ->map(fn (Collection $collection) => $this->collectionDocument($collection, $request->user()));
    }

    public function schema(Request $r, SchemaValidator $v)
    {
        $id = $r->input('id');

        return DB::transaction(function () use ($r, $v, $id) {
            $c = $id ? Collection::lockForUpdate()->findOrFail($id) : null;
            if ($c) {
                abort_unless((int) $r->input('version') === $c->version, 409);
            }$data = $v->validate($r->all(), $c?->definition);
            if ($c) {
                abort_unless($data['key'] === $c->key && $data['kind'] === $c->kind, 422);
            }$data['version'] = $c ? $c->version + 1 : 1;
            $c ? $c->update($data) : $c = Collection::create($data);
            DB::table('formaku_schema_versions')->insert(['collection_id' => $c->id, 'version' => $c->version, 'definition' => json_encode($c->definition), 'created_at' => now(), 'updated_at' => now()]);

            return $c;
        });
    }

    public function records(Request $r, Collection $collection): LengthAwarePaginator
    {
        $user = $r->user();
        $hidden = $this->hiddenFields($collection, $user);
        $query = app(CollectionAccess::class)->query($collection, $user)->with('draft:id,record_id,data');
        if ($r->filled('state')) {
            $query->where('state', $r->string('state'));
        }
        if ($r->filled('search')) {
            if ($this->titleIsHidden($hidden)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('published_title', 'like', '%'.$r->string('search').'%');
            }
        }
        if ($this->titleIsHidden($hidden)) {
            $query->with('published:id,record_id,data');
        }

        return $query->orderByDesc('id')->paginate(25)->through(
            fn (Record $record) => $this->document($record, $collection, $user, $hidden),
        );
    }

    public function show(Request $request, Record $record): array
    {
        $collection = Collection::findOrFail($record->collection_id);
        $user = $request->user();
        $history = Revision::where('record_id', $record->id)->latest('id')->get();
        if ($this->fieldAccess->hasRules($collection)) {
            $history = $history->map(fn (Revision $revision) => array_replace($revision->toArray(), [
                'data' => $this->fieldAccess->readableData($collection, $revision->data, $user),
            ]));
        }

        return $this->document($record, $collection, $user, $this->hiddenFields($collection, $user)) + ['history' => $history];
    }

    public function relationOptions(Request $request, Collection $collection): array
    {
        $filters = $request->validate([
            'selected' => 'sometimes|array|max:100',
            'selected.*' => 'required|integer|min:1',
        ]);
        $labelFields = collect($collection->definition['fields'])
            ->filter(fn (array $field) => ! ($field['private'] ?? false)
                && $this->fieldAccess->canRead($collection, $field, $request->user()))
            ->pluck('key')->all();
        $labelKeys = array_intersect(['title', 'name'], $labelFields);
        $query = app(CollectionAccess::class)->query($collection, $request->user())
            ->select('id', 'draft_id')->with('draft:id,data')->orderByDesc('id');
        $option = static function (Record $record) use ($labelKeys): array {
            $data = $record->draft?->data ?? [];
            foreach ($labelKeys as $key) {
                if (is_string($data[$key] ?? null) && trim($data[$key]) !== '') {
                    return ['id' => $record->id, 'label' => $data[$key]];
                }
            }

            return ['id' => $record->id, 'label' => '#'.$record->id];
        };
        $selected = empty($filters['selected'])
            ? []
            : (clone $query)->whereKey($filters['selected'])->get()->map($option)->all();

        return $query->paginate(25)->appends($filters)->through($option)->toArray()
            + ['selected' => $selected];
    }

    public function save(Request $r, Collection $collection, RecordService $s): array
    {
        $d = $r->validate(['id' => 'nullable|integer', 'version' => 'required|integer|min:0', 'data' => 'required|array']);
        $record = isset($d['id']) ? Record::where('collection_id', $collection->id)->findOrFail($d['id']) : null;
        $user = $r->user();
        $saved = $s->save($collection, $record, $d['data'], $d['version'], $user->id, actorUser: $user);
        $collection = Collection::findOrFail($saved->collection_id);

        return $this->document($saved, $collection, $user, $this->hiddenFields($collection, $user));
    }

    public function publish(Request $r, Record $record, RecordService $s): array
    {
        $d = $r->validate(['version' => 'required|integer']);

        try {
            $published = $s->publish($record, $d['version'], $r->user()->id);
        } catch (ValidationException | HttpExceptionInterface $error) {
            if ($error instanceof ValidationException || $error->getStatusCode() === 422) {
                $collection = Collection::findOrFail($record->collection_id);
                abort_if($this->hiddenFields($collection, $r->user()) !== [], 422, 'The draft could not be published.');
            }
            throw $error;
        }

        return $this->mutationDocument($published, $r->user());
    }

    public function transition(Request $r, Record $record, RecordService $s): array
    {
        $d = $r->validate(['version' => 'required|integer', 'state' => 'required|string', 'assignee_id' => 'nullable|integer']);
        $updated = $s->transition($record, $d['version'], $r->user()->id, $d['state'], $d['assignee_id'] ?? null);

        return $this->mutationDocument($updated, $r->user());
    }

    public function schedule(Request $r, Record $record): array
    {
        $d = $r->validate(['version' => 'required|integer', 'publish_at' => 'required|date|after:now']);

        $updated = DB::transaction(function () use ($record, $d) {
            $record = Record::lockForUpdate()->findOrFail($record->id);
            abort_unless($record->version === $d['version'], 409);
            abort_unless(Collection::findOrFail($record->collection_id)->kind === 'content', 422);
            $record->update(['publish_at' => $d['publish_at'], 'version' => $record->version + 1]);

            return $record;
        });

        return $this->mutationDocument($updated, $r->user());
    }

    public function restore(Request $r, Record $record, Revision $revision, RecordService $s): array
    {
        abort_unless($revision->record_id === $record->id, 404);
        $collection = Collection::findOrFail($record->collection_id);
        $version = (int) $r->validate(['version' => 'required|integer'])['version'];
        $user = $r->user();
        $restored = $s->save($collection, $record, $revision->data, $version, $user->id, actorUser: $user, restore: true);

        return $this->mutationDocument($restored, $user);
    }

    public function preview(Request $request, Record $record, Renderer $renderer): \Illuminate\Http\Response
    {
        $collection = Collection::findOrFail($record->collection_id);
        $draft = Revision::where('record_id', $record->id)->findOrFail($record->draft_id)->data;
        $data = $this->fieldAccess->readableData($collection, $draft, $request->user());
        $slug = is_string($data['slug'] ?? null) ? $data['slug'] : 'page-'.$record->id;
        $title = $data['title'] ?? $data['name'] ?? $slug;

        return response()->view('formaku::page', $renderer->page($collection->definition, $data, is_string($title) ? $title : $slug, $slug))
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function media(Request $request)
    {
        $filters = $request->validate(['kind' => 'sometimes|in:public-image']);
        $query = Media::latest()->orderByDesc('id');
        if (isset($filters['kind'])) {
            $query->where('visibility', 'public')->where('mime', 'like', 'image/%');
        }

        return $query->paginate(30)->appends($filters);
    }

    public function mediaUsage(Request $request, string $mediaId, MediaUsage $usage): LengthAwarePaginator
    {
        $id = filter_var($mediaId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $media = Media::findOrFail($id);

        return $usage->records($media->id, $request->user());
    }

    public function upload(Request $r, MediaVariants $variants): Media
    {
        $d = $r->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf', 'visibility' => 'required|in:public,private', 'alt' => 'nullable|string|max:250']);
        $f = $r->file('file');
        $disk = $d['visibility'] === 'public' ? 'public' : 'local';
        $path = $f->store('formaku', $disk);

        $media = Media::create(['owner_id' => $r->user()->id, 'disk' => $disk, 'path' => $path, 'name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'visibility' => $d['visibility'], 'alt' => $d['alt'] ?? '', 'size' => $f->getSize()]);
        try {
            $variants->generate($media);
        } catch (Throwable $error) {
            report($error);
        }

        return $media;
    }

    public function updateMedia(Request $request, string $mediaId): Media
    {
        $id = filter_var($mediaId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $media = Media::findOrFail($id);
        if ($media->visibility === 'public') {
            $authorize = config('formaku.authorize');
            abort_unless(is_callable($authorize) && $authorize($request->user(), 'publish'), 403);
        }

        $metadata = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:~[/\\\\[:cntrl:]]~'],
            'alt' => 'present|nullable|string|max:250',
        ]);

        $media->update([
            'name' => $metadata['name'],
            'alt' => $metadata['alt'] ?? '',
        ]);

        return $media;
    }

    public function export(Request $r)
    {
        return ['format' => 'formaku.project', 'version' => 1, 'collections' => $this->collections($r)->map(fn ($c) => $c->only('key', 'name', 'kind', 'definition'))->values()];
    }

    public function import(Request $r, SchemaValidator $v)
    {
        $d = $r->validate(['format' => 'required|in:formaku.project', 'version' => 'required|in:1', 'collections' => 'required|array|max:50']);
        $collections = [];
        foreach ($d['collections'] as $c) {
            $validated = $v->validate($c);
            abort_if(Collection::where('key', $validated['key'])->exists(), 409, 'Collection already exists');
            $collections[] = $validated;
        }

        return DB::transaction(function () use ($collections) {
            foreach ($collections as $c) {
                $c = Collection::create($c);
                DB::table('formaku_schema_versions')->insert(['collection_id' => $c->id, 'version' => 1, 'definition' => json_encode($c->definition), 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['imported' => count($collections)];
        });
    }

    private function visibleCollections(object $user)
    {
        $policy = app(CollectionAccess::class);

        return Collection::all()->filter(fn ($collection) => $policy->rule($collection, $user)['scope'] !== 'none')->values();
    }

    private function visibleCount(object $user, bool $published = false): int
    {
        $policy = app(CollectionAccess::class);

        return $this->visibleCollections($user)->sum(function ($collection) use ($policy, $user, $published) {
            $query = $policy->query($collection, $user);

            return ($published ? $query->whereNotNull('published_id') : $query)->count();
        });
    }

    private function collectionDocument(Collection $collection, object $user): array
    {
        $document = $collection->toArray();
        if (! $this->fieldAccess->hasRules($collection) || (config('formaku.authorize'))($user, 'build')) {
            return $document;
        }

        $fields = [];
        foreach ($collection->definition['fields'] as $field) {
            if (! $this->fieldAccess->canRead($collection, $field, $user)) {
                continue;
            }
            if (! $this->fieldAccess->canWrite($collection, $field, $user)) {
                $field['writable'] = false;
            }
            unset($field['access']);
            $fields[] = $field;
        }
        $document['definition']['fields'] = $fields;

        return $document;
    }

    /** @return list<string> */
    private function hiddenFields(Collection $collection, object $user): array
    {
        if (! $this->fieldAccess->hasRules($collection)) {
            return [];
        }

        return collect($collection->definition['fields'])
            ->reject(fn (array $field) => $this->fieldAccess->canRead($collection, $field, $user))
            ->pluck('key')->all();
    }

    private function titleIsHidden(array $hidden): bool
    {
        return array_intersect(['title', 'name'], $hidden) !== [];
    }

    private function mutationDocument(Record $record, object $user): array
    {
        $collection = Collection::findOrFail($record->collection_id);

        return $this->recordAttributes($record, $collection, $user, $this->hiddenFields($collection, $user));
    }

    private function recordAttributes(Record $record, Collection $collection, object $user, array $hidden): array
    {
        $document = $record->attributesToArray();
        if ($hidden !== []) {
            unset($document['search_text']);
        }
        if ($this->titleIsHidden($hidden) && $record->published_id !== null) {
            $record->loadMissing('published:id,record_id,data');
            $data = $this->fieldAccess->readableData($collection, $this->revisionData($record, $record->published), $user);
            $fallback = $record->published_slug ?? '#'.$record->id;
            $title = $data['title'] ?? $data['name'] ?? $fallback;
            $document['published_title'] = is_string($title) && trim($title) !== '' ? $title : $fallback;
        }

        return $document;
    }

    private function document(Record $record, Collection $collection, object $user, array $hidden): array
    {
        $record->loadMissing('draft:id,record_id,data');
        $data = $this->revisionData($record, $record->draft);

        return $this->recordAttributes($record, $collection, $user, $hidden)
            + ['data' => $this->fieldAccess->readableData($collection, $data, $user)];
    }

    private function revisionData(Record $record, ?Revision $revision): array
    {
        return $revision && (int) $revision->record_id === (int) $record->id ? $revision->data : [];
    }
}
