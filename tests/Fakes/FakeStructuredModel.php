<?php

namespace Tests\Fakes;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\MissingPromptVersion;
use App\Ai\TokenUsage;
use App\Ai\UsageRecorder;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Models\PromptVersion;
use Database\Factories\ItpGenerationFactory;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * The gateway every test gets (bound in TestCase): no test reaches the Anthropic API.
 *
 * Like the real gateway, it needs the stage's active prompt version and logs every answered
 * call to `ai_usages`, with the fixed token counts in TOKENS. It answers with the responses
 * queued for the stage, in order, then with a valid default response.
 */
class FakeStructuredModel implements StructuredModel
{
    public const int DURATION_MS = 25;

    /**
     * @var array<string, list<array<string, mixed>|Throwable>>
     */
    protected array $queued = [];

    /**
     * @var list<array{stage: ItpStage, user_content: string, schema: array<string, mixed>, generation_id: ?string}>
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
        $promptVersion = PromptVersion::activeFor($stage) ?? throw MissingPromptVersion::forStage($stage);

        $this->calls[] = [
            'stage' => $stage,
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

        $this->usage->record($stage, $stage->model(), static::tokens(), self::DURATION_MS, $promptVersion, $generation);

        return $response;
    }

    /**
     * @return list<array{stage: ItpStage, user_content: string, schema: array<string, mixed>, generation_id: ?string}>
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
        $calls = $this->calls($stage);

        Assert::assertNotEmpty($calls, "The [{$stage->value}] stage was never called.");

        return end($calls)['user_content'];
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
     * A valid response for each stage: enough detail, a clear intake, three segments.
     *
     * @return array<string, mixed>
     */
    public static function defaultResponse(ItpStage $stage): array
    {
        return match ($stage) {
            ItpStage::Safety => ['route' => 'clear', 'categories' => [], 'confidence' => 0.98],
            ItpStage::Extraction => ['sufficient' => true, 'gaps' => [], 'slots' => [['name' => 'vision', 'value' => 'Standing on stage, at ease.']]],
            ItpStage::Followups => ['questions' => ['What would being at ease feel like in your body?']],
            ItpStage::Reversal => ItpGenerationFactory::derivedSlots(),
            ItpStage::Attunement => ['segments' => [['text' => 'Beloved one, breathe in.'], ['text' => 'You are already home.'], ['text' => 'Rest is where you begin.']]],
            ItpStage::Regeneration => ['text' => 'Here, the tide always returns to you.'],
            ItpStage::Breathe11 => ['lines' => [['text' => 'I breathe in ease.'], ['text' => 'I breathe out effort.']]],
            ItpStage::QuantumWhys => ['whys' => ['Why is it so easy for me to rest?', 'Why do I feel so at home?']],
            ItpStage::Title => ['title' => 'Coming Home to Rest', 'description' => 'An attunement for letting rest come first.'],
        };
    }
}
