<?php

namespace App\Ai\Contracts;

use App\Ai\Exceptions\MissingPromptVersion;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use InvalidArgumentException;

/**
 * The one gateway every pipeline stage calls. It runs the stage's active prompt version as the
 * system prompt (after the active method core, for the stages that use it: `StagePrompt`),
 * returns JSON matching `$schema`, and logs the call to `ai_usages`.
 */
interface StructuredModel
{
    /**
     * @param  array<string, mixed>  $schema  A JSON schema for the response object
     * @return array<string, mixed>
     *
     * @throws MissingPromptVersion when the stage, or the method core it needs, has no active prompt
     * @throws UnusableResponse when the model refuses, runs out of tokens, or returns invalid JSON or JSON that doesn't match the schema
     * @throws InvalidArgumentException for the method core, which is not a pipeline step
     */
    public function generate(ItpStage $stage, string $userContent, array $schema, ?ItpGeneration $generation = null): array;
}
