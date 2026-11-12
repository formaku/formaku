<?php

namespace Formaku\Cms\Http;

use ErrorException;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PageTemplate;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\CollectionAccess;
use Formaku\Cms\Services\FieldAccess;
use Formaku\Cms\Services\Renderer;
use Formaku\Cms\Services\SchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use TypeError;

class PageTemplateController
{
    public function index(Request $request, Collection $collection, CollectionAccess $access): array
    {
        $this->checkCanCreateFromTemplate($request, $collection, $access);
        $templates = PageTemplate::where('collection_id', $collection->id)->latest('id')->paginate(25, ['id', 'name']);

        return [
            'data' => $templates->items(),
            'current_page' => $templates->currentPage(),
            'last_page' => $templates->lastPage(),
        ];
    }

    public function show(Request $request, Collection $collection, string $templateId, CollectionAccess $access): array
    {
        $this->checkCanCreateFromTemplate($request, $collection, $access);

        return $this->findTemplate($collection, $templateId)->only(['id', 'name', 'blocks']);
    }

    public function store(Request $request, Record $record, Renderer $renderer): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'version' => 'required|integer|min:1',
        ]);

        $template = DB::transaction(function () use ($record, $data, $renderer) {
            $collection = Collection::lockForUpdate()->findOrFail($record->collection_id);
            abort_unless(PageTemplate::supportsCollection($collection), 404);
            $current = Record::lockForUpdate()->findOrFail($record->id);
            abort_unless((int) $current->version === (int) $data['version'], 409, 'Record changed. Reload and compare.');
            $blocks = $current->draft?->data['blocks'] ?? [];
            abort_if($blocks === [], 422, 'Save at least one block before creating a template.');
            abort_if(strlen(json_encode($blocks, JSON_THROW_ON_ERROR)) > 256 * 1024, 422, 'Template blocks exceed 256 KiB.');
            SchemaValidator::blockList($blocks, 'blocks');

            try {
                $renderer->blocks($blocks);
            } catch (TypeError|ErrorException) {
                throw ValidationException::withMessages(['blocks' => 'Invalid block data.']);
            }

            return PageTemplate::create([
                'collection_id' => $collection->id,
                'name' => $data['name'],
                'blocks' => $blocks,
            ]);
        });

        return response()->json($template->only(['id', 'name']), 201);
    }

    public function destroy(Collection $collection, string $templateId): array
    {
        $this->findTemplate($collection, $templateId)->delete();

        return ['deleted' => true];
    }

    private function checkCanCreateFromTemplate(Request $request, Collection $collection, CollectionAccess $access): void
    {
        abort_unless(PageTemplate::supportsCollection($collection), 404);
        $access->check($collection, $request->user(), true);
        abort_if($access->rule($collection, $request->user())['scope'] === 'assigned', 403);
        $blocks = collect($collection->definition['fields'])->firstWhere('key', 'blocks');
        abort_unless(app(FieldAccess::class)->canWrite($collection, $blocks, $request->user()), 403);
    }

    private function findTemplate(Collection $collection, string $templateId): PageTemplate
    {
        $id = filter_var($templateId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);

        return PageTemplate::where('collection_id', $collection->id)->findOrFail($id);
    }
}
