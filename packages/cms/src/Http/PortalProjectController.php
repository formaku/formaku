<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Jobs\GeneratePortalProject;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Services\PortalBlueprint;
use Formaku\Cms\Services\PortalProjectGeneration;
use Formaku\Cms\Services\PortalProjectInstaller;
use Formaku\Cms\Services\Renderer;
use Formaku\Cms\Services\SchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PortalProjectController
{
    public function index(Request $request): array
    {
        return ['projects' => PortalProject::where('actor_id', $request->user()->getAuthIdentifier())->latest('id')->limit(50)->get()->map(fn (PortalProject $project) => [
            'id' => $project->id, 'version' => $project->version, 'state' => $project->state, 'provider' => $project->provider,
            'name' => $project->proposal['name'] ?? null, 'created_at' => $project->created_at,
        ])];
    }

    public function create(Request $request, PortalProjectInstaller $installer, PortalBlueprint $blueprint, PortalProjectGeneration $generation): JsonResponse
    {
        $data = $request->validate(['brief' => 'required|string|max:8000', 'materials' => 'nullable|string|max:12000', 'provider' => 'required|in:reference,gemini,openai', 'budget_cents' => 'sometimes|integer|min:0|max:1000000']);
        $reference = $data['provider'] === 'reference';
        $budget = (int) ($data['budget_cents'] ?? 0);
        $materials = $data['materials'] ?? '';
        if (! $reference) {
            $generation->checkBudget($data['provider'], $generation->prompt($data['brief'], $materials), $budget);
            abort_if(PortalProject::where('actor_id', $request->user()->getAuthIdentifier())->where('provider', '!=', 'reference')->whereDate('created_at', today())->count() >= 50, 429, 'Daily generation limit reached.');
        }
        $project = PortalProject::create([
            'actor_id' => $request->user()->getAuthIdentifier(), 'provider' => $data['provider'], 'brief' => $data['brief'], 'materials' => $materials,
            'budget_cents' => $budget, 'cost_cents' => $reference ? 0 : null, 'snapshot' => $installer->snapshot(),
            'state' => $reference ? 'review' : 'queued', 'proposal' => $reference ? $blueprint->reference() : null,
        ]);
        if (! $reference) {
            try {
                GeneratePortalProject::dispatch($project->id);
            } catch (\Throwable $error) {
                (new GeneratePortalProject($project->id))->failed($error);
                throw $error;
            }
        }

        return response()->json($this->show($project->refresh()), $reference ? 201 : 202);
    }

    public function show(PortalProject $project): array
    {
        return $project->only(['id', 'version', 'state', 'provider', 'brief', 'materials', 'proposal', 'budget_cents', 'cost_cents', 'error']) + ['pages' => $project->pages ?? []];
    }

    public function preview(PortalProject $project, int $index, PortalBlueprint $blueprint, Renderer $renderer): Response
    {
        $page = $project->proposal['pages'][$index] ?? null;
        abort_unless($project->state === 'review' && is_array($page), 404);
        $data = $blueprint->pageData($page, route('formaku.project.preview-form', $project, absolute: false));

        return response()->view('formaku::page', $renderer->page($blueprint->pageDefinition(), $data, $data['title'], $data['slug']))
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function previewForm(Request $request, PortalProject $project, PortalBlueprint $blueprint, SchemaValidator $validator): Response
    {
        abort_unless($project->state === 'review', 404);
        $fields = $project->proposal['fields'];
        if ($request->isMethod('post')) {
            $request->validate(['title' => 'required|string|max:200', 'description' => 'required|string|max:5000']);
            $definition = $blueprint->requestsDefinition($project->proposal);
            $validator->record($definition, $request->only(['title', 'description', ...array_column($fields, 'key')]));
        }

        return response()->view('formaku::portal', [
            'previewFormUrl' => route('formaku.project.preview-form', $project, absolute: false),
            'previewValidated' => $request->isMethod('post'),
            'portalFields' => $fields,
        ])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    public function changes(PortalProject $project, PortalProjectInstaller $installer): array
    {
        abort_unless($project->state === 'review', 409);

        return $installer->changes($project);
    }

    public function update(Request $request, PortalProject $project, PortalBlueprint $blueprint): array
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'proposal' => 'required|array']);
        $proposal = $blueprint->validate($data['proposal']);

        return $this->revise($project, (int) $data['version'], ['proposal' => $proposal]);
    }

    public function refresh(Request $request, PortalProject $project, PortalProjectInstaller $installer): array
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return $this->revise($project, (int) $data['version'], ['snapshot' => $installer->snapshot()]);
    }

    private function revise(PortalProject $project, int $expected, array $attributes): array
    {
        return DB::transaction(function () use ($project, $expected, $attributes) {
            $locked = PortalProject::lockForUpdate()->findOrFail($project->id);
            abort_unless($locked->state === 'review' && $locked->version === $expected, 409, 'Review the current project version.');
            $locked->update($attributes + ['version' => $expected + 1]);

            return $this->show($locked);
        });
    }

    public function apply(Request $request, PortalProject $project, PortalProjectInstaller $installer): array
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return $this->show($installer->apply($project, (int) $data['version'], $request->user()));
    }
}
