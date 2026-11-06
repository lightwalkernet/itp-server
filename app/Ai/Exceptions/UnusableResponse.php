<?php

namespace App\Ai\Exceptions;

use App\Enums\ItpStage;
use RuntimeException;

/**
 * The model answered, but not with something a stage can use. Always a permanent failure: the
 * same prompt would only fail again, and each try is billed. The raw output, when there is one,
 * is part of the message, so it shows in the Studio inspector (`last_error`).
 */
class UnusableResponse extends RuntimeException
{
    /**
     * How much of the raw output the message keeps.
     */
    public const int RAW_OUTPUT_LIMIT = 3000;

    public static function refused(ItpStage $stage): self
    {
        return new self("The model refused the [{$stage->value}] stage.");
    }

    public static function truncated(ItpStage $stage): self
    {
        return new self("The [{$stage->value}] stage hit its max_tokens before finishing.");
    }

    public static function invalidJson(ItpStage $stage, ?string $rawOutput = null): self
    {
        return new self("The [{$stage->value}] stage did not return a JSON object.".self::rawOutput($rawOutput));
    }

    /**
     * The JSON parsed but does not match the stage's schema or rules.
     */
    public static function invalid(ItpStage $stage, string $problem, string|array|null $rawOutput = null): self
    {
        return new self("The [{$stage->value}] stage returned an unusable response: {$problem}.".self::rawOutput($rawOutput));
    }

    public static function missing(ItpStage $stage, string $what, string|array|null $rawOutput = null): self
    {
        return new self("The [{$stage->value}] stage returned no {$what}.".self::rawOutput($rawOutput));
    }

    /**
     * @param  string|array<array-key, mixed>|null  $rawOutput
     */
    protected static function rawOutput(string|array|null $rawOutput): string
    {
        if ($rawOutput === null) {
            return '';
        }

        if (is_array($rawOutput)) {
            $rawOutput = (string) json_encode($rawOutput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        $kept = mb_substr($rawOutput, 0, self::RAW_OUTPUT_LIMIT);

        return "\n\nRaw output".(mb_strlen($rawOutput) > self::RAW_OUTPUT_LIMIT ? ' (truncated)' : '').":\n{$kept}";
    }
}
