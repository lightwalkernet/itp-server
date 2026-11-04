<?php

namespace App\Enums;

/**
 * Which sign-in a code was issued for. App users and staff are separate accounts that can share
 * an email, so a code issued for one can never be spent on the other.
 */
enum CodeAudience: string
{
    case App = 'app';
    case Studio = 'studio';
}
