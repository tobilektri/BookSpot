<?php

declare(strict_types=1);

namespace BookSpot\Http\Middleware;

use BookSpot\ApiException;
use BookSpot\Http\Middleware;
use BookSpot\Http\Response;
use BookSpot\Http\Route;
use BookSpot\Request;

/**
 * Cross-site requests cannot set custom headers without a CORS preflight (which
 * is refused unless an origin is explicitly allowed), so this header together
 * with SameSite=Strict cookies protects state-changing requests against CSRF.
 */
final class Csrf implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $route = $request->attributes['route'] ?? null;
        $enabled = !$route instanceof Route || $route->csrf;
        if ($enabled && !$request->isSafe() && $request->header('X-Requested-With') !== 'BookSpot') {
            throw new ApiException('csrf_header_missing', 403);
        }
        return $next($request);
    }
}
