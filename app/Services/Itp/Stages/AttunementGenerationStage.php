<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\IdentifiedTexts;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 4. Writes the attunement (after the method core) as breath-shaped segments.
 *
 * Returns `{segments: [{id, text}]}`. The model's ids give the order: segments are sorted by id
 * and stored as orders 1, 2, 3 …, which are also the ids the regeneration stage later sees.
 */
class AttunementGenerationStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'segments' => IdentifiedTexts::schema('Position in the piece, from 1', 'One breath-shaped line', 'The attunement, in order'),
        ]);
    }

    /**
     * @return list<string> The segment texts, in order
     */
    public function run(ItpGeneration $generation): array
    {
        $intake = $generation->intake;

        $result = $this->model->generate(
            ItpStage::Attunement,
            StageInput::sections([
                'Display name' => $intake->display_name,
                'Address style' => $intake->address_style->value,
                'Avoid' => $intake->avoid,
                'Slots' => StageInput::slots($generation->extraction),
                'Derived slots' => StageInput::derivedSlots($generation->derived_slots),
            ]),
            static::schema(),
            $generation,
        );

        return array_values(IdentifiedTexts::byId(ItpStage::Attunement, $result['segments'], 'segments', $result));
    }
}
