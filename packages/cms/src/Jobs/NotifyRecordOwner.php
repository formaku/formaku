<?php

namespace Formaku\Cms\Jobs;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\PortalReplies;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class NotifyRecordOwner implements ShouldQueue
{
    use Queueable;

    /** Event types that notify the record owner. */
    public const TYPES = ['saved', 'transitioned', PortalReplies::EVENT];

    public int $tries = 3;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $event = DB::table('formaku_events')->lockForUpdate()->find($this->eventId);
            if (! $event || $event->notified_at) {
                return;
            }
            // An event with nobody to notify is closed. Left open, it would be queued again every minute
            // and, once enough of them collect, keep newer events from being delivered.
            $record = Record::find($event->record_id);
            $collection = $record ? Collection::find($record->collection_id) : null;
            $class = config('auth.providers.users.model');
            $owner = $collection?->kind === 'data' && $record->owner_id !== null ? $class::find($record->owner_id) : null;
            if ($owner && filled($owner->email)) {
                Mail::raw('Stan Twojego zgłoszenia został zaktualizowany. Sprawdź portal: '.url('/portal'), fn ($m) => $m->to($owner->email)->subject('Aktualizacja zgłoszenia — Formaku'));
            }
            DB::table('formaku_events')->where('id', $event->id)->update(['notified_at' => now()]);
        });
    }
}
