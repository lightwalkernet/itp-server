<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpAttunementPart;
use App\Enums\ItpStage;
use App\Models\ItpGeneration;
use App\Services\Itp\IdentifiedTexts;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 4. Writes the attunement (after the method core) as breath-shaped segments, each
 * tagged with the SHAPE part (1 to 8) it serves.
 *
 * Returns `{segments: [{id, part, text}]}`. The model's ids give the order: segments are sorted
 * by id and stored as orders 1, 2, 3 …, which are also the ids the regeneration stage later
 * sees. Parts must be 1 to 8 and must not go backwards in that order (a part never interleaves
 * with another); anything else is unusable.
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
            'segments' => JsonSchema::list(JsonSchema::object([
                'id' => JsonSchema::integer('Position in the piece, from 1'),
                'part' => JsonSchema::integer('The SHAPE step (1 to 8) this segment serves'),
                'text' => JsonSchema::string('One breath-shaped line'),
            ]), 'The attunement, in order'),
        ]);
    }

    /**
     * @return list<array{part: int, text: string}> The segments, in order
     *
     * @throws UnusableResponse
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

        $segments = IdentifiedTexts::itemsById(ItpStage::Attunement, $result['segments'], 'segments', $result);
        $previousPart = 0;

        foreach ($segments as $id => $segment) {
            if (ItpAttunementPart::tryFrom($segment['part']) === null) {
                throw UnusableResponse::invalid(ItpStage::Attunement, "segment id {$id} has part {$segment['part']}, not 1 to 8", $result);
            }

            if ($segment['part'] < $previousPart) {
                throw UnusableResponse::invalid(ItpStage::Attunement, "segment id {$id} is in part {$segment['part']} after part {$previousPart}: parts must run in order", $result);
            }

            $previousPart = $segment['part'];
        }

        return array_values(array_map(fn (array $segment) => ['part' => $segment['part'], 'text' => $segment['text']], $segments));
    }
}
