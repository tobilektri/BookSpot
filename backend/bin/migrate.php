<?php

declare(strict_types=1);

// Applies pending database migrations: php backend/bin/migrate.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$db = BookSpot\Database::fromConfig(BookSpot\App::root());
$applied = $db->migrate(BookSpot\App::root() . '/backend/migrations');
echo $applied === [] ? "Schema is up to date.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
