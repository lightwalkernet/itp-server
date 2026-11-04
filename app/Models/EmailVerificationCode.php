<?php

namespace App\Models;

use App\Enums\CodeAudience;
use App\Enums\VerificationCodeFailure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A six-digit sign-in code. Only an HMAC of the code is stored.
 *
 * Several codes can be pending for one email: sending a new one never cancels the last.
 * A code is usable until it expires or has been guessed wrongly MAX_ATTEMPTS times, and only
 * for the audience (app or Studio) it was issued for.
 */
#[Fillable(['audience', 'email', 'code', 'expires_at'])]
class EmailVerificationCode extends Model
{
    use MassPrunable;

    public const LIFETIME_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const UPDATED_AT = null;

    /**
     * Create a code for the email and return it in plain text, for the mail only.
     */
    public static function issue(CodeAudience $audience, string $email): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        static::create([
            'audience' => $audience,
            'email' => $email,
            'code' => static::digest($audience, $email, $code),
            'expires_at' => now()->addMinutes(static::LIFETIME_MINUTES),
        ]);

        return $code;
    }

    /**
     * Spend a usable code. The conditional delete is atomic, so of two requests racing with the
     * same code, only one gets `true`.
     */
    public static function consume(CodeAudience $audience, string $email, string $code): bool
    {
        return static::query()
            ->usableFor($audience, $email)
            ->where('code', static::digest($audience, $email, $code))
            ->delete() > 0;
    }

    /**
     * Count a wrong try against every usable code for the email, and say why the code was refused.
     */
    public static function recordFailedAttempt(CodeAudience $audience, string $email): VerificationCodeFailure
    {
        $counted = static::query()->usableFor($audience, $email)->increment('attempts');

        return match (true) {
            $counted === 0 => VerificationCodeFailure::Expired,
            static::query()->usableFor($audience, $email)->doesntExist() => VerificationCodeFailure::TooManyAttempts,
            default => VerificationCodeFailure::Incorrect,
        };
    }

    /**
     * Keyed with the app key and bound to the audience and email, so a leaked table can't be
     * checked offline and a code can't cross from one sign-in to the other.
     */
    public static function digest(CodeAudience $audience, string $email, string $code): string
    {
        return hash_hmac('sha256', $audience->value.'|'.$email.'|'.$code, config('app.key'));
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usableFor(Builder $query, CodeAudience $audience, string $email): void
    {
        $query->where('audience', $audience)
            ->where('email', $email)
            ->where('expires_at', '>', now())
            ->where('attempts', '<', static::MAX_ATTEMPTS);
    }

    /**
     * Expired and used-up codes are never usable again.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('expires_at', '<=', now())
            ->orWhere('attempts', '>=', static::MAX_ATTEMPTS);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => CodeAudience::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
