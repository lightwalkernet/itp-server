<?php

namespace App\Actions\Account;

use App\Enums\CodeAudience;
use App\Models\AiUsage;
use App\Models\EmailVerificationCode;
use App\Models\ItpAttunement;
use App\Models\ItpAttunementSegment;
use App\Models\ItpCompanion;
use App\Models\ItpFollowup;
use App\Models\ItpGeneration;
use App\Models\ItpIntake;
use App\Models\ItpSegmentFlag;
use Illuminate\Support\Facades\DB;
use Itp\Core\Models\User;

/**
 * Hard-delete an app user and everything that is theirs, in one transaction (platform.md §5).
 *
 * Synced rows are force-deleted through their models, so no tombstone is left: a tombstone could
 * never reach a deleted user anyway. The phone learns of the deletion from the 401 on its next
 * call, and wipes its copy. AI usage rows stay for cost history, detached from the generation.
 */
class DeleteAccountAction
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            $user->entries()->withTrashed()->get()->each->forceDelete();
            $user->settings()->withTrashed()->get()->each->forceDelete();

            $this->deleteGenerations($user);

            $user->itps()->withTrashed()->get()->each->forceDelete();

            EmailVerificationCode::query()
                ->where('email', $user->email)
                ->whereIn('audience', [CodeAudience::App, CodeAudience::AccountDeletion])
                ->delete();

            $user->forceDelete();
        });
    }

    /**
     * The pipeline rows, children first. Flags and segments point at each other with nullable
     * keys, so the flags go before the segments.
     */
    protected function deleteGenerations(User $user): void
    {
        $generationIds = ItpGeneration::query()->where('user_id', $user->id)->pluck('id');
        $attunementIds = ItpAttunement::query()->whereIn('generation_id', $generationIds)->pluck('id');

        AiUsage::query()->whereIn('generation_id', $generationIds)->update(['generation_id' => null]);

        ItpSegmentFlag::query()->whereIn('generation_id', $generationIds)->delete();
        ItpAttunementSegment::query()->whereIn('attunement_id', $attunementIds)->delete();
        ItpAttunement::query()->whereIn('id', $attunementIds)->delete();
        ItpCompanion::query()->whereIn('generation_id', $generationIds)->delete();
        ItpFollowup::query()->whereIn('generation_id', $generationIds)->delete();
        ItpGeneration::query()->whereIn('id', $generationIds)->delete();
        ItpIntake::query()->where('user_id', $user->id)->delete();
    }
}
