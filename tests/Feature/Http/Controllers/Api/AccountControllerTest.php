<?php

use App\Enums\CodeAudience;
use App\Mail\OtpCodeMail;
use App\Models\EmailVerificationCode;
use Illuminate\Support\Facades\Mail;
use Itp\Core\Models\Entry;
use Itp\Core\Models\User;

beforeEach(function () {
    $this->withHeader('X-App-Version', '1.0.0');
});

describe('deletion-code', function () {
    it('mails a deletion code to an existing user', function () {
        Mail::fake();
        User::factory()->create(['email' => 'ann@example.com']);

        $this->postJson(route('api.account.deletion-code'), ['email' => ' Ann@Example.com'])
            ->assertOk()
            ->assertExactJson([
                'message' => 'If that email has an account, we sent it a code to delete the account.',
                'data' => [],
                'errors' => [],
            ]);

        Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('ann@example.com')
            && $mail->audience === CodeAudience::AccountDeletion);
        expect(EmailVerificationCode::sole()->audience)->toBe(CodeAudience::AccountDeletion);
    });

    it('mails nothing for an unknown email and gives the same response', function () {
        Mail::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson(route('api.account.deletion-code'), ['email' => 'known@example.com']);
        $unknown = $this->postJson(route('api.account.deletion-code'), ['email' => 'unknown@example.com']);

        expect($unknown->status())->toBe($known->status())
            ->and($unknown->json())->toBe($known->json())
            ->and(EmailVerificationCode::where('email', 'unknown@example.com')->exists())->toBeFalse();
        Mail::assertQueuedCount(1);
    });

    it('returns 422 when the email is missing', function () {
        $this->postJson(route('api.account.deletion-code'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email', ['The email field is required.']);
    });

    it('returns 429 for a second code to the same email within a minute, counted apart from sign-in codes', function () {
        Mail::fake();
        User::factory()->create(['email' => 'ann@example.com']);

        $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])->assertOk();
        $this->postJson(route('api.account.deletion-code'), ['email' => 'ann@example.com'])->assertOk();

        $this->postJson(route('api.account.deletion-code'), ['email' => 'ann@example.com'])
            ->assertTooManyRequests()
            ->assertExactJson(['message' => 'Too Many Attempts.', 'data' => [], 'errors' => []]);
    });

    it('returns 426 below the minimum app version', function () {
        config(['itp.min_app_version' => '2.0.0']);

        $this->postJson(route('api.account.deletion-code'), ['email' => 'ann@example.com'])->assertStatus(426);
    });
});

describe('delete', function () {
    it('deletes the account and revokes its tokens', function () {
        $user = User::factory()->create(['email' => 'ann@example.com']);
        Entry::factory()->forUser($user)->create();
        $token = $user->createToken('iPhone')->plainTextToken;
        $code = EmailVerificationCode::issue(CodeAudience::AccountDeletion, 'ann@example.com');

        $this->withToken($token)->getJson(route('api.generations.index'))->assertOk();
        app('auth')->forgetGuards();

        $this->withoutToken()
            ->postJson(route('api.account.delete'), ['email' => 'ANN@example.com', 'code' => $code])
            ->assertOk()
            ->assertExactJson(['message' => 'Your account is deleted.', 'data' => [], 'errors' => []]);

        expect(User::withTrashed()->count())->toBe(0)
            ->and(Entry::withTrashed()->count())->toBe(0);

        $this->withToken($token)->getJson(route('api.generations.index'))->assertUnauthorized();
    });

    it('returns 422 for a wrong code, counts the attempt and deletes nothing', function () {
        $user = User::factory()->create(['email' => 'ann@example.com']);
        $code = EmailVerificationCode::issue(CodeAudience::AccountDeletion, 'ann@example.com');

        $this->postJson(route('api.account.delete'), [
            'email' => 'ann@example.com',
            'code' => $code === '000000' ? '111111' : '000000',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'That code is incorrect.',
                'data' => [],
                'errors' => ['code' => ['That code is incorrect.']],
            ]);

        expect(EmailVerificationCode::sole()->attempts)->toBe(1)
            ->and($user->fresh())->not->toBeNull();
    });

    it('kills a deletion code after 5 wrong tries', function () {
        User::factory()->create(['email' => 'ann@example.com']);
        $code = EmailVerificationCode::issue(CodeAudience::AccountDeletion, 'ann@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, EmailVerificationCode::MAX_ATTEMPTS - 1) as $ignored) {
            $this->postJson(route('api.account.delete'), ['email' => 'ann@example.com', 'code' => $wrong])
                ->assertJsonPath('errors.code', ['That code is incorrect.']);
        }

        $this->postJson(route('api.account.delete'), ['email' => 'ann@example.com', 'code' => $wrong])
            ->assertJsonPath('errors.code', ['Too many incorrect tries. Request a new code.']);

        $this->postJson(route('api.account.delete'), ['email' => 'ann@example.com', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);
        expect(User::count())->toBe(1);
    });

    it('returns 422 for a sign-in code and deletes nothing', function () {
        User::factory()->create(['email' => 'ann@example.com']);
        $signInCode = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.account.delete'), ['email' => 'ann@example.com', 'code' => $signInCode])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);

        expect(User::count())->toBe(1);
    });

    it('refuses a deletion code at sign-in', function () {
        User::factory()->create(['email' => 'ann@example.com']);
        $deletionCode = EmailVerificationCode::issue(CodeAudience::AccountDeletion, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $deletionCode,
            'device_name' => 'iPhone',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);

        expect(EmailVerificationCode::where('audience', CodeAudience::AccountDeletion)->exists())->toBeTrue();
    });

    it('returns 422 when the code is spent a second time', function () {
        User::factory()->create(['email' => 'ann@example.com']);
        $code = EmailVerificationCode::issue(CodeAudience::AccountDeletion, 'ann@example.com');
        $payload = ['email' => 'ann@example.com', 'code' => $code];

        $this->postJson(route('api.account.delete'), $payload)->assertOk();

        $this->postJson(route('api.account.delete'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);
    });

    it('returns 422 when the code is malformed', function () {
        $this->postJson(route('api.account.delete'), ['email' => 'ann@example.com', 'code' => '12345'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['The code field must be 6 digits.']);
    });

    it('returns 429 after 10 tries for the same email in a minute', function () {
        foreach (range(1, 10) as $ignored) {
            $this->postJson(route('api.account.delete'), ['email' => 'ann@example.com', 'code' => '123456'])
                ->assertUnprocessable();
        }

        $this->postJson(route('api.account.delete'), ['email' => 'Ann@example.com', 'code' => '123456'])
            ->assertTooManyRequests();

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => '123456',
            'device_name' => 'iPhone',
        ])->assertUnprocessable();
    });
});
