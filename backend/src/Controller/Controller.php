<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\Auth;
use BookSpot\Database;
use BookSpot\Http\Response;
use BookSpot\Request;

/**
 * Base for API controllers. Methods receive the Request and return an array
 * (JSON 200) or a Response. They are used by the /api/v1 routes and by the
 * legacy endpoint files (books.php …), which pass ?id= instead of {id} and are
 * not wrapped in the Authenticate middleware – hence user()/admin() check the
 * session themselves when the middleware did not run.
 */
abstract class Controller
{
    protected function db(): Database
    {
        return App::db();
    }

    /** @return array<string, mixed> */
    protected function user(Request $request): array
    {
        return $request->attributes['user'] ??= Auth::requireUser();
    }

    /** @return array<string, mixed> */
    protected function admin(Request $request): array
    {
        return $request->attributes['user'] = Auth::requireAdmin();
    }

    /** Route parameter {id} or – legacy – ?id= */
    protected function id(Request $request, string $name = 'id', string $notFound = 'not_found'): int
    {
        return $request->paramInt($name) ?? $request->queryInt($name) ?? throw ApiException::notFound($notFound);
    }

    /** @param array<string, mixed> $data */
    protected static function created(array $data): Response
    {
        return Response::json($data, 201);
    }

    protected static function requirePost(Request $request): void
    {
        if ($request->isSafe()) {
            throw new ApiException('method_not_allowed', 405);
        }
    }
}
