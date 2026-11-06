<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\IdentifiedTexts;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 7. Writes the Quantum Whys from the settled attunement (after the method core).
 *
 * Returns `{questions: [{id, text}]}`, stored as a list of strings sorted by id (the shape of
 * itp-core's `quantum_whys`).
 */
class QuantumWhysStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'questions' => IdentifiedTexts::schema('Position, from 1', 'One "Why …?" question', 'The eleven questions, in order'),
        ]);
    }

    /**
     * @return list<string>
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::QuantumWhys,
            StageInput::sections([
                'Settled attunement' => $generation->attunement->text(),
                'Derived slots' => StageInput::derivedSlots($generation->derived_slots),
                'Avoid' => $generation->intake->avoid,
                'Corrections' => StageInput::corrections($generation),
            ]),
            static::schema(),
            $generation,
        );

        return array_values(IdentifiedTexts::byId(ItpStage::QuantumWhys, $result['questions'], 'questions', $result));
    }
}
