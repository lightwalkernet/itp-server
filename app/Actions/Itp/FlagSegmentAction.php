<?php

namespace App\Actions\Itp;

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Exceptions\GenerationNotReady;
use App\Jobs\RegenerateItpSegmentJob;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Support\Facades\DB;

/**
 * Record the user's feedback on one segment and queue its regeneration.
 */
class FlagSegmentAction
{
    /**
     * @throws GenerationNotReady unless the generation is awaiting resonance
     */
    public function execute(
        ItpGeneration $generation,
        ItpAttunementSegment $segment,
        string $userExplanation,
        ?string $requestedChange = null,
    ): ItpGeneration {
        [$generation, $flag] = DB::transaction(function () use ($generation, $segment, $userExplanation, $requestedChange): array {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            GenerationNotReady::unlessStatus($generation, ItpStatus::AwaitingResonance, 'This generation is not awaiting resonance.');

            $flag = ItpSegmentFlag::create([
                'generation_id' => $generation->id,
                'segment_id' => $segment->id,
                'user_explanation' => $userExplanation,
                'requested_change' => $requestedChange,
                'status' => ItpSegmentFlagStatus::Open,
            ]);

            $generation->update(['status' => ItpStatus::Regenerating]);

            return [$generation, $flag];
        });

        RegenerateItpSegmentJob::dispatch($generation->id, $flag->id);

        return $generation;
    }
}
