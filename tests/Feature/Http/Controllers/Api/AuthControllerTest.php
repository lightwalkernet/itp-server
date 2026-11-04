<?php

use App\Enums\CodeAudience;
use App\Mail\OtpCodeMail;
use App\Models\EmailVerificationCode;
use Illuminate\Support\Facades\Mail;
use Itp\Core\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(function () {
    $this->withHeader('X-App-Version', '1.0.0');
});

it('returns 404 for API paths on a host other than the API domain', function () {
    $this->postJson('http://localhost/v1/auth/send-code', ['email' => 'ann@example.com'])
        ->assertNotFound();
});

describe('send-code', function () {
    it('queues a code mail and stores only a digest of the code', function () {
        Mail::fake();

        $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])
            ->assertOk()
            ->assertExactJson([
                'message' => 'We sent a sign-in code to that email.',
                'data' => [],
                'errors' => [],
            ]);

        $sent = null;
        Mail::assertQueued(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$sent) {
            $sent = $mail->code;

            return $mail->hasTo('ann@example.com');
        });

        expect($sent)->toMatch('/\A\d{6}\z/');
        $this->assertDatabaseHas('email_verification_codes', [
            'email' => 'ann@example.com',
            'code' => EmailVerificationCode::digest(CodeAudience::App, 'ann@example.com', $sent),
        ]);
        $this->assertDatabaseMissing('email_verification_codes', ['code' => $sent]);
    });

    it('gives the same response for known and unknown emails', function () {
        Mail::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson(route('api.auth.send-code'), ['email' => 'known@example.com']);
        $unknown = $this->postJson(route('api.auth.send-code'), ['email' => 'unknown@example.com']);

        expect($unknown->status())->toBe($known->status())
            ->and($unknown->json())->toBe($known->json());
    });

    it('lowercases and trims the email', function () {
        Mail::fake();

        $this->postJson(route('api.auth.send-code'), ['email' => '  Ann@Example.COM '])->assertOk();

        Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('ann@example.com'));
        $this->assertDatabaseHas('email_verification_codes', ['email' => 'ann@example.com']);
    });

    it('keeps an earlier pending code usable when a new one is sent', function () {
        Mail::fake();
        $earlier = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])->assertOk();

        expect(EmailVerificationCode::where('email', 'ann@example.com')->count())->toBe(2)
            ->and(EmailVerificationCode::consume(CodeAudience::App, 'ann@example.com', $earlier))->toBeTrue();
    });

    it('returns 422 when the email is missing or invalid', function (array $payload, string $message) {
        Mail::fake();

        $this->postJson(route('api.auth.send-code'), $payload)
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => $message,
                'data' => [],
                'errors' => ['email' => [$message]],
            ]);

        Mail::assertNothingQueued();
    })->with([
        'missing' => [[], 'The email field is required.'],
        'not an email' => [['email' => 'not-an-email'], 'The email field must be a valid email address.'],
    ]);

    it('returns 429 for a second code to the same email within a minute', function () {
        Mail::fake();

        $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])->assertOk();

        $this->postJson(route('api.auth.send-code'), ['email' => 'ANN@example.com'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertExactJson(['message' => 'Too Many Attempts.', 'data' => [], 'errors' => []]);

        $this->postJson(route('api.auth.send-code'), ['email' => 'bob@example.com'])->assertOk();

        $this->travel(61)->seconds();

        $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])->assertOk();
        Mail::assertQueuedCount(3);
    });

    it('returns 429 after 10 codes to the same email in an hour', function () {
        Mail::fake();

        foreach (range(1, 10) as $ignored) {
            $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])->assertOk();
            $this->travel(61)->seconds();
        }

        $this->postJson(route('api.auth.send-code'), ['email' => 'ann@example.com'])->assertTooManyRequests();
    });

    it('returns 429 after 60 codes from the same IP in an hour', function () {
        Mail::fake();

        foreach (range(1, 60) as $number) {
            $this->postJson(route('api.auth.send-code'), ['email' => "user{$number}@example.com"])->assertOk();
        }

        $this->postJson(route('api.auth.send-code'), ['email' => 'one-more@example.com'])->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(route('api.auth.send-code'), ['email' => 'one-more@example.com'])
            ->assertOk();
    });
});

