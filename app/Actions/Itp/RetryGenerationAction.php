<?php

namespace App\Actions\Itp;

use App\Enums\ItpSegmentFlagStatus;
use App\Enums\ItpStatus;
use App\Exceptions\GenerationNotReady;
use App\Jobs\DeriveItpCompanionsJob;
use App\Jobs\GenerateItpJob;
use App\Jobs\RegenerateItpSectionJob;
use App\Models\ItpGeneration;
use App\Models\ItpSegmentFlag;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Restart a failed generation from where it stopped. The jobs only act on the status they expect,
 * so a failed job retried from Horizon does nothing: the status has to be put back first. Work
 * already saved isn't paid for twice, because each job skips what the generation already holds.
 */
class RetryGenerationAction
{
    /**
     * @throws GenerationNotReady unless the generation has failed
     */
    public function execute(ItpGeneration $generation): ItpGeneration
    {
        [$generation, $job] = DB::transaction(function () use ($generation): array {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            GenerationNotReady::unlessStatus($generation, ItpStatus::Failed, 'Only a failed generation can be retried.');

            [$status, $job] = $this->resumePoint($generation);

            $generation->update(['status' => $status, 'last_error' => null]);

            return [$generation, $job];
        });

        Bus::dispatch($job);

        return $generation;
    }

    /**
     * The status and job that carry on from what the generation already holds.
     *
     * @return array{ItpStatus, object}
     */
    protected function resumePoint(ItpGeneration $generation): array
    {
        $openFlag = $generation->flags()->where('status', ItpSegmentFlagStatus::Open)->latest('id')->first();

        if ($openFlag instanceof ItpSegmentFlag) {
            return [ItpStatus::Regenerating, new RegenerateItpSectionJob($generation->id, $openFlag->id)];
        }

        if ($generation->attunement?->settled_at !== null) {
            return [ItpStatus::DerivingCompanions, new DeriveItpCompanionsJob($generation->id)];
        }

        $isReadyToGenerate = $generation->derived_slots !== null
            || $generation->attunement !== null
            || ($generation->extraction['sufficient'] ?? false);

        return [$isReadyToGenerate ? ItpStatus::Generating : ItpStatus::Assessing, new GenerateItpJob($generation->id)];
    }
}
