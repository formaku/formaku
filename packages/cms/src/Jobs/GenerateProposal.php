<?php

namespace Formaku\Cms\Jobs;

use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Services\AiGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateProposal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public int $taskId) {}

    public function failed(?\Throwable $e): void
    {
        AiTask::whereKey($this->taskId)->whereIn('state', ['queued', 'running'])->update(['state' => 'failed', 'error' => 'Task failed or timed out.']);
    }

    public function handle(AiGateway $ai): void
    {
        $task = AiTask::findOrFail($this->taskId);
        if ($task->state !== 'queued') {
            return;
        }
        $claimed = AiTask::whereKey($task->id)->where('state', 'queued')->update(['state' => 'running']);
        if ($claimed !== 1) {
            return;
        }

        try {
            $result = $ai->generate($task->provider, $task->prompt);
            AiTask::whereKey($task->id)->where('state', 'running')->update(['state' => 'ready', 'result' => $result]);
        } catch (\Throwable $e) {
            AiTask::whereKey($task->id)->where('state', 'running')->update(['state' => 'failed', 'error' => 'Nie udało się przygotować propozycji. Sprawdź konfigurację dostawcy lub spróbuj ponownie.']);
        }
    }
}
