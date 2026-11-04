<?php

namespace App\Enums;

/**
 * Why a sign-in code was refused. Every case is the same for known and unknown emails.
 */
enum VerificationCodeFailure
{
    /** No usable code is pending for the email: none was sent, or every one has expired. */
    case Expired;

    /** The code matches no pending code, and at least one pending code has tries left. */
    case Incorrect;

    /** This wrong try used up the last pending code's tries. */
    case TooManyAttempts;

    public function message(): string
    {
        return match ($this) {
            self::Expired => 'That code has expired. Request a new one.',
            self::Incorrect => 'That code is incorrect.',
            self::TooManyAttempts => 'Too many incorrect tries. Request a new code.',
        };
    }
}
