<?php

namespace App\Enums;

use LogicException;

/**
 * A Studio-editable, versioned prompt (`prompt_versions`). Every case but the method core is one
 * AI call in the pipeline, with its own model, effort and output cap (`itp.ai.stages`).
 *
 * The method core is not a pipeline step: it is never called on its own. Its active version is
 * the shared ITP voice, sent as its own cached system block before the stage prompt of every
 * stage that writes in that voice (`usesMethodCore()`).
 */
enum ItpStage: string
{
    case MethodCore = 'method_core';
    case Safety = 'safety';
    case Extraction = 'extraction';
    case Followups = 'followups';
    case Reversal = 'reversal';
    case Attunement = 'attunement';
    case Regeneration = 'regeneration';
    case Breathe11 = 'breathe_11';
    case QuantumWhys = 'quantum_whys';
    case Title = 'title';

    /**
     * Whether this is an AI call of its own (everything but the method core).
     */
    public function isPipelineStep(): bool
    {
        return $this !== self::MethodCore;
    }

    /**
     * Whether the method core is prepended to this stage's prompt: the stages that write in the
     * ITP voice (library stages 3 to 7).
     */
    public function usesMethodCore(): bool
    {
        return in_array($this, self::methodCoreStages(), true);
    }

    /**
     * @return list<self>
     */
    public static function pipelineSteps(): array
    {
        return array_values(array_filter(self::cases(), fn (self $stage) => $stage->isPipelineStep()));
    }

    /**
     * @return list<self>
     */
    public static function methodCoreStages(): array
    {
        return [self::Reversal, self::Attunement, self::Regeneration, self::Breathe11, self::QuantumWhys];
    }

    /**
     * What the stage must return, in one line. Shown in Studio next to the prompt.
     */
    public function purpose(): string
    {
        return match ($this) {
            self::MethodCore => 'Nothing on its own: the shared ITP voice rules, sent before the stage prompt of every stage that writes in that voice.',
            self::Safety => 'Route the intake as clear, sensitive or block, with categories, a confidence from 0 to 1 and a staff-only reason.',
            self::Extraction => 'Extract the literal slots from the answers, say whether they are sufficient, and name each gap with why it is thin.',
            self::Followups => 'Ask one or two warm, specific follow-up questions, each tied to the gap slot it fills.',
            self::Reversal => 'Derive the old spell, its protective function, the ITP shift and its family, a fresh central metaphor, essence recognition, evidence domains, register and temperature.',
            self::Attunement => 'Write the attunement as ordered, breath-shaped segments with ids, each tagged with the SHAPE part (1 to 8) it serves.',
            self::Regeneration => 'Rewrites one section: the flagged part\'s new lines, in order, with ids from 1. The person then keeps the new version or their original.',
            self::Breathe11 => 'Write the Breathe 11: eleven first-person lines with ids, drawn from the settled attunement.',
            self::QuantumWhys => 'Write the Quantum Whys: eleven "Why …?" questions with ids, drawn from the settled attunement.',
            self::Title => 'Give the finished ITP a short title and a one-sentence description.',
        };
    }

    /**
     * What the stage is given, in one line (the user message its Stage class builds). Shown in
     * Studio next to the prompt.
     */
    public function reads(): string
    {
        return match ($this) {
            self::MethodCore => 'Prepended, as its own cached system block, to the reversal, attunement, section regeneration, Breathe 11 and Quantum Whys prompts.',
            self::Safety => 'The intake answers, before anything else runs (only while the safety check is on).',
            self::Extraction => 'The intake answers and every follow-up answer so far. Runs again after each round.',
            self::Followups => 'The extracted slots, the gaps and the earlier follow-up questions and answers.',
            self::Reversal => 'The extracted slots.',
            self::Attunement => 'The display name, address style and avoid-list, the extracted slots and the derived slots.',
            self::Regeneration => 'The derived slots, every current segment by id with its part, the flagged section, the line the person pointed to (if any), the flag and every earlier correction.',
            self::Breathe11 => 'The settled attunement, the derived slots, the avoid-list and every correction.',
            self::QuantumWhys => 'The settled attunement, the derived slots, the avoid-list and every correction.',
            self::Title => 'The settled attunement, the derived slots and every correction.',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MethodCore => 'Method core',
            self::Safety => 'Safety check',
            self::Extraction => 'Extraction',
            self::Followups => 'Follow-up questions',
            self::Reversal => 'Reversal',
            self::Attunement => 'Attunement',
            self::Regeneration => 'Section regeneration',
            self::Breathe11 => 'Breathe 11',
            self::QuantumWhys => 'Quantum Whys',
            self::Title => 'Title',
        };
    }

    /**
     * @throws LogicException for the method core, which has no model of its own
     */
    public function model(): string
    {
        return config($this->configKey('model'));
    }

    /**
     * @throws LogicException for the method core, which has no effort of its own
     */
    public function effort(): string
    {
        return config($this->configKey('effort'));
    }

    /**
     * @throws LogicException for the method core, which has no output cap of its own
     */
    public function maxTokens(): int
    {
        return (int) config($this->configKey('max_tokens'));
    }

    protected function configKey(string $setting): string
    {
        if (! $this->isPipelineStep()) {
            throw new LogicException("The [{$this->value}] prompt is not a pipeline step and has no {$setting} of its own.");
        }

        return "itp.ai.stages.{$this->value}.{$setting}";
    }
}