describe('verify-code', function () {
    it('creates the account on first sign-in and returns a working token', function () {
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $response = $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $code,
            'device_name' => 'Ann’s iPhone',
        ]);

        $user = User::sole();
        $response->assertOk()
            ->assertJsonPath('message', 'Signed in.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'ann@example.com')
            ->assertJsonPath('data.needs_profile', true)
            ->assertJsonPath('errors', []);

        expect($user->email_verified_at)->not->toBeNull()
            ->and(PersonalAccessToken::findToken($response->json('data.token'))->tokenable->is($user))->toBeTrue()
            ->and($user->tokens()->sole()->name)->toBe('Ann’s iPhone');
    });

    it('returns needs_profile from the first name on every sign-in', function (?string $firstName, bool $needsProfile) {
        User::factory()->create(['email' => 'ann@example.com', 'first_name' => $firstName]);
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $code,
            'device_name' => 'iPhone',
        ])
            ->assertOk()
            ->assertJsonPath('data.needs_profile', $needsProfile);

        expect(User::count())->toBe(1);
    })->with([
        'returning user with a first name' => ['Ann', false],
        'returning user without a first name' => [null, true],
    ]);

    it('matches the email case-insensitively', function () {
        $user = User::factory()->create(['email' => 'ann@example.com']);
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => ' ANN@Example.com',
            'code' => $code,
            'device_name' => 'iPhone',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    });

    it('accepts any pending code, not only the newest', function () {
        $earlier = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');
        EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $earlier,
            'device_name' => 'iPhone',
        ])->assertOk();
    });

    it('returns 422 for a wrong code and creates nothing', function () {
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $code === '000000' ? '111111' : '000000',
            'device_name' => 'iPhone',
        ])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'That code is incorrect.',
                'data' => [],
                'errors' => ['code' => ['That code is incorrect.']],
            ]);

        expect(User::count())->toBe(0)
            ->and(PersonalAccessToken::count())->toBe(0);
    });

    it('returns 422 for a code issued for Studio', function () {
        $studioCode = EmailVerificationCode::issue(CodeAudience::Studio, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $studioCode,
            'device_name' => 'iPhone',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);

        expect(User::count())->toBe(0);
    });

    it('returns 422 for an expired code', function () {
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->travel(EmailVerificationCode::LIFETIME_MINUTES)->minutes();

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $code,
            'device_name' => 'iPhone',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);

        expect(User::count())->toBe(0);
    });

    it('kills a code after 5 wrong tries', function () {
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');
        $wrong = $code === '000000' ? '111111' : '000000';
        $attempt = fn (string $guess) => $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $guess,
            'device_name' => 'iPhone',
        ]);

        foreach (range(1, EmailVerificationCode::MAX_ATTEMPTS - 1) as $ignored) {
            $attempt($wrong)->assertJsonPath('errors.code', ['That code is incorrect.']);
        }

        $attempt($wrong)->assertJsonPath('errors.code', ['Too many incorrect tries. Request a new code.']);

        $attempt($code)
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);
        expect(User::count())->toBe(0);
    });

    it('lets a code be used only once', function () {
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');
        $payload = ['email' => 'ann@example.com', 'code' => $code, 'device_name' => 'iPhone'];

        $this->postJson(route('api.auth.verify-code'), $payload)->assertOk();

        $this->postJson(route('api.auth.verify-code'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.code', ['That code has expired. Request a new one.']);

        expect(PersonalAccessToken::count())->toBe(1);
    });

    it('keeps other tokens, even with the same device name', function () {
        $user = User::factory()->create(['email' => 'ann@example.com']);
        $existing = $user->createToken('iPhone')->accessToken;
        $code = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'ann@example.com',
            'code' => $code,
            'device_name' => 'iPhone',
        ])->assertOk();

        expect($user->tokens()->count())->toBe(2)
            ->and($existing->fresh())->not->toBeNull();
    });

    it('returns 422 when a field is missing or malformed', function (array $payload, string $field, string $message) {
        $this->postJson(route('api.auth.verify-code'), $payload + [
            'email' => 'ann@example.com',
            'code' => '123456',
            'device_name' => 'iPhone',
        ])
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}", [$message]);
    })->with([
        'code not digits' => [['code' => 'abcdef'], 'code', 'The code field must be 6 digits.'],
        'code too short' => [['code' => '12345'], 'code', 'The code field must be 6 digits.'],
        'no device name' => [['device_name' => null], 'device_name', 'The device name field is required.'],
    ]);

    it('returns 429 after 10 tries for the same email in a minute', function () {
        foreach (range(1, 10) as $ignored) {
            $this->postJson(route('api.auth.verify-code'), [
                'email' => 'ann@example.com',
                'code' => '123456',
                'device_name' => 'iPhone',
            ])->assertUnprocessable();
        }

        $this->postJson(route('api.auth.verify-code'), [
            'email' => 'Ann@example.com',
            'code' => '123456',
            'device_name' => 'iPhone',
        ])->assertTooManyRequests();
    });
});

describe('complete-profile', function () {
    it('sets the first name', function () {
        $user = User::factory()->withoutFirstName()->create();
        $token = $user->createToken('iPhone')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.auth.complete-profile'), ['first_name' => 'Ann'])
            ->assertOk()
            ->assertJsonPath('data.user.first_name', 'Ann')
            ->assertJsonPath('data.needs_profile', false);

        expect($user->fresh()->first_name)->toBe('Ann');
    });

    it('returns 422 without a first name', function () {
        $token = User::factory()->withoutFirstName()->create()->createToken('iPhone')->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.auth.complete-profile'), [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.first_name', ['The first name field is required.']);
    });

    it('returns 401 without a token', function () {
        $this->postJson(route('api.auth.complete-profile'), ['first_name' => 'Ann'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.', 'data' => [], 'errors' => []]);
    });
});

describe('logout', function () {
    it('revokes only the token it was called with', function () {
        $user = User::factory()->create();
        $phone = $user->createToken('iPhone');
        $laptop = $user->createToken('Laptop');

        $this->withToken($phone->plainTextToken)
            ->postJson(route('api.auth.logout'))
            ->assertOk()
            ->assertJsonPath('message', 'Signed out.');

        expect($user->tokens()->pluck('id')->all())->toBe([$laptop->accessToken->id]);
    });

    it('returns 401 without a token', function () {
        $this->postJson(route('api.auth.logout'))->assertUnauthorized();
    });

    it('returns 401 in JSON even when the request does not ask for JSON', function () {
        $this->post(route('api.auth.logout'))
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.', 'data' => [], 'errors' => []]);
    });
});
