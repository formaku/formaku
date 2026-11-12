<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Console\PublishScheduledCommand;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PublicationScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_preserves_the_instant_from_an_explicit_offset(): void
    {
        $this->travelTo(Date::parse('2026-11-12T02:00:00Z'));
        $publisher = User::factory()->create(['role' => 'publisher']);
        $collection = $this->contentCollection();
        $draft = $this->draft($collection, $publisher, 'offset-page', 'Draft');

        $this->actingAs($publisher)->postJson($this->scheduleUrl($draft), [
            'version' => $draft->version,
            'publish_at' => '2026-11-12T07:45:00+05:30',
        ])->assertOk()
            ->assertJsonPath('publish_at', '2026-11-12T02:15:00.000000Z')
            ->assertJsonPath('version', $draft->version + 1);

        $this->assertSame('2026-11-12 02:15:00', $draft->fresh()->getRawOriginal('publish_at'));
    }

    public function test_scheduling_uses_the_configured_storage_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Warsaw']);
        date_default_timezone_set('Europe/Warsaw');

        try {
            $this->travelTo(Date::parse('2026-11-12T03:00:00+01:00'));
            $publisher = User::factory()->create(['role' => 'publisher']);
            $collection = $this->contentCollection();
            $draft = $this->draft($collection, $publisher, 'configured-timezone', 'Draft');

            $this->actingAs($publisher)->postJson($this->scheduleUrl($draft), [
                'version' => $draft->version,
                'publish_at' => '2026-11-12T07:45:00+05:30',
            ])->assertOk()->assertJsonPath('publish_at', '2026-11-12T02:15:00.000000Z');

            $this->assertSame('2026-11-12 03:15:00', $draft->fresh()->getRawOriginal('publish_at'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_post_requires_a_future_date_and_never_cancels_a_schedule(): void
    {
        $this->travelTo(Date::parse('2026-11-12T02:00:00Z'));
        $publisher = User::factory()->create(['role' => 'publisher']);
        $collection = $this->contentCollection();
        $draft = $this->draft($collection, $publisher, 'required-date', 'Draft');
        $draft->update(['publish_at' => '2026-11-12 03:00:00']);

        $this->actingAs($publisher);
        foreach ([[], ['publish_at' => null], ['publish_at' => ''], ['publish_at' => '2026-11-12T07:45:00+06:00']] as $payload) {
            $this->postJson($this->scheduleUrl($draft), ['version' => $draft->version] + $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('publish_at');
        }

        $this->assertSame('2026-11-12 03:00:00', $draft->fresh()->getRawOriginal('publish_at'));
        $this->assertSame($draft->version, $draft->fresh()->version);
    }

    public function test_cancel_clears_the_due_date_and_keeps_both_revisions(): void
    {
        $this->travelTo(Date::parse('2026-11-12T02:00:00Z'));
        $publisher = User::factory()->create(['role' => 'publisher']);
        $collection = $this->contentCollection();
        $draft = $this->draft($collection, $publisher, 'scheduled-page', 'Published revision');
        $records = app(RecordService::class);
        $published = $records->publish($draft, $draft->version, $publisher->id);
        $draft = $records->save($collection, $published, ['slug' => 'scheduled-page', 'title' => 'Unpublished revision'], $published->version, $publisher->id);
        $due = '2026-11-12 02:15:00';
        $draft->update(['publish_at' => $due]);
        $scheduledCopy = $draft->fresh();
        $control = $this->draft($collection, $publisher, 'control-page', 'Still scheduled');
        $control->update(['publish_at' => $due]);

        $this->actingAs($publisher)->deleteJson($this->scheduleUrl($draft), ['version' => $draft->version])
            ->assertOk()
            ->assertJsonPath('publish_at', null)
            ->assertJsonPath('version', $draft->version + 1)
            ->assertJsonPath('draft_id', $draft->draft_id)
            ->assertJsonPath('published_id', $published->published_id);

        $this->deleteJson($this->scheduleUrl($draft), ['version' => $draft->version + 1])
            ->assertOk()
            ->assertJsonPath('publish_at', null)
            ->assertJsonPath('version', $draft->version + 1);

        $this->travelTo(Date::parse('2026-11-12T02:16:00Z'));
        $this->artisan('formaku:publish-scheduled')->assertSuccessful();

        $this->assertSame($control->draft_id, $control->fresh()->published_id);
        $this->assertSame($draft->draft_id, $draft->fresh()->draft_id);
        $this->assertSame($published->published_id, $draft->fresh()->published_id);
        $this->assertNull($draft->fresh()->publish_at);

        // The scheduler also passes the version from any record already read before cancellation.
        $this->expectExceptionObject(new HttpException(409));
        $records->publish($scheduledCopy, $scheduledCopy->version, $publisher->id);
    }

    public function test_cancel_requires_publish_permission_and_the_current_version(): void
    {
        $publisher = User::factory()->create(['role' => 'publisher']);
        $editor = User::factory()->create(['role' => 'editor']);
        $collection = $this->contentCollection();
        $draft = $this->draft($collection, $publisher, 'permission-page', 'Draft');
        $draft->update(['publish_at' => '2026-11-12 03:00:00']);
        $url = $this->scheduleUrl($draft);

        $this->actingAs($editor)->deleteJson($url, ['version' => $draft->version])->assertForbidden();
        $this->actingAs($publisher)->deleteJson($url, ['version' => $draft->version - 1])->assertConflict();
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->assertSame('2026-11-12 03:00:00', $draft->fresh()->getRawOriginal('publish_at'));
        $this->assertSame($draft->version, $draft->fresh()->version);
    }

    public function test_package_command_is_registered_and_the_starter_schedules_it_once(): void
    {
        $command = 'formaku:publish-scheduled';
        $this->assertInstanceOf(PublishScheduledCommand::class, Artisan::all()[$command]);

        $events = collect(Schedule::events())->where('description', $command);
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertStringContainsString($command, $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_command_publishes_due_records_once_even_when_another_record_fails(): void
    {
        $this->travelTo(Date::parse('2026-11-12T02:15:00Z'));
        $publisher = User::factory()->create(['role' => 'publisher']);
        $collection = $this->contentCollection();
        $invalid = $this->draft($collection, $publisher, 'invalid-slug', 'Invalid slug');
        $invalid->draft->update(['data' => ['slug' => 'invalid slug', 'title' => 'Invalid slug']]);
        $invalid->update(['publish_at' => '2026-11-12 02:14:59']);
        $due = $this->draft($collection, $publisher, 'due-now', 'Ready now');
        $due->update(['publish_at' => '2026-11-12 02:15:00']);
        $future = $this->draft($collection, $publisher, 'due-later', 'Ready later');
        $future->update(['publish_at' => '2026-11-12 02:15:01']);
        Log::shouldReceive('warning')->twice()->with('Scheduled publication failed.', [
            'record_id' => $invalid->id,
            'exception_type' => HttpException::class,
        ]);

        $this->artisan('formaku:publish-scheduled')
            ->expectsOutput("Publication failed for record {$invalid->id}. See the application log.")
            ->expectsOutput('Published: 1, skipped: 0, failed: 1.')
            ->assertFailed();

        $this->assertNull($invalid->fresh()->published_id);
        $this->assertNotNull($invalid->fresh()->publish_at);
        $this->assertSame($due->draft_id, $due->fresh()->published_id);
        $this->assertNull($due->fresh()->publish_at);
        $this->assertNull($future->fresh()->published_id);
        $this->assertSame('2026-11-12 02:15:01', $future->fresh()->getRawOriginal('publish_at'));

        $this->artisan('formaku:publish-scheduled')
            ->expectsOutput('Published: 0, skipped: 0, failed: 1.')
            ->assertFailed();

        $this->assertSame($due->version + 1, $due->fresh()->version);
    }

    public function test_command_skips_a_concurrent_version_change_without_reporting_a_failure(): void
    {
        $publisher = User::factory()->create(['role' => 'publisher']);
        $draft = $this->draft($this->contentCollection(), $publisher, 'concurrent-change', 'Concurrent change');
        $draft->update(['publish_at' => now()->subMinute()]);
        $this->mock(RecordService::class)->shouldReceive('publish')->once()->andThrow(new HttpException(409));
        Exceptions::fake();

        $this->artisan('formaku:publish-scheduled')
            ->expectsOutput('Published: 0, skipped: 1, failed: 0.')
            ->assertSuccessful();

        Exceptions::assertNothingReported();
        $this->assertNull($draft->fresh()->published_id);
    }

    private function contentCollection(): Collection
    {
        return Collection::create([
            'key' => 'pages',
            'name' => 'Pages',
            'kind' => 'content',
            'definition' => [
                'public' => true,
                'fields' => [
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'slug', 'label' => 'Slug', 'type' => 'text'],
                ],
            ],
        ]);
    }

    private function draft(Collection $collection, User $publisher, string $slug, string $title): Record
    {
        return app(RecordService::class)->save($collection, null, ['slug' => $slug, 'title' => $title], 0, $publisher->id);
    }

    private function scheduleUrl(Record $draft): string
    {
        return '/formaku/api/v1/records/'.$draft->id.'/schedule';
    }
}
