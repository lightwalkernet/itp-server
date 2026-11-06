<?php

namespace App\Services\Itp;

/**
 * Small builders for the stages' structured-output schemas, and a checker for the subset they
 * use. Every object closes its properties and requires all of them, as strict structured outputs
 * expect, so an empty value in the prompt library ("", []) is a required string or array here.
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
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    public static function enum(array $values, string $description): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function integer(string $description): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function number(string $description): array
    {
        return ['type' => 'number', 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function boolean(string $description): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function list(array $items, string $description): array
    {
        return ['type' => 'array', 'items' => $items, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stringList(string $description): array
    {
        return static::list(['type' => 'string'], $description);
    }

    /**
     * The first way `$value` breaks `$schema`, as a JSON path and a reason, or null when it
     * matches. Checks what the builders above produce: types, enums, required keys and closed
     * objects.
     *
     * @param  array<string, mixed>  $schema
     */
    public static function violation(array $schema, mixed $value, string $path = '$'): ?string
    {
        $type = $schema['type'] ?? null;

        $typeMatches = match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => true,
        };

        if (! $typeMatches) {
            return "{$path} must be a JSON {$type}";
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return "{$path} must be one of ".implode(', ', $schema['enum']);
        }

        if ($type === 'object') {
            return static::objectViolation($schema, $value, $path);
        }

        if ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $item) {
                $violation = static::violation($schema['items'], $item, "{$path}[{$index}]");

                if ($violation !== null) {
                    return $violation;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     */
    protected static function objectViolation(array $schema, array $value, string $path): ?string
    {
        $properties = $schema['properties'] ?? [];

        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                return "{$path}.{$key} is missing";
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            $extra = array_diff(array_keys($value), array_keys($properties));

            if ($extra !== []) {
                return "{$path} has unexpected key ".implode(', ', $extra);
            }
        }

        foreach ($properties as $key => $propertySchema) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $violation = static::violation($propertySchema, $value[$key], "{$path}.{$key}");

            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }
}
