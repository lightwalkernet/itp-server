<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 426 Upgrade Required when X-App-Version is below `itp.min_app_version`. Both builds
 * always send the header, so a missing or malformed one is refused too (platform.md §4).
 */
class EnsureMinimumAppVersion
{
    /**
     * MAJOR.MINOR.PATCH, digits only.
     */
    protected const VERSION_PATTERN = '/\A\d+\.\d+\.\d+\z/';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $version = $request->header('X-App-Version');
        $minimum = config('itp.min_app_version');

        $problem = match (true) {
            ! is_string($version) || $version === '' => 'The X-App-Version header is required.',
            ! preg_match(self::VERSION_PATTERN, $version) => 'The X-App-Version header must look like 1.2.3.',
            version_compare($version, $minimum, '<') => "Version {$version} is no longer supported.",
            default => null,
        };

        if ($problem !== null) {
            return ApiResponse::error(
                'This version of the app needs an update.',
                Response::HTTP_UPGRADE_REQUIRED,
                ['app_version' => [$problem]],
                ['min_app_version' => $minimum],
            );
        }

        return $next($request);
    }
}
