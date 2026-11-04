<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The one JSON shape every API response uses, success or failure:
 *
 *     {"message": "...", "data": {...}, "errors": {"field": ["..."]}}
 *
 * All three keys are always present. `errors` follows Laravel's validation format.
 */
class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(string $message, array $data = [], int $status = 200): JsonResponse
    {
        return new JsonResponse(static::body($message, $data, []), $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $data
     */
    public static function error(string $message, int $status, array $errors = [], array $data = []): JsonResponse
    {
        return new JsonResponse(static::body($message, $data, $errors), $status);
    }

    /**
     * Rewrap a framework error response (validation, auth, throttle, not found) in the API shape.
     * Status and headers, such as `Retry-After`, are kept. Debug details (APP_DEBUG only) stay
     * alongside on server errors, never on client errors.
     */
    public static function wrap(JsonResponse $response): JsonResponse
    {
        $payload = (array) $response->getData(true);

        $debug = $response->isServerError() ? Arr::except($payload, ['message', 'data', 'errors']) : [];

        return $response->setData(
            static::body($payload['message'] ?? '', [], $payload['errors'] ?? []) + $debug
        );
    }

    public static function handles(Request $request): bool
    {
        return $request->getHost() === config('itp.api_domain');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     * @return array{message: string, data: object, errors: object}
     */
    protected static function body(string $message, array $data, array $errors): array
    {
        return [
            'message' => $message,
            'data' => (object) $data,
            'errors' => (object) $errors,
        ];
    }
}
