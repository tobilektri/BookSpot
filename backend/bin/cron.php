<?php

declare(strict_types=1);

// CLI cron runner: php backend/bin/cron.php   (Docker: see the "cron" service)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

echo json_encode(BookSpot\Cron::run(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
