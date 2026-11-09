<?php

use App\Enums\CodeAudience;
use App\Livewire\Studio\Login;
use App\Mail\OtpCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\Staff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Mail;
use Itp\Core\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the sign-in page on the Studio domain only', function () {
    $this->get(route('studio.login'))->assertOk()->assertSeeLivewire(Login::class);

    $this->get('http://localhost/login')->assertNotFound();
    $this->get('http://'.config('itp.api_domain').'/login')->assertNotFound();
});

it('sends guests to the sign-in page', function () {
    $this->get(route('studio.dashboard'))->assertRedirect(route('studio.login'));
});

it('sends signed-in staff past the sign-in page', function () {
    $this->actingAs(Staff::factory()->create(), 'studio')
        ->get(route('studio.login'))
        ->assertRedirect(route('studio.dashboard'));
});

it('mails a Studio code to a staff member', function () {
    Mail::fake();
    Staff::factory()->create(['email' => 'ann@example.com']);

    Livewire::test(Login::class)
        ->set('email', ' Ann@Example.com ')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true);

    Mail::assertQueued(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('ann@example.com'));
    expect(EmailVerificationCode::sole()->audience)->toBe(CodeAudience::Studio);
});

it('moves on without mailing anything for an email that is not staff', function (bool $isAppUser) {
    Mail::fake();
    if ($isAppUser) {
        User::factory()->create(['email' => 'ann@example.com']);
    }

    Livewire::test(Login::class)
        ->set('email', 'ann@example.com')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('codeSent', true);

    Mail::assertNothingQueued();
    expect(EmailVerificationCode::count())->toBe(0);
})->with([
    'unknown email' => [false],
    'app user who is not staff' => [true],
]);

it('signs staff in with a valid code', function () {
    $staff = Staff::factory()->create(['email' => 'ann@example.com']);
    $code = EmailVerificationCode::issue(CodeAudience::Studio, 'ann@example.com');

    Livewire::test(Login::class)
        ->set('email', 'ann@example.com')
        ->set('codeSent', true)
        ->set('code', $code)
        ->call('verifyCode')
        ->assertHasNoErrors()
        ->assertRedirect(route('studio.dashboard'));

    $this->assertAuthenticatedAs($staff, 'studio');
    $this->assertGuest('web');
});

it('returns to the page the staff member first asked for', function () {
    Staff::factory()->owner()->create(['email' => 'ann@example.com']);
    $code = EmailVerificationCode::issue(CodeAudience::Studio, 'ann@example.com');
    session()->put('url.intended', route('horizon.index'));

    Livewire::test(Login::class)
        ->set('email', 'ann@example.com')
        ->set('code', $code)
        ->call('verifyCode')
        ->assertRedirect(route('horizon.index'));
});

it('rejects a wrong code', function () {
    Staff::factory()->create(['email' => 'ann@example.com']);
    $code = EmailVerificationCode::issue(CodeAudience::Studio, 'ann@example.com');

    Livewire::test(Login::class)
        ->set('email', 'ann@example.com')
        ->set('codeSent', true)
        ->set('code', $code === '000000' ? '111111' : '000000')
        ->call('verifyCode')
        ->assertHasErrors('code')
        ->assertSee('That code is incorrect.');

    $this->assertGuest('studio');
});

it('refuses a code issued for the app', function () {
    Staff::factory()->create(['email' => 'ann@example.com']);
    $appCode = EmailVerificationCode::issue(CodeAudience::App, 'ann@example.com');

    Livewire::test(Login::class)
        ->set('email', 'ann@example.com')
        ->set('codeSent', true)
        ->set('code', $appCode)
        ->call('verifyCode')
        ->assertHasErrors('code')
        ->assertSee('That code has expired. Request a new one.');

    $this->assertGuest('studio');
    expect(EmailVerificationCode::sole()->attempts)->toBe(0);
});

it('refuses a second code to the same email within a minute', function () {
    Mail::fake();
    $this->freezeTime();
    Staff::factory()->create(['email' => 'ann@example.com']);

    Livewire::test(Login::class)->set('email', 'ann@example.com')->call('sendCode')->assertHasNoErrors();

    $component = Livewire::test(Login::class)->set('email', 'ANN@example.com')->call('sendCode');

    expect($component->errors()->first('email'))->toBe('Too many attempts. Try again in 60 seconds.')
        ->and($component->get('codeSent'))->toBeFalse();
    Mail::assertQueuedCount(1);
});

it('refuses an 11th code attempt for the same email within a minute', function () {
    Staff::factory()->create(['email' => 'ann@example.com']);
    EmailVerificationCode::issue(CodeAudience::Studio, 'ann@example.com');
    $component = Livewire::test(Login::class)->set('email', 'ann@example.com')->set('code', '123456');

    foreach (range(1, 10) as $ignored) {
        $component->call('verifyCode');
        expect($component->errors()->first('code'))->not->toStartWith('Too many attempts.');
    }

    $component->call('verifyCode');

    expect($component->errors()->first('code'))->toStartWith('Too many attempts.');
});

it('goes back to the email step', function () {
    Livewire::test(Login::class)
        ->set('email', 'ann@example.com')
        ->set('codeSent', true)
        ->set('code', '123')
        ->call('useDifferentEmail')
        ->assertSet('codeSent', false)
        ->assertSet('code', '');
});

/**
 * Finish a Studio sign-in with the box ticked or not, returning the name of the remember cookie.
 */
function signInToStudio(Staff $staff, bool $keepSignedIn): string
{
    Livewire::test(Login::class)
        ->set('email', $staff->email)
        ->set('codeSent', true)
        ->set('code', EmailVerificationCode::issue(CodeAudience::Studio, $staff->email))
        ->set('keepSignedIn', $keepSignedIn)
        ->call('verifyCode')
        ->assertHasNoErrors();

    return Auth::guard('studio')->getRecallerName();
}

it('keeps staff signed in for 90 days when the box is ticked', function () {
    $staff = Staff::factory()->create();

    $cookie = Cookie::queued(signInToStudio($staff, keepSignedIn: true));

    expect($staff->fresh()->getRememberToken())->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBeGreaterThan(now()->addDays(89)->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(90)->addMinute()->getTimestamp());
});

it('does not keep staff signed in unless the box is ticked', function () {
    $name = signInToStudio(Staff::factory()->create(), keepSignedIn: false);

    expect(Cookie::hasQueued($name))->toBeFalse();
});

it('signs staff back in from the remember cookie when the session has gone', function () {
    $staff = Staff::factory()->create();
    $cookie = Cookie::queued(signInToStudio($staff, keepSignedIn: true));
    Auth::guard('studio')->forgetUser();
    session()->flush();
    $this->app['auth']->forgetGuards();

    $this->withCookie($cookie->getName(), $cookie->getValue())
        ->get(route('studio.dashboard'))
        ->assertOk();

    $this->assertAuthenticatedAs($staff, 'studio');
});

it('stops remembering staff on sign-out', function () {
    $staff = Staff::factory()->create();
    $cookie = Cookie::queued(signInToStudio($staff, keepSignedIn: true));
    $rememberToken = $staff->fresh()->getRememberToken();
    Auth::guard('studio')->forgetUser();
    session()->flush();
    $this->app['auth']->forgetGuards();

    $this->withCookie($cookie->getName(), $cookie->getValue())
        ->post(route('studio.logout'))
        ->assertCookieExpired($cookie->getName());

    expect($staff->fresh()->getRememberToken())->not->toBe($rememberToken);
});
