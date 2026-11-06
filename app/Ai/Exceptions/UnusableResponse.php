<?php

namespace App\Ai\Exceptions;

use App\Enums\ItpStage;
use RuntimeException;

/**
 * The model answered, but not with something a stage can use.
 */
class UnusableResponse extends RuntimeException
{
    public static function refused(ItpStage $stage): self
    {
        return new self("The model refused the [{$stage->value}] stage.");
    }

    public static function truncated(ItpStage $stage): self
    {
        return new self("The [{$stage->value}] stage hit its max_tokens before finishing.");
    }

    public static function invalidJson(ItpStage $stage): self
    {
        return new self("The [{$stage->value}] stage did not return a JSON object.");
    }

    public static function missing(ItpStage $stage, string $what): self
    {
        return new self("The [{$stage->value}] stage returned no {$what}.");
    }
}
