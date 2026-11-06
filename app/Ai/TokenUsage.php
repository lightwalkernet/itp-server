<?php

namespace App\Ai;

/**
 * Token counts for one call. `input` excludes cached tokens, as the API reports it.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheWrite = 0,
        public int $cacheRead = 0,
    ) {}
}
