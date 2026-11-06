<?php

namespace App\Services\Itp;

/**
 * Small builders for the stages' structured-output schemas. Every object closes its properties
 * and requires all of them, as structured outputs expect.
 */
class JsonSchema
{
    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    public static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function string(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function list(array $items, string $description): array
    {
        return ['type' => 'array', 'items' => $items, 'description' => $description];
    }
}
