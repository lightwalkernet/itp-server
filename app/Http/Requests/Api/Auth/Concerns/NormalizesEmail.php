<?php

namespace App\Http\Requests\Api\Auth\Concerns;

use Illuminate\Support\Str;

/**
 * Emails are stored and matched lowercased and trimmed, so `Ann@Example.com ` is the same account.
 */
trait NormalizesEmail
{
    /**
     * Also used by the auth rate limiters, which run before the form request.
     */
    public static function normalizeEmail(mixed $email): string
    {
        return is_string($email) ? Str::lower(trim($email)) : '';
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => static::normalizeEmail($this->input('email'))]);
        }
    }
}
