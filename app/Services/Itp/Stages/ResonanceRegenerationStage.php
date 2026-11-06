<?php

namespace App\Services\Itp\Stages;

use App\Ai\Contracts\StructuredModel;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStage;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use App\Services\Itp\JsonSchema;
use App\Services\Itp\StageInput;

/**
 * Rewrites only the flagged segment, in the context of the whole attunement, the user's
 * feedback and any earlier feedback on this attunement.
 */
class ResonanceRegenerationStage
{
    public function __construct(protected StructuredModel $model) {}

    public function run(ItpGeneration $generation, ItpAttunementSegment $segment, ItpSegmentFlag $flag): string
    {
        $result = $this->model->generate(
            ItpStage::Regeneration,
            StageInput::sections([
                'Addressing' => StageInput::addressing($generation->intake),
                'Follow-up answers' => StageInput::followupAnswers($generation),
                'Derived slots' => StageInput::slots($generation->derived_slots),
                'Attunement' => StageInput::segments($generation->attunement->currentSegments),
                'Flagged segment' => StageInput::segments([$segment]),
                'Feedback' => StageInput::fields([
                    'Why it does not resonate' => $flag->user_explanation,
                    'Requested change' => $flag->requested_change,
                ]),
                'Earlier feedback' => $this->earlierFeedback($generation, $flag),
            ]),
            JsonSchema::object([
                'text' => JsonSchema::string('The new text for the flagged segment'),
            ]),
            $generation,
        );

        $text = trim((string) ($result['text'] ?? ''));

        return $text !== '' ? $text : throw UnusableResponse::missing(ItpStage::Regeneration, 'text');
    }

    protected function earlierFeedback(ItpGeneration $generation, ItpSegmentFlag $flag): ?string
    {
        $earlier = $generation->flags()
            ->with('segment')
            ->where('status', ItpSegmentFlagStatus::Regenerated)
            ->whereKeyNot($flag->id)
            ->orderBy('id')
            ->get();

        return $earlier->isEmpty() ? null : $earlier
            ->map(fn (ItpSegmentFlag $earlierFlag) => StageInput::fields([
                'Flagged' => $earlierFlag->segment?->text,
                'Why' => $earlierFlag->user_explanation,
                'Requested change' => $earlierFlag->requested_change,
            ]))
            ->implode("\n\n---\n\n");
    }
}
