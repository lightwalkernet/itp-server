<?php

namespace App\Exceptions;

use App\Enums\ItpStatus;
use App\Models\ItpGeneration;
use RuntimeException;

/**
 * A user action arrived while the generation was in a status that doesn't accept it. The API
 * answers 422 with this message.
 */
class GenerationNotReady extends RuntimeException
{
    public static function unlessStatus(ItpGeneration $generation, ItpStatus $expected, string $message): void
    {
        if ($generation->status !== $expected) {
            throw new self($message);
        }
    }
}
