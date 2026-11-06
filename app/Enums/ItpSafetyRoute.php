<?php

namespace App\Enums;

/**
 * The safety check's verdict on an intake. Sensitive intakes are held for a person (deferred);
 * blocked ones are refused.
 */
enum ItpSafetyRoute: string
{
    case Clear = 'clear';
    case Sensitive = 'sensitive';
    case Block = 'block';
}
