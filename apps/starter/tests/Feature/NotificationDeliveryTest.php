<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Console\NotifyCommand;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_notifies_the_owner_once_and_marks_the_event(): void
    {
        $owner = User::factory()->create(['role' => 'client']);
        $record = $this->request($owner);

        $this->artisan('formaku:notify')->expectsOutput('Queued notifications: 1.')->assertSuccessful();
        $this->artisan('formaku:notify')->expectsOutput('Queued notifications: 0.')->assertSuccessful();

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $this->assertSame($owner->email, $messages[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertNotNull(DB::table('formaku_events')->where('record_id', $record->id)->value('notified_at'));
    }

    public function test_events_without_a_recipient_leave_the_queue_instead_of_blocking_it(): void
    {
        $former = User::factory()->create(['role' => 'client']);
        $orphans = [$this->request($former)->id, $this->request($former)->id];
        $former->delete();
        $owner = User::factory()->create(['role' => 'client']);
        $current = $this->request($owner);

        // Only the oldest events fit in one run. They have nobody to notify.
        $this->artisan('formaku:notify', ['--limit' => 2])->expectsOutput('Queued notifications: 2.')->assertSuccessful();
        $this->assertCount(0, $this->sentMessages());
        $this->assertSame(0, DB::table('formaku_events')->whereIn('record_id', $orphans)->whereNull('notified_at')->count());

        $this->artisan('formaku:notify', ['--limit' => 2])->expectsOutput('Queued notifications: 1.')->assertSuccessful();
        $this->assertCount(1, $this->sentMessages());
        $this->assertNotNull(DB::table('formaku_events')->where('record_id', $current->id)->value('notified_at'));
    }

    public function test_event_of_a_missing_record_is_closed(): void
    {
        $event = DB::table('formaku_events')->insertGetId([
            'record_id' => 987654, 'type' => 'saved', 'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('formaku:notify')->assertSuccessful();

        $this->assertNotNull(DB::table('formaku_events')->where('id', $event)->value('notified_at'));
        $this->assertCount(0, $this->sentMessages());
    }

    public function test_limit_must_be_a_positive_integer(): void
    {
        $this->artisan('formaku:notify', ['--limit' => 0])->assertFailed();
        $this->artisan('formaku:notify', ['--limit' => 'many'])->assertFailed();
    }

    public function test_starter_schedules_notifications_and_publication_with_short_locks(): void
    {
        $this->assertInstanceOf(NotifyCommand::class, Artisan::all()['formaku:notify']);

        foreach (['formaku:notify', 'formaku:publish-scheduled'] as $command) {
            $events = collect(Schedule::events())->filter(fn ($event) => str_contains((string) $event->command, $command));
            $this->assertCount(1, $events, $command);
            $event = $events->first();
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertSame(5, $event->expiresAt, 'A lock left by a killed run must not block the task for a day.');
        }
    }

    private function request(User $owner): Record
    {
        $collection = Collection::firstOrCreate(['key' => 'requests'], ['name' => 'Zgłoszenia', 'kind' => 'data', 'definition' => [
            'fields' => [['key' => 'title', 'label' => 'Temat', 'type' => 'text', 'required' => true]],
        ]]);

        return app(RecordService::class)->save($collection, null, ['title' => 'Zgłoszenie'], 0, $owner->id);
    }

    private function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }
}
