<?php

use App\Http\Middleware\EnsureMinimumAppVersion;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // An outdated app gets 426 before auth or a rate limit can answer instead.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureMinimumAppVersion::class,
        );

        // The API answers 401 in JSON; it never redirects to a login page.
        $middleware->redirectGuestsTo(
            fn (Request $request) => ApiResponse::handles($request) ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ApiResponse::handles($request) || $request->expectsJson(),
        );

        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => $response instanceof JsonResponse && ApiResponse::handles($request)
            ? ApiResponse::wrap($response)
            : $response);
    })->create();
