<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Derives the Quantum Whys from the settled attunement: a list of "Why …?" questions.
 */
class QuantumWhysStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return list<string>
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::QuantumWhys,
            StageInput::sections([
                'Addressing' => StageInput::addressing($generation->intake),
                'Settled attunement' => $generation->attunement->text(),
            ]),
            JsonSchema::object([
                'whys' => JsonSchema::list(['type' => 'string'], 'The "Why …?" questions, in order'),
            ]),
            $generation,
        );

        $whys = collect((array) ($result['whys'] ?? []))
            ->filter(fn (mixed $why) => is_string($why) && trim($why) !== '')
            ->map(fn (string $why) => trim($why))
            ->values()
            ->all();

        return $whys !== [] ? $whys : throw UnusableResponse::missing(ItpStage::QuantumWhys, 'whys');
    }
}
