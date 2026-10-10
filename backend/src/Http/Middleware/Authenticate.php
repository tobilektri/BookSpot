<?php

declare(strict_types=1);

namespace BookSpot\Http\Middleware;

use BookSpot\Auth;
use BookSpot\Http\Middleware;
use BookSpot\Http\Response;
use BookSpot\Request;

/**
 * Requires a logged-in user (or an admin) and puts it into $request->attributes['user'].
 * readOnlySession avoids the session lock for requests that only read (cover images,
 * many in parallel).
 */
final class Authenticate implements Middleware
{
    public function __construct(private string $role = Auth::ROLE_USER, private bool $readOnlySession = false)
    {
    }

    public static function admin(): self
    {
        return new self(Auth::ROLE_ADMIN);
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->readOnlySession) {
            Auth::$readOnlySession = true;
        }
        $request->attributes['user'] = $this->role === Auth::ROLE_ADMIN ? Auth::requireAdmin() : Auth::requireUser();
        return $next($request);
    }
}
