<?php

namespace App\Actions\Itp;

use App\Enums\ItpCompanionType;
use App\Enums\ItpStatus;
use App\Models\ItpGeneration;
use Illuminate\Support\Facades\DB;
use Itp\Core\Models\Itp;

/**
 * Write the finished, playable ITP to itp-core and complete the generation. The row is written
 * through the model, so sync stamps it and the phone gets it on its next pull. Safe to run
 * twice: a generation that already has its ITP is left alone.
 */
class FinalizeItpAction
{
    public function execute(ItpGeneration $generation): ItpGeneration
    {
        return DB::transaction(function () use ($generation): ItpGeneration {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->itp_id !== null) {
                return $generation;
            }

            $generation->load(['user', 'attunement.currentSegments', 'companions']);

            /** @var Itp $itp */
            $itp = $generation->user->itps()->create([
                'title' => $generation->title,
                'description' => $generation->description,
                'attunement_text' => $generation->attunement->text(),
                'breath11_lines' => $generation->companion(ItpCompanionType::Breathe11)?->content,
                'quantum_whys' => $generation->companion(ItpCompanionType::QuantumWhys)?->content,
            ]);

            $generation->update([
                'itp_id' => $itp->id,
                'completed_at' => now(),
                'status' => ItpStatus::Complete,
                'last_error' => null,
            ]);

            return $generation;
        });
    }
}
