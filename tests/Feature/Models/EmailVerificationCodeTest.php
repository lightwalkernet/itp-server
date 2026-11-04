<?php

use App\Enums\CodeAudience;
use App\Models\EmailVerificationCode;

it('prunes expired and used-up codes and keeps usable ones', function () {
    EmailVerificationCode::issue(CodeAudience::App, 'expired@example.com');
    $this->travel(EmailVerificationCode::LIFETIME_MINUTES + 1)->minutes();

    EmailVerificationCode::issue(CodeAudience::App, 'used-up@example.com');
    foreach (range(1, EmailVerificationCode::MAX_ATTEMPTS) as $ignored) {
        EmailVerificationCode::recordFailedAttempt(CodeAudience::App, 'used-up@example.com');
    }

    EmailVerificationCode::issue(CodeAudience::App, 'usable@example.com');

    $this->artisan('model:prune', ['--model' => [EmailVerificationCode::class]])->assertSuccessful();

    expect(EmailVerificationCode::pluck('email')->all())->toBe(['usable@example.com']);
});
