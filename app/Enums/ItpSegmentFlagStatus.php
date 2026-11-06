<?php

namespace App\Enums;

/**
 * A segment flag is open until its replacement segment exists.
 */
enum ItpSegmentFlagStatus: string
{
    case Open = 'open';
    case Regenerated = 'regenerated';
}
