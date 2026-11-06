<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Derives the old → new identity reversal and the creative direction: register, temperature,
 * a freshly invented central metaphor and the vocabulary to mirror.
 */
class ReversalDerivationStage
{
    /**
     * @var array<string, string>
     */
    public const array SLOTS = [
        'reversal_old' => 'The old identity or belief being reversed',
        'reversal_new' => 'The new identity or belief replacing it',
        'register' => 'Tonal register for the attunement',
        'temperature' => 'Emotional temperature',
        'central_metaphor' => 'A freshly invented central metaphor',
        'mirrored_vocab' => 'Key vocabulary mirrored from the intake',
    ];

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array{reversal_old: string, reversal_new: string, register: string, temperature: string, central_metaphor: string, mirrored_vocab: string}
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Reversal,
            StageInput::sections([
                'Intake' => StageInput::intake($generation->intake),
                'Follow-up answers' => StageInput::followupAnswers($generation),
                'Extraction' => StageInput::extraction($generation->extraction),
            ]),
            JsonSchema::object(collect(self::SLOTS)->map(fn (string $description) => JsonSchema::string($description))->all()),
            $generation,
        );

        $slots = [];

        foreach (array_keys(self::SLOTS) as $slot) {
            if (! is_string($result[$slot] ?? null)) {
                throw UnusableResponse::missing(ItpStage::Reversal, $slot);
            }

            $slots[$slot] = $result[$slot];
        }

        return $slots;
    }
}
