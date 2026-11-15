<?php

use App\Actions\Account\DeleteAccountAction;
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
use Itp\Core\Models\Entry;
use Itp\Core\Models\Itp;
use Itp\Core\Models\User;
use Itp\Core\Models\UserSetting;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * A user with one of everything a deletion has to remove.
 */
function userWithEverything(string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $itp = Itp::factory()->forUser($user)->create();

    $user->createToken('iPhone');
    UserSetting::factory()->livingWith($itp)->create();
    Entry::factory()->forUser($user)->create();
    Entry::factory()->forUser($user)->evening()->create()->delete();

    $generation = ItpGeneration::factory()->forUser($user)->withAttunement()->create(['itp_id' => $itp->id]);
    $segment = $generation->attunement->segments()->first();
    $flag = ItpSegmentFlag::factory()->for($segment, 'segment')->create();
    ItpAttunementSegment::factory()->for($generation->attunement, 'attunement')->create(['order' => 1, 'version' => 2, 'proposed_by_flag_id' => $flag->id]);
    ItpCompanion::factory()->for($generation, 'generation')->create();
    ItpFollowup::factory()->for($generation, 'generation')->create();
    AiUsage::factory()->for($generation, 'generation')->create();

    EmailVerificationCode::issue(CodeAudience::App, $email);
    EmailVerificationCode::issue(CodeAudience::AccountDeletion, $email);

    return $user;
}

it('hard-deletes the user and everything that is theirs', function () {
    $user = userWithEverything('ann@example.com');

    app(DeleteAccountAction::class)->execute($user);

    expect(User::withTrashed()->count())->toBe(0)
        ->and(PersonalAccessToken::count())->toBe(0)
        ->and(UserSetting::withTrashed()->count())->toBe(0)
        ->and(Entry::withTrashed()->count())->toBe(0)
        ->and(Itp::withTrashed()->count())->toBe(0)
        ->and(ItpIntake::count())->toBe(0)
        ->and(ItpGeneration::count())->toBe(0)
        ->and(ItpAttunement::count())->toBe(0)
        ->and(ItpAttunementSegment::count())->toBe(0)
        ->and(ItpSegmentFlag::count())->toBe(0)
        ->and(ItpCompanion::count())->toBe(0)
        ->and(ItpFollowup::count())->toBe(0)
        ->and(EmailVerificationCode::count())->toBe(0);
});

it('keeps the AI usage rows for cost history, detached from the generation', function () {
    $user = userWithEverything('ann@example.com');
    $usage = AiUsage::sole();

    app(DeleteAccountAction::class)->execute($user);

    expect($usage->fresh())->not->toBeNull()
        ->and($usage->fresh()->generation_id)->toBeNull()
        ->and((float) $usage->fresh()->cost_usd)->toBe(0.014);
});

it('leaves other users and a staff code for the same email alone', function () {
    $user = userWithEverything('ann@example.com');
    $other = userWithEverything('bob@example.com');
    EmailVerificationCode::issue(CodeAudience::Studio, 'ann@example.com');

    app(DeleteAccountAction::class)->execute($user);

    expect($other->fresh())->not->toBeNull()
        ->and($other->tokens()->count())->toBe(1)
        ->and($other->settings()->count())->toBe(1)
        ->and($other->entries()->withTrashed()->count())->toBe(2)
        ->and($other->itps()->count())->toBe(1)
        ->and(ItpGeneration::sole()->user_id)->toBe($other->id)
        ->and(ItpAttunementSegment::count())->toBe(4)
        ->and(AiUsage::whereNotNull('generation_id')->count())->toBe(1)
        ->and(EmailVerificationCode::where('email', 'ann@example.com')->sole()->audience)->toBe(CodeAudience::Studio)
        ->and(EmailVerificationCode::where('email', 'bob@example.com')->count())->toBe(2);
});
