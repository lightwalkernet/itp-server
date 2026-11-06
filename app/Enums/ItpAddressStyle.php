<?php

namespace App\Enums;

/**
 * How the attunement addresses the user: "Beloved {name}" or "Beloved one".
 */
enum ItpAddressStyle: string
{
    case BelovedName = 'beloved_name';
    case BelovedOne = 'beloved_one';
}
