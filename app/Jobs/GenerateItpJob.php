<?php

namespace App\Jobs;

use App\Enums\ItpSafetyRoute;
use App\Enums\ItpStatus;
use App\Jobs\Concerns\FailsGeneration;
use App\Models\ItpGeneration;
use App\Services\Itp\Stages\AttunementGenerationStage;
use App\Services\Itp\Stages\FollowUpGenerationStage;
use App\Services\Itp\Stages\ReversalDerivationStage;
use App\Services\Itp\Stages\SafetyClassificationStage;
use App\Services\Itp\Stages\SlotExtractionStage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;

/**
 * Runs a generation from wherever its status says it is, up to the next point that needs the
 * user: follow-up questions or the finished attunement. Each step stores its result before the
 * next starts, so a retry resumes rather than repeats.
 *
 * queued → [classifying] → assessing → awaiting_followup | generating → awaiting_resonance
 */
#[Queue('generation')]
#[Tries(3)]
#[Backoff(30, 120)]
#[Timeout(600)]
class GenerateItpJob implements ShouldQueue
{
    use FailsGeneration, Queueable;

    public function __construct(public string $generationId) {}

    public function handle(
        SafetyClassificationStage $safety,
        SlotExtractionStage $extraction,
        FollowUpGenerationStage $followups,
        ReversalDerivationStage $reversal,
        AttunementGenerationStage $attunement,
    ): void {
        $generation = ItpGeneration::find($this->generationId);

        if ($generation === null) {
            return;
        }

        if ($generation->status === ItpStatus::Queued) {
            $generation->update([
                'status' => config('itp.generation.safety_check') ? ItpStatus::Classifying : ItpStatus::Assessing,
            ]);
        }

        if ($generation->status === ItpStatus::Classifying && ! $this->passesSafetyCheck($generation, $safety)) {
            return;
        }

        if ($generation->status === ItpStatus::Assessing && $this->asksFollowups($generation, $extraction, $followups)) {
            return;
        }

        if ($generation->status === ItpStatus::Generating) {
            $this->generateAttunement($generation, $reversal, $attunement);
        }
    }

    /**
     * Hold a sensitive intake (deferred) and refuse a blocked one. A clear intake moves on to
     * assessing. The verdict is stored first, so a retry never asks twice.
     */
    protected function passesSafetyCheck(ItpGeneration $generation, SafetyClassificationStage $safety): bool
    {
        if ($generation->safety_route === null) {
            $verdict = $safety->run($generation);

            $generation->update([
                'safety_route' => $verdict['route'],
                'safety_categories' => $verdict['categories'],
                'safety_confidence' => $verdict['confidence'],
                'safety_reason' => $verdict['reason'],
            ]);
        }

        $status = match ($generation->safety_route) {
            ItpSafetyRoute::Clear => ItpStatus::Assessing,
            ItpSafetyRoute::Sensitive => ItpStatus::Deferred,
            ItpSafetyRoute::Block => ItpStatus::Blocked,
        };

        $generation->update(['status' => $status]);

        return $status === ItpStatus::Assessing;
    }

    /**
     * Re-run the extraction over the intake and every answer so far, and store it. While it is
     * not sufficient and rounds are left, ask the next round and wait for the user. Otherwise
     * move on to generating.
     */
    protected function asksFollowups(ItpGeneration $generation, SlotExtractionStage $extraction, FollowUpGenerationStage $followups): bool
    {
        $result = $extraction->run($generation);

        $generation->update(['extraction' => $result]);

        $roundsLeft = $generation->followup_rounds < (int) config('itp.generation.max_followup_rounds');

        $questions = ! $result['sufficient'] && $roundsLeft ? $followups->run($generation) : [];

        DB::transaction(function () use ($generation, $questions): void {
            if ($questions === []) {
                $generation->update(['status' => ItpStatus::Generating]);

                return;
            }

            $round = $generation->followup_rounds + 1;

            foreach ($questions as $question) {
                $generation->followups()->create(['round' => $round, ...$question]);
            }

            $generation->update(['followup_rounds' => $round, 'status' => ItpStatus::AwaitingFollowup]);
        });

        return $questions !== [];
    }

    protected function generateAttunement(ItpGeneration $generation, ReversalDerivationStage $reversal, AttunementGenerationStage $attunement): void
    {
        if ($generation->derived_slots === null) {
            $generation->update(['derived_slots' => $reversal->run($generation)]);
        }

        $segments = $generation->attunement()->exists() ? [] : $attunement->run($generation);

        DB::transaction(function () use ($generation, $segments): void {
            if ($segments !== []) {
                $created = $generation->attunement()->create(['version' => 1]);

                foreach ($segments as $index => $segment) {
                    $created->segments()->create(['order' => $index + 1, 'part' => $segment['part'], 'text' => $segment['text'], 'version' => 1]);
                }
            }

            $generation->update(['status' => ItpStatus::AwaitingResonance]);
        });
    }
}
