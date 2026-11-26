<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\Jobs\NotifyRecordOwner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyCommand extends Command
{
    protected $signature = 'formaku:notify {--limit=100 : Events to queue in one run, from 1 to 1000}';

    protected $description = 'Queue e-mail notifications about record events for record owners';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The limit must be an integer from 1 to 1000.');

            return self::FAILURE;
        }

        // Oldest first, so an event that cannot be delivered is handled before newer ones wait behind it.
        $events = DB::table('formaku_events')->whereNull('notified_at')->whereIn('type', NotifyRecordOwner::TYPES)
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($events as $event) {
            NotifyRecordOwner::dispatch($event);
        }

        $this->line('Queued notifications: '.$events->count().'.');

        return self::SUCCESS;
    }
}
