<?php

declare(strict_types=1);

namespace BookSpot\Http\Middleware;

use BookSpot\Http\Middleware;
use BookSpot\Http\Response;
use BookSpot\Http\Route;
use BookSpot\RateLimiter;
use BookSpot\Request;

/**
 * Request limit per user (after Authenticate) or per IP. Answers 429 with
 * Retry-After when exceeded.
 *
 *   new RateLimit('batch', maxRequests: 30, windowSeconds: 60)   per route
 *   RateLimit::global(600)                                         per IP, all routes
 */
final class RateLimit implements Middleware
{
    public function __construct(
        private string $name,
        private int $maxRequests,
        private int $windowSeconds = 60,
        private bool $perIp = false,
        private ?RateLimiter $limiter = null,
    ) {
    }

    public static function global(int $perMinute, ?RateLimiter $limiter = null): self
    {
        return new self('global', $perMinute, 60, true, $limiter);
    }

    public function process(Request $request, callable $next): Response
    {
        $route = $request->attributes['route'] ?? null;
        if ($this->maxRequests <= 0 || ($this->name === 'global' && $route instanceof Route && !$route->throttle)) {
            return $next($request);
        }
        $user = $request->attributes['user'] ?? null;
        $subject = !$this->perIp && is_array($user) ? 'user:' . $user['id'] : 'ip:' . $request->ip;
        $wait = ($this->limiter ??= new RateLimiter())->attempt("request:{$this->name}:$subject", $this->maxRequests, $this->windowSeconds);
        if ($wait > 0) {
            $seconds = (int) ceil($wait);
            return Response::json(['error' => 'rate_limited', 'retry_after' => $seconds], 429)
                ->withHeader('Retry-After', (string) $seconds);
        }
        return $next($request);
    }
}
