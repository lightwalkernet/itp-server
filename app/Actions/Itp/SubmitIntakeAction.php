<?php

namespace App\Actions\Itp;

use App\Enums\ItpStatus;
use App\Jobs\GenerateItpJob;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use Illuminate\Support\Facades\DB;
use Itp\Core\Models\User;

/**
 * Store an intake and queue its generation.
 */
class SubmitIntakeAction
{
    /**
     * @param  array<string, string|null>  $answers  Validated intake fields
     */
    public function execute(User $user, array $answers): ItpGeneration
    {
        $generation = DB::transaction(function () use ($user, $answers): ItpGeneration {
            $intake = ItpIntake::create(['user_id' => $user->id, ...$answers]);

            return ItpGeneration::create([
                'user_id' => $user->id,
                'intake_id' => $intake->id,
                'status' => ItpStatus::Queued,
            ]);
        });

        GenerateItpJob::dispatch($generation->id);

        return $generation;
    }
}
