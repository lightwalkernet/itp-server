<?php

namespace App\Ai;

use App\Enums\ItpStage;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use Illuminate\Support\Facades\Log;

/**
 * Logs one AI call to `ai_usages` and notes the prompt versions on its generation. The stage's
 * own prompt version is `prompt_version_id`; the method core it ran with, if any, is
 * `method_core_version_id`.
 */
class UsageRecorder
{
    public function __construct(protected UsageCost $cost) {}

    public function record(
        ItpStage $stage,
        string $model,
        TokenUsage $tokens,
        int $durationMs,
        StagePrompt $prompt,
        ?ItpGeneration $generation = null,
    ): AiUsage {
        $cost = $this->cost->calculate($model, $tokens);

        if ($cost === null) {
            Log::warning("No price for model [{$model}] in itp.ai.prices; its cost is logged as 0.", ['stage' => $stage->value]);
        }

        $usage = AiUsage::create([
            'generation_id' => $generation?->id,
            'stage' => $stage,
            'provider' => 'anthropic',
            'model' => $model,
            'input_tokens' => $tokens->input,
            'output_tokens' => $tokens->output,
            'cache_write_tokens' => $tokens->cacheWrite,
            'cache_read_tokens' => $tokens->cacheRead,
            'cost_usd' => $cost ?? 0,
            'prompt_version_id' => $prompt->stagePrompt->id,
            'method_core_version_id' => $prompt->methodCore?->id,
            'duration_ms' => $durationMs,
        ]);

        $generation?->recordPromptVersions($prompt);

        return $usage;
    }
}
