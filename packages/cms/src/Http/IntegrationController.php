<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Jobs\GenerateProposal;
use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class IntegrationController
{
    public function tasks(Request $r)
    {
        return AiTask::where('user_id', $r->user()->id)->latest()->limit(30)->get();
    }

    public function generate(Request $r)
    {
        $d = $r->validate(['provider' => 'required|in:openai,gemini', 'prompt' => 'required|string|max:6000']);
        $cfg = config('formaku.ai.'.$d['provider']);
        abort_unless(! empty($cfg['key']) && ! empty($cfg['model']), 422, 'Configure the provider key and model first.');
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

    public function release(Request $r)
    {
        $d = $r->validate(['commit' => ['required', 'regex:/^[a-f0-9]{40}$/']]);
        $cfg = config('formaku.github');
        abort_unless(! empty($cfg['token']) && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $cfg['repository'] ?? ''), 422, 'Configure the GitHub repository and token first.');
        $release = Release::create(['id' => (string) Str::uuid(), 'commit' => $d['commit'], 'state' => 'dispatching', 'manifest' => ['format' => 1, 'mode' => 'build', 'repository' => $cfg['repository']]]);
        try {
            $res = Http::withToken($cfg['token'])->acceptJson()->timeout(20)->post('https://api.github.com/repos/'.$cfg['repository'].'/actions/workflows/'.$cfg['workflow'].'/dispatches', ['ref' => 'main', 'inputs' => ['release_id' => $release->id, 'source_commit' => $d['commit']]]);
            $release->update(['state' => $res->successful() ? 'requested' : 'failed', 'run_url' => 'https://github.com/'.$cfg['repository'].'/actions']);
        } catch (\Throwable) {
            $release->update(['state' => 'unknown']);
        }

        return $release;
    }
}
