<?php

namespace App\Enums;

/**
 * What the user kept after a section was rewritten: the new version or their original.
 */
enum ItpSectionChoice: string
{
    case New = 'new';
    case Original = 'original';
}
