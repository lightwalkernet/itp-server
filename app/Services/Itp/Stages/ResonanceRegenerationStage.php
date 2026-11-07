<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use App\Services\Itp\IdentifiedTexts;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Library stage 5. Rewrites the flagged section (after the method core), given the derived
 * slots, every current line with its part, the section's part and lines, the line the user
 * pointed to (if any), the flag, and every earlier correction.
 *
 * The model sees each line's `order` as its id, never the database id. It returns
 * `{segments: [{id, text}]}`: the section's new lines, numbered from 1, which come back in id
 * order.
 */
class ResonanceRegenerationStage
{
    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'segments' => IdentifiedTexts::schema(
                'Position in the new section, from 1',
                'One new breath-shaped line',
                'The flagged section\'s new lines, in order',
            ),
        ]);
    }

    /**
     * @return list<string> The section's new lines, in order
     *
     * @throws UnusableResponse
     */
    public function run(ItpGeneration $generation, ItpSegmentFlag $flag): array
    {
        $attunement = $generation->attunement;
        $section = $attunement->sectionSegments($flag->part);
        $pinpointed = $section->firstWhere('id', $flag->segment_id);

        $result = $this->model->generate(
            ItpStage::Regeneration,
            StageInput::sections([
                'Derived slots' => StageInput::derivedSlots($generation->derived_slots),
                'Attunement segments' => StageInput::segments($attunement->currentSegments),
                'Flagged section' => StageInput::json([
                    'part' => $flag->part,
                    'segments' => $section->map(fn (ItpAttunementSegment $segment) => ['id' => $segment->order, 'text' => $segment->text])->all(),
                ]),
                'Pinpointed line' => $pinpointed === null ? null : StageInput::json(['id' => $pinpointed->order, 'text' => $pinpointed->text]),
                'Flag' => StageInput::json([
                    'explanation' => $flag->user_explanation,
                    'requested_change' => $flag->requested_change,
                ]),
                'Corrections' => StageInput::corrections($generation, exceptFlagId: $flag->id),
            ]),
            static::schema(),
            $generation,
        );

        return array_values(IdentifiedTexts::byId(ItpStage::Regeneration, $result['segments'], 'segments', $result));
    }
}
