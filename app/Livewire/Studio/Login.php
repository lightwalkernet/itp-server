<?php

namespace App\Livewire\Studio;

use App\Enums\CodeAudience;
use App\Http\Requests\Api\Auth\SendCodeRequest;
use App\Mail\OtpCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Studio sign-in: the same email OTP flow as the app, against the `staff` table and the
 * `studio` guard (platform.md §9). Codes are issued for the Studio audience only, so an app
 * sign-in code can never open Studio.
 */
#[Layout('layouts.studio')]
#[Title('Sign in')]
class Login extends Component
{
    public string $email = '';

    public string $code = '';

    public bool $codeSent = false;

    /**
     * Mail a code if the email belongs to staff. The screen moves on either way, so the form
     * never tells anyone who is staff.
     *
     * @throws ValidationException
     */
    public function sendCode(): void
    {
        $this->email = SendCodeRequest::normalizeEmail($this->email);

        $this->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        $this->throttle('email', [
            'studio-send-code:email-minute:'.$this->email => [1, 60],
            'studio-send-code:email-hour:'.$this->email => [10, 3600],
            'studio-send-code:ip:'.request()->ip() => [60, 3600],
        ]);

        if (Staff::query()->where('email', $this->email)->exists()) {
            Mail::to($this->email)->send(new OtpCodeMail(
                EmailVerificationCode::issue(CodeAudience::Studio, $this->email),
            ));
        }

        $this->reset('code');
        $this->codeSent = true;
    }

    /**
     * @throws ValidationException
     */
    public function verifyCode(): void
    {
        $this->email = SendCodeRequest::normalizeEmail($this->email);

        $this->validate(['code' => ['required', 'string', 'digits:6']]);

        $this->throttle('code', [
            'studio-verify-code:email:'.$this->email => [10, 60],
            'studio-verify-code:ip:'.request()->ip() => [30, 60],
        ]);

        $staff = DB::transaction(fn (): ?Staff => EmailVerificationCode::consume(CodeAudience::Studio, $this->email, $this->code)
            ? Staff::query()->where('email', $this->email)->first()
            : null);

        if ($staff === null) {
            throw ValidationException::withMessages([
                'code' => EmailVerificationCode::recordFailedAttempt(CodeAudience::Studio, $this->email)->message(),
            ]);
        }

        Auth::guard('studio')->login($staff);
        session()->regenerate();

        $this->redirectIntended(route('studio.dashboard'));
    }

    public function useDifferentEmail(): void
    {
        $this->reset('code', 'codeSent');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.studio.login');
    }

    /**
     * Hit every limit, or refuse with a validation error on `$field` if any is used up.
     * Livewire actions don't pass through route middleware, so `throttle:` can't do this.
     *
     * @param  array<string, array{int, int}>  $limits  key => [max attempts, decay seconds]
     *
     * @throws ValidationException
     */
    protected function throttle(string $field, array $limits): void
    {
        foreach ($limits as $key => [$maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    $field => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
                ]);
            }
        }

        foreach ($limits as $key => [, $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }
}
