<?php

namespace App\Enums;

/**
 * The eight SHAPE steps of an attunement, in order. Every line belongs to one, and the user
 * reviews the attunement part by part. Studio shows the labels; the app shows each section as a
 * plain passage. Attunements written before prompt library v2 have no parts and count as one
 * section, `Opening` (`fallback()`).
 */
enum ItpAttunementPart: int
{
    case Opening = 1;
    case Breath = 2;
    case Essence = 3;
    case Honouring = 4;
    case Shift = 5;
    case Permission = 6;
    case Evidence = 7;
    case Threshold = 8;

    /**
     * The part a line without one (written before v2) counts as.
     */
    public static function fallback(): self
    {
        return self::Opening;
    }

    /**
     * The part number of a line, with a missing part counted as the fallback.
     */
    public static function numberOf(?int $part): int
    {
        return $part ?? self::fallback()->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening',
            self::Breath => 'Breath',
            self::Essence => 'Essence',
            self::Honouring => 'Honouring',
            self::Shift => 'The shift',
            self::Permission => 'Permission',
            self::Evidence => 'Evidence',
            self::Threshold => 'Threshold',
        };
    }
}
