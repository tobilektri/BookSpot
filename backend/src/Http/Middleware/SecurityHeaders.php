<?php

declare(strict_types=1);

namespace BookSpot\Http\Middleware;

use BookSpot\Http\Middleware;
use BookSpot\Http\Response;
use BookSpot\Request;

/** Default security headers; responses that set their own (covers, downloads) keep them. */
final class SecurityHeaders implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        $response->withDefaultHeader('X-Content-Type-Options', 'nosniff');
        $response->withDefaultHeader('Referrer-Policy', 'same-origin');
        if ($response->isJson()) {
            $response->withDefaultHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        }
        return $response;
    }
}
