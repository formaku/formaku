<?php

namespace Tests\Feature;

use App\Models\User;
use Formaku\Cms\Jobs\GenerateProposal;
use Formaku\Cms\Models\AiTask;
use Formaku\Cms\Services\AiGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerateProposalStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_provider_block_map_fails_the_task_without_a_result(): void
    {
        $task = $this->generate(['first' => ['type' => 'hero', 'props' => ['title' => 'Synthetic heading']]]);

        $this->assertSame('failed', $task->state);
        $this->assertNull($task->result);
    }

    #[DataProvider('orderedBlockEncodings')]
    public function test_ordered_blocks_are_saved_in_order_and_marked_ready(bool $encodeAsObject): void
    {
        $blocks = [
            ['type' => 'cta', 'props' => ['title' => 'First call to action', 'url' => '/contact']],
            ['type' => 'hero', 'props' => ['title' => 'Second heading', 'text' => 'Synthetic content']],
        ];

        $task = $this->generate($encodeAsObject ? (object) $blocks : $blocks);

        $this->assertSame('ready', $task->state);
        $this->assertSame(['cta', 'hero'], array_column($task->result['blocks'], 'type'));
        $this->assertEquals($blocks, $task->result['blocks']);
    }

    public static function orderedBlockEncodings(): array
    {
        return [
            'JSON list' => [false],
            'JSON object with consecutive numeric keys' => [true],
        ];
    }

    private function generate(array|object $blocks): AiTask
    {
        config(['formaku.ai.openai' => ['key' => 'synthetic-token', 'model' => 'test-model']]);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                'title' => 'Synthetic proposal',
                'blocks' => $blocks,
            ], JSON_THROW_ON_ERROR)]]]],
        ])]);
        $task = AiTask::create([
            'user_id' => User::factory()->create()->id,
            'provider' => 'openai',
            'prompt' => 'Synthetic page request',
        ]);

        (new GenerateProposal($task->id))->handle(app(AiGateway::class));

        Http::assertSentCount(1);

        return $task->refresh();
    }
}
