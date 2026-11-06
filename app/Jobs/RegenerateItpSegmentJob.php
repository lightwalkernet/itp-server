<?php

namespace App\Jobs;

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Jobs\Concerns\FailsGeneration;
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
 * Replaces a flagged segment. The old segment is superseded, never deleted, so the flag keeps
 * the text it was about; the flag records the segment that replaced it.
 *
 * regenerating → awaiting_resonance
 */
#[Queue('generation')]
#[Tries(3)]
#[Backoff(30, 120)]
#[Timeout(600)]
class RegenerateItpSegmentJob implements ShouldQueue
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

        $segment = $flag->segment;
        $text = $regeneration->run($generation, $segment, $flag);

        DB::transaction(function () use ($generation, $flag, $segment, $text): void {
            $attunement = $generation->attunement;
            $version = $attunement->version + 1;

            $replacement = $attunement->segments()->create([
                'order' => $segment->order,
                'text' => $text,
                'version' => $version,
            ]);

            $segment->update(['superseded_at' => now()]);

            $flag->update([
                'status' => ItpSegmentFlagStatus::Regenerated,
                'resulting_segment_id' => $replacement->id,
            ]);

            $attunement->update(['version' => $version]);
            $generation->update(['status' => ItpStatus::AwaitingResonance]);
        });
    }
}
