<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\App;
use BookSpot\Auth;
use BookSpot\CoverService;

/*
 * GET cover.php?id=<book id>&v=<version>
 *     Serves the locally stored cover (downloaded on first request, see CoverService).
 * GET cover.php?preview=1&url=<cover url>  |  &isbn=<isbn13>
 *     Preview for the book form before saving – fetched by the server, not stored.
 * 404 when there is no cover – the app then shows its generated placeholder.
 * The browser never loads covers from other hosts (CSP img-src 'self').
 */
header('X-Content-Type-Options: nosniff');
try {
    Auth::$readOnlySession = true; // no session lock: a book list loads many covers in parallel
    if (Auth::user() === null) {
        http_response_code(401);
        exit;
    }
    if (isset($_GET['preview'])) {
        $preview = (new CoverService(App::db()))->preview(
            is_string($_GET['url'] ?? null) ? trim($_GET['url']) : null,
            is_string($_GET['isbn'] ?? null) ? trim($_GET['isbn']) : null,
        );
        if ($preview === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $preview['mime']);
        header('Cache-Control: private, max-age=86400');
        header("Content-Security-Policy: default-src 'none'");
        echo $preview['body'];
        exit;
    }

    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $cover = $id === false ? null : (new CoverService(App::db()))->ensure($id);
    if ($cover === null) {
        http_response_code(404);
        header('Cache-Control: private, max-age=3600');
        exit;
    }
    $etag = '"' . md5($cover['path'] . filemtime($cover['path']) . filesize($cover['path'])) . '"';
    header('Content-Type: ' . $cover['mime']);
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($cover['path']));
    readfile($cover['path']);
} catch (Throwable $e) {
    error_log('[BookSpot] cover failed: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
}
