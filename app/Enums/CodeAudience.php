<?php

namespace App\Enums;

/**
 * What a code was issued for. App users and staff are separate accounts that can share an email,
 * and deleting an account takes its own code, so a code issued for one can never be spent on another.
 */
enum CodeAudience: string
{
    case App = 'app';
    case Studio = 'studio';
    case AccountDeletion = 'account_deletion';
}
