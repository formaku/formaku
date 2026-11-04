<?php

namespace Formaku\Cms\Jobs;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NotifyRecordOwner implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $event = DB::table('formaku_events')->lockForUpdate()->find($this->eventId);
            if (! $event || $event->notified_at) {
                return;
            }
            $record = Record::find($event->record_id);
            if (! $record) {
                return;
            }
            $collection = Collection::find($record->collection_id);
            if ($collection->kind !== 'data') {
                DB::table('formaku_events')->where('id', $event->id)->update(['notified_at' => now()]);

                return;
            }
            $class = config('auth.providers.users.model');
            $owner = $class::find($record->owner_id);
            if (! $owner) {
                return;
            }
            Mail::raw('Stan Twojego zgłoszenia został zaktualizowany. Sprawdź portal: '.url('/portal'), fn ($m) => $m->to($owner->email)->subject('Aktualizacja zgłoszenia — Formaku'));
            DB::table('formaku_events')->where('id', $event->id)->update(['notified_at' => now()]);
        });
    }
}
