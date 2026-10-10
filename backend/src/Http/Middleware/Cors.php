<?php

declare(strict_types=1);

namespace BookSpot\Http\Middleware;

use BookSpot\Auth;
use BookSpot\Config;
use BookSpot\Http\Middleware;
use BookSpot\Http\Response;
use BookSpot\Request;

/**
 * CORS. The app itself is same-origin and needs no CORS at all; by default no
 * other origin is allowed. CORS_ALLOWED_ORIGINS (comma separated, exact
 * origins) opens the API for other front-ends – those origins can then send
 * authenticated requests including the CSRF header, so only list trusted ones.
 */
final class Cors implements Middleware
{
    private const ALLOWED_HEADERS = 'Accept, Content-Type, X-Requested-With, X-HTTP-Method-Override';
    private const ALLOWED_METHODS = 'GET, POST, PUT, PATCH, DELETE';

    /** @param list<string>|null $allowedOrigins */
    public function __construct(private ?array $allowedOrigins = null)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $origin = $request->header('Origin');
        $crossOrigin = $origin !== null && $origin !== self::ownOrigin($request);
        $allowed = $crossOrigin && in_array($origin, $this->allowedOrigins(), true);

        if ($request->method === 'OPTIONS' && $request->header('Access-Control-Request-Method') !== null) {
            if (!$allowed) {
                return Response::json(['error' => 'cors_forbidden'], 403);
            }
            return $this->withCors(Response::empty(204, [
                'Access-Control-Allow-Methods' => self::ALLOWED_METHODS,
                'Access-Control-Allow-Headers' => self::ALLOWED_HEADERS,
                'Access-Control-Max-Age' => '600',
            ]), (string) $origin);
        }

        $response = $next($request);
        return $allowed ? $this->withCors($response, (string) $origin) : $response;
    }

    private function withCors(Response $response, string $origin): Response
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin');
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        return $this->allowedOrigins ??= array_values(array_filter(array_map(
            fn (string $o) => rtrim(trim($o), '/'),
            explode(',', (string) Config::get('CORS_ALLOWED_ORIGINS', ''))
        )));
    }

    private static function ownOrigin(Request $request): string
    {
        return (Auth::isHttps() ? 'https' : 'http') . '://' . ($request->header('Host') ?? '');
    }
}
