<?php

namespace Formaku\Cms\Jobs;

use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Services\PortalBlueprint;
use Formaku\Cms\Services\PortalProjectGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeneratePortalProject implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public int $projectId) {}

    public function handle(PortalProjectGeneration $generation, PortalBlueprint $blueprint): void
    {
        $claimed = PortalProject::whereKey($this->projectId)->where('state', 'queued')->update(['state' => 'running']);
        if ($claimed !== 1) {
            return;
        }
        $project = PortalProject::findOrFail($this->projectId);

        try {
            $prompt = $generation->prompt($project->brief, $project->materials);
            $rates = $generation->checkBudget($project->provider, $prompt, $project->budget_cents);
            $response = $generation->generate($project->provider, $prompt);
            if (is_int($response['input_tokens']) && $response['input_tokens'] >= 0 && is_int($response['output_tokens']) && $response['output_tokens'] >= 0) {
                $project->update(['cost_cents' => $generation->cost($response['input_tokens'], $response['output_tokens'], $rates)]);
            }
            $proposal = $blueprint->validate(json_decode($response['text'], true, 64, JSON_THROW_ON_ERROR));
            $project->update(['proposal' => $proposal, 'state' => 'review', 'version' => $project->version + 1]);
        } catch (Throwable $error) {
            $this->failed($error);
        }
    }

    public function failed(?Throwable $error): void
    {
        PortalProject::whereKey($this->projectId)->whereIn('state', ['queued', 'running'])
            ->update(['state' => 'failed', 'error' => 'Generation failed. Check model configuration and budget, or start with the reference portal.']);
    }
}
