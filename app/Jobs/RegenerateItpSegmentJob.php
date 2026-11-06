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
 * Replaces a flagged segment, and at most one adjacent segment when the model needed to change
 * it too. The flag's feedback is folded into the generation's corrections first, so it reaches
 * this call and every later stage.
 *
 * Each replaced segment is superseded, never deleted, and its replacement takes the same order,
 * so the flag keeps the text it was about. The model works in orders (its segment ids); they are
 * mapped back to database rows here. The flag records the segments that were written.
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

        $generation->recordCorrection($flag);

        $segment = $flag->segment;
        $replacements = $regeneration->run($generation, $segment, $flag);

        DB::transaction(function () use ($generation, $flag, $segment, $replacements): void {
            $attunement = $generation->attunement;
            $version = $attunement->version + 1;
            $current = $attunement->currentSegments()->get()->keyBy('order');

            $written = collect($replacements)->map(function (string $text, int $order) use ($attunement, $current, $version): ItpAttunementSegment {
                $current->get($order)->update(['superseded_at' => now()]);

                return $attunement->segments()->create([
                    'order' => $order,
                    'text' => $text,
                    'version' => $version,
                ]);
            });

            $flag->update([
                'status' => ItpSegmentFlagStatus::Regenerated,
                'resulting_segment_id' => $written->get($segment->order)->id,
                'resulting_segment_ids' => $written->pluck('id')->values()->all(),
            ]);

            $attunement->update(['version' => $version]);
            $generation->update(['status' => ItpStatus::AwaitingResonance]);
        });
    }
}
