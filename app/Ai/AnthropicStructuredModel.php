<?php

namespace App\Ai;

use Anthropic\Client;
use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;

/**
 * The gateway on the official Anthropic SDK. The system prompt is the stage's active prompt,
 * after the active method core for the stages that write in the ITP voice, each block cached
 * (`StagePrompt`). The response is constrained to the stage's JSON schema and checked against
 * it again here; thinking is adaptive, with its depth set by the stage's effort.
 *
 * Every answered call is logged, refusals and truncations included, because they are billed.
 */
class AnthropicStructuredModel implements StructuredModel
{
    public function __construct(
        protected Client $client,
        protected UsageRecorder $usage,
    ) {}

    public function generate(ItpStage $stage, string $userContent, array $schema, ?ItpGeneration $generation = null): array
    {
        $prompt = StagePrompt::for($stage);
        $model = $stage->model();
        $startedAt = hrtime(true);

        $message = $this->client->messages->create(
            maxTokens: $stage->maxTokens(),
            messages: [
                ['role' => 'user', 'content' => $userContent],
            ],
            model: $model,
            outputConfig: [
                'effort' => $stage->effort(),
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            system: $prompt->systemBlocks(),
            thinking: ['type' => 'adaptive'],
        );

        $this->usage->record(
            $stage,
            $model,
            new TokenUsage(
                input: $message->usage->inputTokens,
                output: $message->usage->outputTokens,
                cacheWrite: $message->usage->cacheCreationInputTokens ?? 0,
                cacheRead: $message->usage->cacheReadInputTokens ?? 0,
            ),
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
            $prompt,
            $generation,
        );

        if ($message->stopReason === 'refusal') {
            throw UnusableResponse::refused($stage);
        }

        if ($message->stopReason === 'max_tokens') {
            throw UnusableResponse::truncated($stage);
        }

        $text = collect($message->content)
            ->filter(fn (object $block) => $block->type === 'text')
            ->map(fn (object $block) => $block->text)
            ->implode('');

        $data = JsonResponse::decode($text) ?? throw UnusableResponse::invalidJson($stage, $text);

        $violation = JsonSchema::violation($schema, $data);

        if ($violation !== null) {
            throw UnusableResponse::invalid($stage, $violation, $text);
        }

        return $data;
    }
}
