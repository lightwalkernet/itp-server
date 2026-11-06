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
 * Library stage 5. Rewrites the flagged segment (after the method core), given the derived
 * slots, every current segment, the flag and every correction so far (the flag's own included:
 * the job folds it in before calling this).
 *
 * The model sees each segment's `order` as its id, never the database id, and returns
 * `{segments: [{id, text}]}`: the flagged id, plus at most one adjacent id when the line can't be
 * fixed alone. Anything else is unusable.
 */
class ResonanceRegenerationStage
{
    public const int MAX_REPLACED_SEGMENTS = 2;

    public function __construct(protected StructuredModel $model) {}

    /**
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return JsonSchema::object([
            'segments' => IdentifiedTexts::schema(
                'The id of the segment being replaced',
                'Its new text',
                'The flagged segment, and at most one adjacent segment',
            ),
        ]);
    }

    /**
     * @return array<int, string> New text by segment order: the flagged segment's, and at most one neighbour's
     *
     * @throws UnusableResponse
     */
    public function run(ItpGeneration $generation, ItpAttunementSegment $segment, ItpSegmentFlag $flag): array
    {
        $current = $generation->attunement->currentSegments()->get();

        $result = $this->model->generate(
            ItpStage::Regeneration,
            StageInput::sections([
                'Derived slots' => StageInput::derivedSlots($generation->derived_slots),
                'Attunement segments' => StageInput::segments($current, $segment->order),
                'Flagged segment' => StageInput::json(['id' => $segment->order, 'text' => $segment->text]),
                'Flag' => StageInput::json([
                    'explanation' => $flag->user_explanation,
                    'requested_change' => $flag->requested_change,
                ]),
                'Corrections' => StageInput::corrections($generation),
            ]),
            static::schema(),
            $generation,
        );

        if (count($result['segments']) > self::MAX_REPLACED_SEGMENTS) {
            throw UnusableResponse::invalid(ItpStage::Regeneration, 'it replaced more than the flagged segment and one adjacent segment', $result);
        }

        $replacements = IdentifiedTexts::byId(ItpStage::Regeneration, $result['segments'], 'segments', $result);
        $currentOrders = $current->pluck('order')->all();

        if (! array_key_exists($segment->order, $replacements)) {
            throw UnusableResponse::invalid(ItpStage::Regeneration, "it did not replace the flagged segment (id {$segment->order})", $result);
        }

        foreach (array_keys($replacements) as $order) {
            $isAdjacent = abs($order - $segment->order) === 1 && in_array($order, $currentOrders, true);

            if ($order !== $segment->order && ! $isAdjacent) {
                throw UnusableResponse::invalid(ItpStage::Regeneration, "segment id {$order} is neither the flagged segment ({$segment->order}) nor next to it", $result);
            }
        }

        return $replacements;
    }
}
