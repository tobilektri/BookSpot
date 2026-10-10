<?php

declare(strict_types=1);

namespace BookSpot\Http\Middleware;

use BookSpot\ApiException;
use BookSpot\Config;
use BookSpot\Http\Middleware;
use BookSpot\Http\Response;
use BookSpot\Request;

/** Turns ApiException and unexpected errors into uniform JSON error responses. */
final class ErrorHandler implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (ApiException $e) {
            return Response::json(['error' => $e->getMessage()] + $e->details, $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('[BookSpot] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $payload = ['error' => 'server_error'];
            if (Config::get('APP_ENV', 'production') === 'development') {
                $payload['message'] = $e->getMessage();
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }
            return Response::json($payload, 500);
        }
    }
}
