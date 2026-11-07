<?php

namespace App\Enums;

/**
 * A flag is open while its section is being rewritten, and regenerated once the new lines are
 * written (then waiting for the user's choice, `ItpSegmentFlag::$choice`).
 */
enum ItpSegmentFlagStatus: string
{
    case Open = 'open';
    case Regenerated = 'regenerated';
}
