<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\IdentifiedTexts;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 6. Condenses the settled attunement (after the method core) into the Breathe 11.
 *
 * Returns `{lines: [{id, text}]}`, stored as the companion's `[{order, text}]`, sorted by id and
 * numbered from 1 (the shape of itp-core's `breath11_lines`).
 */
class Breathe11Stage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'lines' => IdentifiedTexts::schema('Position, from 1', 'One first-person line, said on one breath', 'The eleven lines, in order'),
        ]);
    }

    /**
     * @return list<array{order: int, text: string}>
     */
    public function run(ItpGeneration $generation): array
    {
        $result = $this->model->generate(
            ItpStage::Breathe11,
            StageInput::sections([
                'Settled attunement' => $generation->attunement->text(),
                'Derived slots' => StageInput::derivedSlots($generation->derived_slots),
                'Avoid' => $generation->intake->avoid,
                'Corrections' => StageInput::corrections($generation),
            ]),
            static::schema(),
            $generation,
        );

        return collect(IdentifiedTexts::byId(ItpStage::Breathe11, $result['lines'], 'lines', $result))
            ->values()
            ->map(fn (string $text, int $index) => ['order' => $index + 1, 'text' => $text])
            ->all();
    }
}
