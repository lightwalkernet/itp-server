<?php

namespace App\Actions\Itp;

use App\Enums\ItpStatus;
use App\Exceptions\GenerationNotReady;
use App\Jobs\GenerateItpJob;
use App\Models\ItpGeneration;
use Illuminate\Support\Facades\DB;

/**
 * Store the answers to the open round and send the generation back to assessing, where the
 * extraction runs again with every answer so far.
 */
class SubmitFollowupsAction
{
    /**
     * Questions of the open round that get no answer here count as skipped.
     *
     * @param  list<array{followup_id: int, answer_text: ?string}>  $answers
     *
     * @throws GenerationNotReady unless the generation is awaiting follow-up answers
     */
    public function execute(ItpGeneration $generation, array $answers): ItpGeneration
    {
        $generation = DB::transaction(function () use ($generation, $answers): ItpGeneration {
            $generation = ItpGeneration::query()->lockForUpdate()->findOrFail($generation->id);

            GenerationNotReady::unlessStatus($generation, ItpStatus::AwaitingFollowup, 'This generation is not awaiting follow-up answers.');

            $answerTexts = collect($answers)->pluck('answer_text', 'followup_id');

            foreach ($generation->openFollowups() as $followup) {
                $followup->update([
                    'answer_text' => filled($answerTexts->get($followup->id)) ? $answerTexts->get($followup->id) : null,
                    'answered_at' => now(),
                ]);
            }

            $generation->update(['status' => ItpStatus::Assessing]);

            return $generation;
        });

        GenerateItpJob::dispatch($generation->id);

        return $generation;
    }
}
