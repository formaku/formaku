<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Jobs\GenerateProposal;
use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Models\Release;
use Formaku\Cms\Services\AiGateway;
use Formaku\Cms\Services\DeploymentReceipt;
use Formaku\Cms\Services\RuntimeObservation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class IntegrationController
{
    public function tasks(Request $r)
    {
        return AiTask::where('user_id', $r->user()->id)->latest()->limit(30)->get();
    }

    public function generate(Request $r, AiGateway $ai): JsonResponse
    {
        $d = $r->validate(['provider' => 'required|in:openai,gemini', 'prompt' => 'required|string|max:6000']);
        $cfg = config('formaku.ai.'.$d['provider']);
        abort_unless(! empty($cfg['key']) && ! empty($cfg['model']), 422, 'Configure the provider key and model first.');
        abort_if($ai->maxOutputTokens($d['provider']) === null, 422, 'Configure the provider output token limit as an integer from 16 to 3000.');
        abort_if(AiTask::where('user_id', $r->user()->id)->whereDate('created_at', today())->count() >= 50, 429, 'Daily task limit reached.');
        $t = AiTask::create($d + ['user_id' => $r->user()->id]);
        GenerateProposal::dispatch($t->id);

        return response()->json($t, 202);
    }

    public function cancel(Request $r, AiTask $task)
    {
        abort_unless($task->user_id === $r->user()->id, 404);
        AiTask::whereKey($task->id)->whereIn('state', ['queued', 'running'])->update(['state' => 'cancelled']);

        return $task->fresh();
    }

    public function releases()
    {
        return Release::latest()->limit(30)->get();
    }

    public function release(Request $r): Release
    {
        $d = $r->validate(['commit' => ['required', 'regex:/^[a-f0-9]{40}$/']]);
        $cfg = $this->githubConfiguration();
        $workflow = $this->workflow($cfg['workflow'] ?? null);
        $release = Release::create([
            'id' => (string) Str::uuid(),
            'commit' => $d['commit'],
            'state' => 'dispatching',
            'manifest' => ['format' => 1, 'mode' => 'build', 'repository' => $cfg['repository'], 'workflow' => $workflow],
        ]);

        try {
            $response = Http::withToken($cfg['token'])->acceptJson()->withoutRedirecting()->timeout(20)
                ->post('https://api.github.com/repos/'.$cfg['repository'].'/actions/workflows/'.$workflow.'/dispatches', [
                    'ref' => 'main',
                    'inputs' => ['release_id' => $release->id, 'source_commit' => $d['commit']],
                ]);
            $status = [
                'state' => $response->successful() ? 'requested' : 'failed',
                'run_url' => 'https://github.com/'.$cfg['repository'].'/actions',
            ];
        } catch (ConnectionException) {
            $status = ['state' => 'unknown'];
        }

        Release::whereKey($release->id)->where('state', 'dispatching')->update($status);

        return $release->refresh();
    }

    public function refreshRelease(Release $release): Release
    {
        $cfg = $this->githubConfiguration();
        $repository = $cfg['repository'];
        $manifest = $release->manifest ?? [];
        abort_unless(
            ($manifest['mode'] ?? null) === 'build'
            && is_string($manifest['repository'] ?? null)
            && strcasecmp($manifest['repository'], $repository) === 0,
            422,
            'This build does not belong to the configured GitHub repository.',
        );

        $workflow = $this->workflow($manifest['workflow'] ?? $cfg['workflow'] ?? null);
        $run = $this->findReleaseRun($release, $cfg, $workflow);
        if ($run === null) {
            return $release->refresh();
        }

        $runId = $run['id'] ?? null;
        $attempt = $run['run_attempt'] ?? 1;
        $state = match ($run['status'] ?? null) {
            'queued', 'requested', 'waiting', 'pending' => 'queued',
            'in_progress' => 'running',
            'completed' => match ($run['conclusion'] ?? null) {
                'success' => 'succeeded',
                'cancelled' => 'cancelled',
                'failure', 'action_required', 'neutral', 'skipped', 'stale', 'startup_failure', 'timed_out' => 'failed',
                default => null,
            },
            default => null,
        };
        abort_unless(is_int($runId) && $runId > 0 && is_int($attempt) && $attempt > 0 && $state !== null,
            502, 'GitHub returned an invalid build status.');

        return DB::connection($release->getConnectionName())->transaction(function () use ($release, $runId, $attempt, $state, $repository, $workflow): Release {
            $current = $release->newQuery()->lockForUpdate()->findOrFail($release->id);
            $manifest = $current->manifest;
            $currentAttempt = $manifest['run_attempt'] ?? 0;
            $progress = ['queued' => 1, 'running' => 2, 'succeeded' => 3, 'failed' => 3, 'cancelled' => 3];

            if ($current->run_id !== null && (
                (string) $current->run_id !== (string) $runId
                || $currentAttempt > $attempt
                || ($currentAttempt === $attempt && ($progress[$current->state] ?? 0) >= $progress[$state])
            )) {
                return $current;
            }

            $current->update([
                'run_id' => (string) $runId,
                'run_url' => 'https://github.com/'.$repository.'/actions/runs/'.$runId,
                'state' => $state,
                'manifest' => array_replace($manifest, ['workflow' => $workflow, 'run_attempt' => $attempt]),
            ]);

            return $current;
        });
    }

    public function deploymentReceipt(Request $request, DeploymentReceipt $receipt): JsonResponse
    {
        $data = $request->validate([
            'run_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'release_id' => ['required', 'string', 'regex:/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/'],
            'image' => ['required', 'string', 'max:300', 'regex:~\Aghcr\.io/[a-z0-9_.-]+/[a-z0-9_.-]+@sha256:[a-f0-9]{64}\z~'],
        ]);
        $cfg = $this->githubConfiguration();

        return response()->json($receipt->read($cfg['repository'], $cfg['token'], (int) $data['run_id'], $data['release_id'], $data['image']))
            ->header('Cache-Control', 'no-store');
    }

    public function runtimeObservation(RuntimeObservation $observation): JsonResponse
    {
        $cfg = $this->githubConfiguration();

        return response()->json($observation->read($cfg['repository'], $cfg['token']))
            ->header('Cache-Control', 'no-store');
    }

    /** @return array{token: string, repository: string, workflow?: string} */
    private function githubConfiguration(): array
    {
        $cfg = config('formaku.github', []);
        abort_unless(
            is_string($cfg['token'] ?? null) && $cfg['token'] !== ''
            && is_string($cfg['repository'] ?? null)
            && preg_match('~\A(?!\.{1,2}/)[A-Za-z0-9_.-]+/(?!\.{1,2}\z)[A-Za-z0-9_.-]+\z~', $cfg['repository']),
            422,
            'Configure the GitHub repository and token first.',
        );

        return $cfg;
    }

    private function workflow(mixed $value): string
    {
        abort_unless(is_string($value) && preg_match('~\A(?:[0-9]+|[A-Za-z0-9_-][A-Za-z0-9_.-]*\.ya?ml)\z~', $value),
            422, 'Configure a valid GitHub workflow first.');

        return $value;
    }

    /** @param array{token: string, repository: string} $cfg */
    private function findReleaseRun(Release $release, array $cfg, string $workflow): ?array
    {
        $actions = 'https://api.github.com/repos/'.$cfg['repository'].'/actions';
        $matches = static fn (array $run): bool => ($run['display_title'] ?? null) === 'Formaku '.$release->id
            && ($run['event'] ?? null) === 'workflow_dispatch' && ($run['head_branch'] ?? null) === 'main';

        if ($release->run_id !== null) {
            abort_unless(ctype_digit((string) $release->run_id), 422, 'This build has an invalid GitHub run ID.');
            $run = $this->readGitHub($cfg['token'], $actions.'/runs/'.$release->run_id);
            abort_unless($matches($run) && (string) ($run['id'] ?? '') === (string) $release->run_id,
                502, 'GitHub returned an unexpected build.');

            return $run;
        }

        // Search at most 300 recent dispatches since the build request, allowing for clock skew.
        for ($page = 1; $page <= 3; $page++) {
            $result = $this->readGitHub($cfg['token'], $actions.'/workflows/'.$workflow.'/runs', [
                'event' => 'workflow_dispatch',
                'branch' => 'main',
                'created' => '>='.$release->created_at->copy()->subMinute()->toIso8601ZuluString(),
                'per_page' => 100,
                'page' => $page,
            ]);
            $runs = $result['workflow_runs'] ?? null;
            abort_unless(is_array($runs) && array_is_list($runs), 502, 'GitHub returned an invalid build list.');

            foreach ($runs as $run) {
                if (is_array($run) && $matches($run)) {
                    return $run;
                }
            }
            if (count($runs) < 100) {
                break;
            }
        }

        return null;
    }

    private function readGitHub(string $token, string $url, array $query = []): array
    {
        try {
            $response = Http::withToken($token)->acceptJson()->withoutRedirecting()->timeout(5)->get($url, $query);
        } catch (ConnectionException) {
            abort(502, 'GitHub build status is temporarily unavailable.');
        }

        $payload = $response->json();
        abort_unless($response->successful() && is_array($payload), 502, 'GitHub build status is temporarily unavailable.');

        return $payload;
    }
}
