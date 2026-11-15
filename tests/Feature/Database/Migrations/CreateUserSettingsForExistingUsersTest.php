<?php

use Itp\Core\Models\User;
use Itp\Core\Models\UserSetting;

function backfillUserSettings(): void
{
    (require database_path('migrations/2026_11_16_200000_create_user_settings_for_existing_users.php'))->up();
}

it('gives every user without settings a stamped row with the defaults, and keeps existing rows', function () {
    $withoutSettings = User::factory()->count(3)->create();
    $existing = UserSetting::factory()->withoutReminders()->create();

    backfillUserSettings();

    expect(UserSetting::count())->toBe(4)
        ->and($existing->fresh()->morning_reminder)->toBeFalse();

    $withoutSettings->each(function (User $user) {
        $settings = $user->settings()->sole();

        expect($settings->morning_at)->toBe('07:00:00')
            ->and($settings->evening_reminder)->toBeTrue()
            ->and($settings->sync_seq)->toBeGreaterThan(0);
    });
});

it('adds nothing when run again', function () {
    User::factory()->count(2)->create();

    backfillUserSettings();
    backfillUserSettings();

    expect(UserSetting::count())->toBe(2);
});
