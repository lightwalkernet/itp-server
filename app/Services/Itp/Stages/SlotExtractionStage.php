<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Extracts the slots from the intake and every follow-up answer so far, and judges whether
 * there is enough to generate. Runs again after each round of answers.
 */
class SlotExtractionStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array{sufficient: bool, gaps: list<string>, slots: list<array{name: string, value: string}>}
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Extraction,
            StageInput::sections([
                'Intake' => StageInput::intake($generation->intake),
                'Follow-up answers' => StageInput::followupAnswers($generation),
            ]),
            JsonSchema::object([
                'sufficient' => ['type' => 'boolean', 'description' => 'Whether there is enough detail to generate'],
                'gaps' => JsonSchema::list(['type' => 'string'], 'Missing or thin areas, if any'),
                'slots' => JsonSchema::list(JsonSchema::object([
                    'name' => JsonSchema::string('Slot name'),
                    'value' => JsonSchema::string('What the user said for it'),
                ]), 'The extracted slots'),
            ]),
            $generation,
        );

        return [
            'sufficient' => (bool) ($result['sufficient'] ?? true),
            'gaps' => array_values(array_filter((array) ($result['gaps'] ?? []), fn (mixed $gap) => is_string($gap) && $gap !== '')),
            'slots' => array_values(array_filter(
                (array) ($result['slots'] ?? []),
                fn (mixed $slot) => is_array($slot) && is_string($slot['name'] ?? null) && is_string($slot['value'] ?? null),
            )),
        ];
    }
}
