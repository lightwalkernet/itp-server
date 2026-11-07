<?php

namespace App\Actions\Itp;

use App\Enums\ItpStatus;
use App\Exceptions\GenerationNotReady;
use App\Jobs\DeriveItpCompanionsJob;
use App\Models\ItpGeneration;
use Illuminate\Support\Facades\DB;

/**
 * Settle the attunement and queue the companions, title and finished ITP. Not while a section is
 * being rewritten (the status is then regenerating) or a rewrite is waiting for the user's choice.
 */
class AcceptAttunementAction
{
    /**
     * @throws GenerationNotReady unless the generation is awaiting resonance with no rewrite waiting
     */
    public function execute(ItpGeneration $generation): ItpGeneration
    {
        $generation = DB::transaction(function () use ($generation): ItpGeneration {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            GenerationNotReady::unlessStatus($generation, ItpStatus::AwaitingResonance, 'This generation is not awaiting resonance.');

            if ($generation->hasProposalWaiting()) {
                throw new GenerationNotReady('A section has a new version waiting for a choice.');
            }

            $generation->attunement->update(['settled_at' => now()]);
            $generation->update(['status' => ItpStatus::DerivingCompanions]);

            return $generation;
        });

        DeriveItpCompanionsJob::dispatch($generation->id);

        return $generation;
    }
}
