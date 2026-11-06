<?php

namespace App\Enums;

/**
 * One AI call in the pipeline. Each stage has its own Studio-editable prompt (`prompt_versions`)
 * and its own model, effort and output cap (`itp.ai.stages`).
 */
enum ItpStage: string
{
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
     * What the stage must return, in one line. Shown in Studio next to the prompt.
     */
    public function purpose(): string
    {
        return match ($this) {
            self::Safety => 'Classify the intake as clear, sensitive or block, with categories and a confidence from 0 to 1.',
            self::Extraction => 'Extract the slots from the intake and follow-up answers, and say whether there is enough to generate, listing any gaps.',
            self::Followups => 'Ask one or two warm, targeted follow-up questions that fill the listed gaps.',
            self::Reversal => 'Derive the old and new identity, register, temperature, a fresh central metaphor and mirrored vocabulary.',
            self::Attunement => 'Write the attunement as ordered, breath-shaped segments.',
            self::Regeneration => 'Rewrite only the flagged segment so it fits the rest of the attunement and the user\'s feedback.',
            self::Breathe11 => 'Write the Breathe 11 companion as ordered lines drawn from the settled attunement.',
            self::QuantumWhys => 'Write the Quantum Whys: a list of "Why …?" questions drawn from the settled attunement.',
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
            self::Safety => 'The intake answers, before anything else runs (only while the safety check is on).',
            self::Extraction => 'The intake answers and every follow-up answer so far. Runs again after each round.',
            self::Followups => 'The intake, earlier follow-up answers, the gaps the extraction found and which round this is.',
            self::Reversal => 'The intake, the follow-up answers and the extraction\'s slots and gaps.',
            self::Attunement => 'The intake, the follow-up answers and the slots from the reversal stage.',
            self::Regeneration => 'The name and address style, the answers and slots, the whole attunement, the flagged segment, the user\'s feedback and any earlier feedback.',
            self::Breathe11 => 'The name, address style and things to avoid, and the settled attunement.',
            self::QuantumWhys => 'The name, address style and things to avoid, and the settled attunement.',
            self::Title => 'The name, address style and things to avoid, and the settled attunement.',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Safety => 'Safety check',
            self::Extraction => 'Extraction',
            self::Followups => 'Follow-up questions',
            self::Reversal => 'Reversal',
            self::Attunement => 'Attunement',
            self::Regeneration => 'Segment regeneration',
            self::Breathe11 => 'Breathe 11',
            self::QuantumWhys => 'Quantum Whys',
            self::Title => 'Title',
        };
    }

    public function model(): string
    {
        return config("itp.ai.stages.{$this->value}.model");
    }

    public function effort(): string
    {
        return config("itp.ai.stages.{$this->value}.effort");
    }

    public function maxTokens(): int
    {
        return (int) config("itp.ai.stages.{$this->value}.max_tokens");
    }
}
