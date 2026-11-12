<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PublishScheduledCommand extends Command
{
    protected $signature = 'formaku:publish-scheduled';

    protected $description = 'Publish Formaku records whose scheduled time has arrived';

    public function handle(RecordService $records): int
    {
        $cutoff = now();
        $due = Record::query()
            ->select(['id', 'version', 'owner_id'])
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', $cutoff)
            ->lazyById(100);

        $published = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($due as $record) {
            try {
                $records->publish($record, $record->version, $record->owner_id);
                $published++;
            } catch (Throwable $error) {
                if ($error instanceof HttpExceptionInterface && $error->getStatusCode() === 409) {
                    $skipped++;

                    continue;
                }

                $failed++;
                $this->error("Publication failed for record {$record->id}. See the application log.");
                if (app(ExceptionHandler::class)->shouldReport($error)) {
                    report($error);
                } else {
                    Log::warning('Scheduled publication failed.', [
                        'record_id' => $record->id,
                        'exception_type' => $error::class,
                    ]);
                }
            }
        }

        $this->line("Published: {$published}, skipped: {$skipped}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
