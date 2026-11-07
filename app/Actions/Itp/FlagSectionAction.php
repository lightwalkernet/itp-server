<?php

namespace App\Actions\Itp;

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Exceptions\GenerationNotReady;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Support\Facades\DB;

/**
 * Record the user's feedback on one section of the attunement, optionally pointing to one of its
 * lines, and queue the section's rewrite. One section is rewritten at a time, and a section whose
 * rewrite is waiting for the user's choice can't be flagged again until they choose.
 */
class FlagSectionAction
{
    /**
     * @param  int|null  $segmentId  The line the user pointed to: a current line of that section (StoreItpSectionFlagRequest)
     *
     * @throws GenerationNotReady unless the generation is awaiting resonance with no rewrite of this section waiting
     */
    public function execute(
        ItpGeneration $generation,
        int $part,
        string $userExplanation,
        ?string $requestedChange = null,
        ?int $segmentId = null,
    ): ItpGeneration {
        [$generation, $flag] = DB::transaction(function () use ($generation, $part, $userExplanation, $requestedChange, $segmentId): array {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            GenerationNotReady::unlessStatus($generation, ItpStatus::AwaitingResonance, 'This generation is not awaiting resonance.');

            if ($generation->hasProposalWaiting($part)) {
                throw new GenerationNotReady('This section has a new version waiting for a choice.');
            }

            $flag = ItpSegmentFlag::create([
                'generation_id' => $generation->id,
                'part' => $part,
                'segment_id' => $segmentId,
                'user_explanation' => $userExplanation,
                'requested_change' => $requestedChange,
                'status' => ItpSegmentFlagStatus::Open,
            ]);

            $generation->update(['status' => ItpStatus::Regenerating]);

            return [$generation, $flag];
        });

        RegenerateItpSectionJob::dispatch($generation->id, $flag->id);

        return $generation;
    }
}
