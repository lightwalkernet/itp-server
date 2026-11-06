<?php

namespace App\Ai\Contracts;

use App\Ai\Exceptions\MissingPromptVersion;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;

/**
 * The one gateway every pipeline stage calls. It runs the stage's active prompt version as the
 * system prompt, returns JSON matching `$schema`, and logs the call to `ai_usages`.
 */
interface StructuredModel
{
    /**
     * @param  array<string, mixed>  $schema  A JSON schema for the response object
     * @return array<string, mixed>
     *
     * @throws MissingPromptVersion when the stage has no active prompt
     * @throws UnusableResponse when the model refuses, runs out of tokens or returns invalid JSON
     */
    public function generate(ItpStage $stage, string $userContent, array $schema, ?ItpGeneration $generation = null): array;
}
