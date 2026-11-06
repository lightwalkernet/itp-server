<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Content\PullContentRequest;
use Illuminate\Http\JsonResponse;
use Lightwalker\Sync\Server\SyncServer;
use Lightwalker\Sync\SyncRegistry;

/**
 * Shared content for guests, so a phone that is not signed in still gets server changes to the
 * universal ITPs and wildcards (platform.md §7).
 */
class ContentController extends Controller
{
    /**
     * `GET content/pull?type=&after=&limit=`, for global sync types only. The body is exactly the
     * sync pull's, so the app applies it with the sync package's own logic. A global type's pull
     * ignores the user, so none is passed.
     */
    public function pull(PullContentRequest $request, SyncServer $server, SyncRegistry $registry): JsonResponse
    {
        $type = $request->validated('type');

        abort_unless($registry->hasType($type) && $registry->definition($type)['scope'] === 'global', 404);

        $page = $server->pull(
            $type,
            '',
            (int) $request->validated('after', 0),
            (int) $request->validated('limit', config('sync.server.pull_limit')),
        );

        return new JsonResponse(['data' => ['type' => $type, ...$page]]);
    }
}
