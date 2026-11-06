<?php

namespace App\Ai;

use Anthropic\Client;
use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\MissingPromptVersion;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Models\PromptVersion;

/**
 * The gateway on the official Anthropic SDK. The stage's active prompt is the system prompt,
 * cached; the response is constrained to the stage's JSON schema; thinking is adaptive, with
 * its depth set by the stage's effort.
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
        $promptVersion = PromptVersion::activeFor($stage) ?? throw MissingPromptVersion::forStage($stage);
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
            system: [
                ['type' => 'text', 'text' => $promptVersion->body, 'cacheControl' => ['type' => 'ephemeral']],
            ],
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
            $promptVersion,
            $generation,
        );

        if ($message->stopReason === 'refusal') {
            throw UnusableResponse::refused($stage);
        }

        if ($message->stopReason === 'max_tokens') {
            throw UnusableResponse::truncated($stage);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                return is_array($data) ? $data : throw UnusableResponse::invalidJson($stage);
            }
        }

        throw UnusableResponse::invalidJson($stage);
    }
}
