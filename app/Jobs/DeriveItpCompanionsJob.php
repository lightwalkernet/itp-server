<?php

namespace App\Jobs;

use App\Actions\Itp\FinalizeItpAction;
use App\Enums\ItpCompanionType;
use App\Enums\ItpStatus;
use App\Jobs\Concerns\FailsGeneration;
use App\Models\ItpGeneration;
use App\Services\Itp\Stages\Breathe11Stage;
use App\Services\Itp\Stages\QuantumWhysStage;
use App\Services\Itp\Stages\TitleStage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Derives Breathe 11, Quantum Whys and the title from the settled attunement, then writes the
 * finished ITP. Each result is stored as it arrives and skipped on a retry, so a retry only
 * pays for what is still missing.
 *
 * deriving_companions → complete
 */
#[Queue('generation')]
#[Tries(3)]
#[Backoff(30, 120)]
#[Timeout(600)]
class DeriveItpCompanionsJob implements ShouldQueue
{
    use FailsGeneration, Queueable;

    public function __construct(public string $generationId) {}

    public function handle(
        Breathe11Stage $breathe11,
        QuantumWhysStage $quantumWhys,
        TitleStage $title,
        FinalizeItpAction $finalize,
    ): void {
        $generation = ItpGeneration::find($this->generationId);

        if ($generation?->status !== ItpStatus::DerivingCompanions) {
            return;
        }

        $this->deriveCompanion($generation, ItpCompanionType::Breathe11, fn () => $breathe11->run($generation));
        $this->deriveCompanion($generation, ItpCompanionType::QuantumWhys, fn () => $quantumWhys->run($generation));

        if ($generation->title === null) {
            $generation->update($title->run($generation));
        }

        $finalize->execute($generation);
    }

    /**
     * @param  callable(): array<int, mixed>  $derive
     */
    protected function deriveCompanion(ItpGeneration $generation, ItpCompanionType $type, callable $derive): void
    {
        if ($generation->companions()->where('type', $type)->exists()) {
            return;
        }

        $generation->companions()->create([
            'type' => $type,
            'content' => $derive(),
            'from_attunement_version' => $generation->attunement->version,
        ]);
    }
}
