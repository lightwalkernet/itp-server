<?php

namespace App\Ai;

use App\Ai\Exceptions\MissingPromptVersion;
use App\Enums\ItpStage;
use App\Models\PromptVersion;
use InvalidArgumentException;

/**
 * The system prompt of one call: the stage's active prompt version and, for the stages that write
 * in the ITP voice, the active method core before it.
 *
 * Each is its own system block with its own cache breakpoint (two of the four allowed). The
 * method core is byte-identical on every call, so its breakpoint caches it as a prefix shared by
 * every method-core stage on the same model; the second breakpoint caches the stage prompt on top.
 */
final readonly class StagePrompt
{
    public function __construct(
        public ItpStage $stage,
        public PromptVersion $stagePrompt,
        public ?PromptVersion $methodCore = null,
    ) {}

    /**
     * The active prompts for a stage.
     *
     * @throws MissingPromptVersion when the stage, or the method core it needs, has no active version
     * @throws InvalidArgumentException for the method core, which is never called on its own
     */
    public static function for(ItpStage $stage): self
    {
        if (! $stage->isPipelineStep()) {
            throw new InvalidArgumentException("The [{$stage->value}] prompt is not a pipeline step and cannot be called on its own.");
        }

        $stagePrompt = PromptVersion::activeFor($stage) ?? throw MissingPromptVersion::forStage($stage);

        $methodCore = $stage->usesMethodCore()
            ? (PromptVersion::activeFor(ItpStage::MethodCore) ?? throw MissingPromptVersion::forStage(ItpStage::MethodCore))
            : null;

        return new self($stage, $stagePrompt, $methodCore);
    }

    /**
     * The system blocks, method core first, each marked as a cache breakpoint.
     *
     * @return list<array{type: 'text', text: string, cacheControl: array{type: 'ephemeral'}}>
     */
    public function systemBlocks(): array
    {
        return collect([$this->methodCore, $this->stagePrompt])
            ->filter()
            ->map(fn (PromptVersion $version) => ['type' => 'text', 'text' => $version->body, 'cacheControl' => ['type' => 'ephemeral']])
            ->values()
            ->all();
    }
}
