<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Writes the attunement as ordered, breath-shaped segments.
 */
class AttunementGenerationStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return list<string> The segment texts, in order
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Attunement,
            StageInput::sections([
                'Intake' => StageInput::intake($generation->intake),
                'Follow-up answers' => StageInput::followupAnswers($generation),
                'Derived slots' => StageInput::slots($generation->derived_slots),
            ]),
            JsonSchema::object([
                'segments' => JsonSchema::list(
                    JsonSchema::object(['text' => JsonSchema::string('One breath-shaped line')]),
                    'The attunement, in order',
                ),
            ]),
            $generation,
        );

        $segments = collect((array) ($result['segments'] ?? []))
            ->map(fn (mixed $segment) => is_array($segment) ? trim((string) ($segment['text'] ?? '')) : '')
            ->filter(fn (string $text) => $text !== '')
            ->values()
            ->all();

        return $segments !== [] ? $segments : throw UnusableResponse::missing(ItpStage::Attunement, 'segments');
    }
}
