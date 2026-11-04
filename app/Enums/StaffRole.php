<?php

namespace App\Enums;

/**
 * Studio roles (platform.md §9). The owner sees everything; an editor works on content and
 * generations, but not on Horizon, app users or pipeline config.
 */
enum StaffRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Editor => 'Editor',
        };
    }
}
