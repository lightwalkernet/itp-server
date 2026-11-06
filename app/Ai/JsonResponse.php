<?php

namespace App\Ai;

/**
 * Turns a model's text answer into a JSON object. Structured outputs should never wrap the JSON
 * in a Markdown code fence, but a stray fence is stripped before decoding, defensively.
 */
final class JsonResponse
{
    /**
     * The decoded object, or null when the text is not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $text = self::stripCodeFence($text);

        if (! str_starts_with($text, '{')) {
            return null;
        }

        $data = json_decode($text, true);

        return is_array($data) ? $data : null;
    }

    public static function stripCodeFence(string $text): string
    {
        $text = trim($text);

        if (preg_match('/\A```[A-Za-z0-9_-]*[ \t]*\R(.*?)\R?```\z/s', $text, $matches) === 1) {
            return trim($matches[1]);
        }

        return $text;
    }
}
