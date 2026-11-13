<?php

namespace Formaku\Cms\Http;

use Dom\HTMLDocument;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Models\Revision;
use Formaku\Cms\Services\FieldAccess;
use Formaku\Cms\Services\PublicPages;
use Formaku\Cms\Services\Renderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PublicationCheckController
{
    public function __invoke(Request $request, Record $record, FieldAccess $fields, Renderer $renderer): JsonResponse
    {
        $input = $request->validate(['version' => 'required|integer|min:0']);
        abort_unless((int) $input['version'] === (int) $record->version, 409);
        $collection = Collection::findOrFail($record->collection_id);
        abort_unless(PublicPages::allowsCollection($collection) && $record->draft_id !== null, 404);
        $revision = Revision::where('record_id', $record->id)->findOrFail($record->draft_id);
        $definition = $collection->definition;
        $data = $fields->readableData($collection, PublicPages::data($definition, $revision->data), $request->user());
        $checks = [];

        // If the publisher cannot read the blocks, skip diagnostics about them entirely.
        $blocksField = collect($definition['fields'] ?? [])->firstWhere('key', 'blocks');
        $canInspectBlocks = $blocksField !== null && ! ($blocksField['private'] ?? false)
            && $fields->canRead($collection, $blocksField, $request->user());
        try {
            $slug = $data['slug'] ?? 'page-'.$record->id;
            $page = $renderer->page($definition, $data, PublicPages::title($data, $slug), $slug);
            if ($page['description'] === null) {
                $descriptionKey = $definition['seo']['description'] ?? null;
                $descriptionField = collect($definition['fields'] ?? [])->firstWhere('key', $descriptionKey);
                if ($descriptionField === null || $fields->canRead($collection, $descriptionField, $request->user())) {
                    $checks[] = ['code' => 'seo_description', 'severity' => 'warning', 'count' => 1];
                }
            }
            if ($canInspectBlocks) {
                $checks = array_merge($checks, $this->inspectHtml($page['html']));
            }
        } catch (ValidationException|HttpExceptionInterface $error) {
            if ($error instanceof HttpExceptionInterface && $error->getStatusCode() !== 422) {
                throw $error;
            }

            $checks[] = ['code' => 'invalid_content', 'severity' => 'error', 'count' => 1];
        }

        $translation = DB::table('formaku_record_translations')->where('record_id', $record->id)->first();
        if ($translation?->group_key !== null && ! DB::table('formaku_record_translations')
            ->where('collection_id', $record->collection_id)->where('group_key', $translation->group_key)
            ->where('record_id', '<>', $record->id)
            ->whereIn('record_id', PublicPages::publishedRecords()->where('collection_id', $record->collection_id)->select('id'))
            ->exists()) {
            $checks[] = ['code' => 'translation_unpublished', 'severity' => 'warning', 'count' => 1];
        }

        return response()->json([
            'version' => $record->version,
            'revision_id' => $revision->id,
            'checks' => $checks,
        ])->header('Cache-Control', 'no-store');
    }

    /** @return list<array{code: string, severity: 'warning', count: int}> */
    private function inspectHtml(string $html): array
    {
        $document = HTMLDocument::createFromString('<!doctype html><html><head><title>Formaku</title></head><body>'.$html.'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $counts = ['image_alt' => 0, 'link' => 0, 'heading_empty' => 0, 'heading_order' => 0];
        foreach ($document->querySelectorAll('img') as $image) {
            if (trim($image->getAttribute('alt') ?? '') === '') {
                $counts['image_alt']++;
            }
        }
        foreach ($document->querySelectorAll('a') as $link) {
            $target = trim($link->getAttribute('href') ?? '');
            $label = trim($link->getAttribute('aria-label') ?? '') ?: trim($link->textContent);
            if ($label === '') {
                foreach ($link->querySelectorAll('img[alt]') as $image) {
                    $label .= trim($image->getAttribute('alt'));
                }
            }
            if ($target === '' || $target === '#' || $label === '') {
                $counts['link']++;
            }
        }
        $previousLevel = 0;
        foreach ($document->querySelectorAll('h1,h2,h3,h4,h5,h6') as $heading) {
            $level = (int) substr($heading->localName, 1);
            if (trim($heading->textContent) === '') {
                $counts['heading_empty']++;
            }
            if ($level > $previousLevel + 1) {
                $counts['heading_order']++;
            }
            $previousLevel = $level;
        }

        $checks = [];
        foreach ($counts as $code => $count) {
            if ($count > 0) {
                $checks[] = ['code' => $code, 'severity' => 'warning', 'count' => $count];
            }
        }

        return $checks;
    }
}
