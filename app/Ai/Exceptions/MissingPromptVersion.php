<?php

namespace App\Ai\Exceptions;

use App\Enums\ItpStage;
use RuntimeException;

/**
 * A stage ran with no active prompt version. Activate one in Studio, or run the PromptSeeder.
 */
class MissingPromptVersion extends RuntimeException
{
    public static function forStage(ItpStage $stage): self
    {
        return new self("No active prompt version for the [{$stage->value}] stage. Activate one in Studio.");
    }
}
