<?php

namespace Tests\Fakes;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Ai\StagePrompt;
use App\Ai\TokenUsage;
use App\Ai\UsageRecorder;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use Database\Factories\ItpGenerationFactory;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * The gateway every test gets (bound in TestCase): no test reaches the Anthropic API.
 *
 * Like the real gateway, it resolves the system prompt (the stage's active version, after the
 * active method core for the stages that use it), logs every answered call to `ai_usages` with
 * the fixed token counts in TOKENS, and rejects a response that doesn't match the schema. It
 * answers with the responses queued for the stage, in order, then with a valid default response
 * in the prompt library's shape.
 */
class FakeStructuredModel implements StructuredModel
{
    public const int DURATION_MS = 25;

    /**
     * @var array<string, list<array<string, mixed>|Throwable>>
     */
    protected array $queued = [];

    /**
     * @var list<array{stage: ItpStage, system: list<array<string, mixed>>, user_content: string, schema: array<string, mixed>, generation_id: ?string}>
     */
    protected array $calls = [];

    public function __construct(protected UsageRecorder $usage) {}

    public static function tokens(): TokenUsage
    {
        return new TokenUsage(input: 1000, output: 500, cacheWrite: 200, cacheRead: 800);
    }

    /**
     * Queue responses for a stage. A Throwable is thrown instead of answering.
     *
     * @param  array<string, mixed>|Throwable  ...$responses
     */
    public function respondWith(ItpStage $stage, array|Throwable ...$responses): static
    {
        $this->queued[$stage->value] = [...($this->queued[$stage->value] ?? []), ...$responses];

        return $this;
    }

    public function generate(ItpStage $stage, string $userContent, array $schema, ?ItpGeneration $generation = null): array
    {
        $prompt = StagePrompt::for($stage);

        $this->calls[] = [
            'stage' => $stage,
            'system' => $prompt->systemBlocks(),
            'user_content' => $userContent,
            'schema' => $schema,
            'generation_id' => $generation?->id,
        ];

        $response = ($this->queued[$stage->value] ?? []) === []
            ? static::defaultResponse($stage)
            : array_shift($this->queued[$stage->value]);

        if ($response instanceof Throwable) {
            throw $response;
        }

        $this->usage->record($stage, $stage->model(), static::tokens(), self::DURATION_MS, $prompt, $generation);

        $violation = JsonSchema::violation($schema, $response);

        if ($violation !== null) {
            throw UnusableResponse::invalid($stage, $violation, $response);
        }

        return $response;
    }

    /**
     * @return list<array{stage: ItpStage, system: list<array<string, mixed>>, user_content: string, schema: array<string, mixed>, generation_id: ?string}>
     */
    public function calls(?ItpStage $stage = null): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $stage === null || $call['stage'] === $stage));
    }

    /**
     * The user message of the stage's latest call.
     */
    public function lastInput(ItpStage $stage): string
    {
        return $this->lastCall($stage)['user_content'];
    }

    /**
     * The system block texts of the stage's latest call, in order.
     *
     * @return list<string>
     */
    public function lastSystem(ItpStage $stage): array
    {
        return array_column($this->lastCall($stage)['system'], 'text');
    }

    public function assertCalled(ItpStage $stage, ?int $times = null): void
    {
        $count = count($this->calls($stage));

        $times === null
            ? Assert::assertGreaterThan(0, $count, "The [{$stage->value}] stage was never called.")
            : Assert::assertSame($times, $count, "The [{$stage->value}] stage was called {$count} times, not {$times}.");
    }

    public function assertNotCalled(ItpStage $stage): void
    {
        $this->assertCalled($stage, 0);
    }

    /**
     * A valid response for each stage, in the library's shape: enough detail, a clear intake,
     * three segments in parts 1, 3 and 8, and a one-line rewrite of whichever section is flagged.
     *
     * @return array<string, mixed>
     */
    public static function defaultResponse(ItpStage $stage): array
    {
        return match ($stage) {
            ItpStage::Safety => ['route' => 'clear', 'categories' => [], 'confidence' => 0.98, 'reason' => 'Ordinary rest and confidence goals.'],
            ItpStage::Extraction => ItpGenerationFactory::extraction(),
            ItpStage::Followups => ['questions' => [['gap_slot' => 'scene', 'question_text' => 'What would being at ease feel like in your body?']]],
            ItpStage::Reversal => ItpGenerationFactory::derivedSlots(),
            ItpStage::Attunement => ['segments' => [
                ['id' => 1, 'part' => 1, 'text' => 'Beloved one, breathe in.'],
                ['id' => 2, 'part' => 3, 'text' => 'You are already home.'],
                ['id' => 3, 'part' => 8, 'text' => 'Rest is where you begin.'],
            ]],
            ItpStage::Regeneration => ['segments' => [['id' => 1, 'text' => 'Here, the tide always returns to you.']]],
            ItpStage::Breathe11 => ['lines' => [['id' => 1, 'text' => 'I breathe in ease.'], ['id' => 2, 'text' => 'I breathe out effort.']]],
            ItpStage::QuantumWhys => ['questions' => [['id' => 1, 'text' => 'Why is it so easy for me to rest?'], ['id' => 2, 'text' => 'Why do I feel so at home?']]],
            ItpStage::Title => ['title' => 'Coming Home to Rest', 'description' => 'An attunement for letting rest come first.'],
            ItpStage::MethodCore => Assert::fail('The method core is never called on its own.'),
        };
    }

    /**
     * @return array{stage: ItpStage, system: list<array<string, mixed>>, user_content: string, schema: array<string, mixed>, generation_id: ?string}
     */
    protected function lastCall(ItpStage $stage): array
    {
        $calls = $this->calls($stage);

        Assert::assertNotEmpty($calls, "The [{$stage->value}] stage was never called.");

        return end($calls);
    }
}
