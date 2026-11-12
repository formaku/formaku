<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Jobs\GenerateProposal;
use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerateProposalClaimTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    protected function setUp(): void
    {
        parent::setUp();

        config(['formaku.ai.openai' => ['key' => 'synthetic-token', 'model' => 'test-model']]);
        Http::preventStrayRequests();
    }

    #[DataProvider('claimLostTo')]
    public function test_a_state_change_after_reading_the_task_prevents_the_provider_request(string $state): void
    {
        $task = $this->queuedTask();
        Http::fake([self::ENDPOINT => Http::response($this->providerResponse())]);
        Event::listen('eloquent.retrieved: '.AiTask::class, function (AiTask $loaded) use ($task, $state): void {
            if ($loaded->is($task) && $loaded->state === 'queued') {
                $changed = AiTask::whereKey($task->id)->where('state', 'queued')->update(['state' => $state]);
                $this->assertSame(1, $changed);
            }
        });

        (new GenerateProposal($task->id))->handle(app(AiGateway::class));

        Http::assertNothingSent();
        $this->assertSame($state, $task->fresh()->state);
        $this->assertNull($task->fresh()->result);
    }

    public static function claimLostTo(): array
    {
        return [
            'another worker' => ['running'],
            'cancellation' => ['cancelled'],
        ];
    }

    public function test_replaying_the_same_task_after_a_successful_claim_sends_one_request(): void
    {
        $task = $this->queuedTask();
        Http::fake([self::ENDPOINT => Http::response($this->providerResponse())]);
        $job = new GenerateProposal($task->id);

        $job->handle(app(AiGateway::class));
        $job->handle(app(AiGateway::class));

        Http::assertSentCount(1);
        $task->refresh();
        $this->assertSame('ready', $task->state);
        $this->assertSame('Synthetic proposal', $task->result['title']);
    }

    public function test_cancellation_after_claim_ignores_the_late_provider_result(): void
    {
        $task = $this->queuedTask();
        Http::fake([self::ENDPOINT => function () use ($task) {
            $cancelled = AiTask::whereKey($task->id)->where('state', 'running')->update(['state' => 'cancelled']);
            $this->assertSame(1, $cancelled);

            return Http::response($this->providerResponse());
        }]);

        (new GenerateProposal($task->id))->handle(app(AiGateway::class));

        Http::assertSentCount(1);
        $task->refresh();
        $this->assertSame('cancelled', $task->state);
        $this->assertNull($task->result);
    }

    private function queuedTask(): AiTask
    {
        return AiTask::create([
            'user_id' => User::factory()->create()->id,
            'provider' => 'openai',
            'prompt' => 'Synthetic page request',
        ]);
    }

    private function providerResponse(): array
    {
        return [
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                'title' => 'Synthetic proposal',
                'blocks' => [['type' => 'hero', 'props' => ['title' => 'Synthetic heading']]],
            ], JSON_THROW_ON_ERROR)]]]],
        ];
    }
}
