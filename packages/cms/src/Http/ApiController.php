<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Media;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\CollectionAccess;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Renderer;
use Formaku\Cms\Services\SchemaValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiController
{
    public function bootstrap(Request $r)
    {
        return ['staff' => is_callable(config('formaku.staff')) ? (config('formaku.staff'))() : [], 'abilities' => collect(['work', 'build', 'publish'])->mapWithKeys(fn ($ability) => [$ability => (config('formaku.authorize'))($r->user(), $ability)])->all(), 'user' => ['name' => $r->user()->name, 'id' => $r->user()->id], 'collections' => $this->visibleCollections($r->user()), 'counts' => ['records' => $this->visibleCount($r->user()), 'published' => $this->visibleCount($r->user(), true), 'media' => Media::count()], 'providers' => collect(config('formaku.ai'))->map(fn ($p) => ['configured' => ! empty($p['key']) && ! empty($p['model']), 'model' => $p['model']]), 'blocks' => ['hero', 'text', 'cta', 'image', 'cards', 'faq', 'container', 'columns']];
    }

    public function collections(Request $request)
    {
        return $this->visibleCollections($request->user());
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

    public function records(Request $r, Collection $collection)
    {
        $q = app(CollectionAccess::class)->query($collection, $r->user());
        if ($r->filled('state')) {
            $q->where('state', $r->string('state'));
        }if ($r->filled('search')) {
            $q->where('published_title', 'like', '%'.$r->string('search').'%');
        }

        return $q->orderByDesc('id')->paginate(25)->through(fn ($x) => $this->document($x));
    }

    public function show(Record $record)
    {
        return $this->document($record) + ['history' => Revision::where('record_id', $record->id)->latest('id')->get()];
    }

    public function save(Request $r, Collection $collection, RecordService $s)
    {
        $d = $r->validate(['id' => 'nullable|integer', 'version' => 'required|integer|min:0', 'data' => 'required|array']);
        $record = isset($d['id']) ? Record::where('collection_id', $collection->id)->findOrFail($d['id']) : null;

        return $this->document($s->save($collection, $record, $d['data'], $d['version'], $r->user()->id));
    }

    public function publish(Request $r, Record $record, RecordService $s)
    {
        $d = $r->validate(['version' => 'required|integer']);

        return $s->publish($record, $d['version'], $r->user()->id);
    }

    public function transition(Request $r, Record $record, RecordService $s)
    {
        $d = $r->validate(['version' => 'required|integer', 'state' => 'required|string', 'assignee_id' => 'nullable|integer']);

        return $s->transition($record, $d['version'], $d['state'], $r->user()->id, $d['assignee_id'] ?? null);
    }

    public function schedule(Request $r, Record $record)
    {
        $d = $r->validate(['version' => 'required|integer', 'publish_at' => 'required|date|after:now']);

        return DB::transaction(function () use ($record, $d) {
            $record = Record::lockForUpdate()->findOrFail($record->id);
            abort_unless($record->version === $d['version'], 409);
            abort_unless(Collection::findOrFail($record->collection_id)->kind === 'content', 422);
            $record->update(['publish_at' => $d['publish_at'], 'version' => $record->version + 1]);

            return $record;
        });
    }

    public function restore(Request $r, Record $record, Revision $revision, RecordService $s)
    {
        abort_unless($revision->record_id === $record->id, 404);

        return $s->save(Collection::findOrFail($record->collection_id), $record, $revision->data, (int) $r->validate(['version' => 'required|integer'])['version'], $r->user()->id);
    }

    public function preview(Record $record, Renderer $renderer)
    {
        $definition = Collection::findOrFail($record->collection_id)->definition;
        $draft = Revision::where('record_id', $record->id)->findOrFail($record->draft_id)->data;
        $slug = is_string($draft['slug'] ?? null) ? $draft['slug'] : 'page-'.$record->id;
        $title = $draft['title'] ?? $draft['name'] ?? $slug;

        return response()->view('formaku::page', $renderer->page($definition, $draft, is_string($title) ? $title : $slug, $slug))
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function media()
    {
        return Media::latest()->orderByDesc('id')->paginate(30);
    }

    public function upload(Request $r)
    {
        $d = $r->validate(['file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf', 'visibility' => 'required|in:public,private', 'alt' => 'nullable|string|max:250']);
        $f = $r->file('file');
        $disk = $d['visibility'] === 'public' ? 'public' : 'local';
        $path = $f->store('formaku', $disk);

        return Media::create(['owner_id' => $r->user()->id, 'disk' => $disk, 'path' => $path, 'name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'visibility' => $d['visibility'], 'alt' => $d['alt'] ?? '', 'size' => $f->getSize()]);
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
        return ['format' => 'formaku.project', 'version' => 1, 'collections' => $this->visibleCollections($r->user())->map(fn ($c) => $c->only('key', 'name', 'kind', 'definition'))->values()];
    }

    public function import(Request $r, SchemaValidator $v)
    {
        $d = $r->validate(['format' => 'required|in:formaku.project', 'version' => 'required|in:1', 'collections' => 'required|array|max:50']);
        foreach ($d['collections'] as $c) {
            $v->validate($c);
            abort_if(Collection::where('key', $c['key'])->exists(), 409, 'Collection already exists');
        }

        return DB::transaction(function () use ($d) {
            foreach ($d['collections'] as $c) {
                $c = Collection::create(collect($c)->only('key', 'name', 'kind', 'definition')->all());
                DB::table('formaku_schema_versions')->insert(['collection_id' => $c->id, 'version' => 1, 'definition' => json_encode($c->definition), 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['imported' => count($d['collections'])];
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

    private function document(Record $r): array
    {
        return $r->toArray() + ['data' => Revision::find($r->draft_id)?->data ?? []];
    }
}
