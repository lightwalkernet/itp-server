<?php

namespace App\Jobs;

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Jobs\Concerns\FailsGeneration;
use App\Models\ItpAttunementSegment;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use App\Services\Itp\Stages\ResonanceRegenerationStage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites a flagged section. The flag's feedback is folded into the generation's corrections
 * first, so it reaches every later stage.
 *
 * The new lines are written as a proposal (`proposed_by_flag_id`, orders from 1, the flag's
 * part), not as current lines: the attunement and its version stay as they were until the user
 * keeps the new version or their original (ChooseSectionVersionAction). The flag records the
 * lines that were written.
 *
 * regenerating → awaiting_resonance
 */
#[Queue('generation')]
#[Tries(3)]
#[Backoff(30, 120)]
#[Timeout(600)]
class RegenerateItpSectionJob implements ShouldQueue
{
    use FailsGeneration, Queueable;

    public function __construct(
        public string $generationId,
        public int $flagId,
    ) {}

    public function handle(ResonanceRegenerationStage $regeneration): void
    {
        $generation = ItpGeneration::find($this->generationId);
        $flag = ItpSegmentFlag::find($this->flagId);

        if ($generation?->status !== ItpStatus::Regenerating || $flag?->status !== ItpSegmentFlagStatus::Open) {
            return;
        }

        $generation->recordCorrection($flag);

        $lines = $regeneration->run($generation, $flag);

        DB::transaction(function () use ($generation, $flag, $lines): void {
            $attunement = $generation->attunement;

            $written = collect($lines)->map(fn (string $text, int $index): ItpAttunementSegment => $attunement->segments()->create([
                'order' => $index + 1,
                'part' => $flag->part,
                'text' => $text,
                'version' => $attunement->version + 1,
                'proposed_by_flag_id' => $flag->id,
            ]));

            $flag->update([
                'status' => ItpSegmentFlagStatus::Regenerated,
                'resulting_segment_ids' => $written->pluck('id')->values()->all(),
            ]);

            $generation->update(['status' => ItpStatus::AwaitingResonance]);
        });
    }
}
