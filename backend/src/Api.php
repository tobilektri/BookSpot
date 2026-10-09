<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Entry point for every public/api/*.php endpoint: sends security headers,
 * enforces the CSRF header on state-changing requests and renders results or
 * errors as JSON.
 */
final class Api
{
    private static int $status = 200;

    /** @param callable(Request): mixed $handler */
    public static function run(callable $handler, bool $csrf = true): void
    {
        self::$status = 200;
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

        try {
            $request = Request::fromGlobals();
            // Cross-site requests cannot set custom headers without a CORS preflight
            // (which we never allow), so this header together with SameSite=Strict
            // cookies protects against CSRF.
            if ($csrf && !$request->isSafe() && $request->header('X-Requested-With') !== 'BookSpot') {
                throw new ApiException('csrf_header_missing', 403);
            }
            $result = $handler($request);
            self::send($result ?? ['ok' => true], self::$status);
        } catch (ApiException $e) {
            self::send(['error' => $e->getMessage()] + $e->details, $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            error_log('[BookSpot] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $payload = ['error' => 'server_error'];
            if (Config::get('APP_ENV', 'production') === 'development') {
                $payload['message'] = $e->getMessage();
                $payload['trace'] = explode("\n", $e->getTraceAsString());
            }
            self::send($payload, 500);
        }
    }

    public static function status(int $status): void
    {
        self::$status = $status;
    }

    public static function methodNotAllowed(): never
    {
        throw new ApiException('method_not_allowed', 405);
    }

    private static function send(mixed $data, int $status): void
    {
        http_response_code($status);
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
