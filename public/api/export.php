<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\App;
use BookSpot\Auth;
use BookSpot\ExportService;

// GET export.php -> Bookstats-compatible CSV download (catalogue + own reading data)
try {
    $user = Auth::user();
    if ($user === null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unauthorized';
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bookspot-' . App::today() . '.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    (new ExportService(App::db()))->writeCsv($user['id'], fopen('php://output', 'w'));
} catch (Throwable $e) {
    error_log('[BookSpot] export failed: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'Export failed';
}
