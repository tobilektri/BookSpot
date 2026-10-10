<?php

declare(strict_types=1);

namespace BookSpot\Http;

use BookSpot\Request;

/**
 * Cross-cutting step around a controller call. Calls $next to continue the
 * pipeline or returns its own response (e.g. 429).
 */
interface Middleware
{
    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response;
}
