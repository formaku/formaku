<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Console\Command;
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

        foreach ($due as $record) {
            try {
                $records->publish($record, $record->version, $record->owner_id);
            } catch (Throwable $error) {
                report($error);
            }
        }

        return self::SUCCESS;
    }
}
