<?php

namespace App\Actions\Itp;

use App\Enums\ItpSectionChoice;
use App\Exceptions\GenerationNotReady;
use App\Models\ItpAttunement;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Support\Facades\DB;

/**
 * Settle a rewritten section with the user's choice. No line is ever deleted.
 *
 * - New: the section's old lines are superseded and the proposed lines take their place, then
 *   every current line is renumbered so the orders stay 1 to N. The attunement version goes up.
 * - Original: the proposed lines are superseded (they keep their proposing flag, so Studio shows
 *   them as rejected). The attunement is unchanged.
 *
 * The flag records the choice, and so does its correction. The generation's status is left as it
 * is: another section may still be rewriting.
 */
class ChooseSectionVersionAction
{
    /**
     * @throws GenerationNotReady unless the section has a rewrite waiting for a choice
     */
    public function execute(ItpGeneration $generation, int $part, ItpSectionChoice $keep): ItpGeneration
    {
        return DB::transaction(function () use ($generation, $part, $keep): ItpGeneration {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            $flag = $generation->flagAwaitingChoice($part)
                ?? throw new GenerationNotReady('This section has no new version waiting for a choice.');

            match ($keep) {
                ItpSectionChoice::New => $this->keepNew($generation->attunement, $flag),
                ItpSectionChoice::Original => $flag->proposedSegments()->update(['superseded_at' => now()]),
            };

            $flag->update(['choice' => $keep, 'chosen_at' => now()]);
            $generation->recordChoice($flag);

            return $generation;
        });
    }

    protected function keepNew(ItpAttunement $attunement, ItpSegmentFlag $flag): void
    {
        $version = $attunement->version + 1;
        $proposed = $flag->proposedSegments;
        $current = $attunement->currentSegments;

        $section = $current->filter(fn (ItpAttunementSegment $segment) => $segment->sectionPart() === $flag->part);
        $position = $current->filter(fn (ItpAttunementSegment $segment) => $segment->sectionPart() < $flag->part)->count();

        $lines = $current->reject(fn (ItpAttunementSegment $segment) => $section->contains($segment))->values();
        $lines->splice($position, 0, $proposed->all());

        ItpAttunementSegment::query()->whereKey($section->modelKeys())->update(['superseded_at' => now()]);

        $lines->each(function (ItpAttunementSegment $segment, int $index) use ($proposed, $version): void {
            $segment->order = $index + 1;

            if ($proposed->contains($segment)) {
                $segment->forceFill(['proposed_by_flag_id' => null, 'version' => $version]);
            }

            $segment->save();
        });

        $attunement->update(['version' => $version]);
    }
}
