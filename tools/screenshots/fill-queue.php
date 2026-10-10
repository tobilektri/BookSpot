<?php

declare(strict_types=1);

/*
 * Demo helper for capture.mjs – plays the metadata sources for the bulk scan GIF,
 * so no real requests go out and the result is always the same.
 *   --block-providers  pause the real sources (the app's own queue polling then waits)
 *   --fill             work off the queue with a fake source
 * Uses the same .env / environment as the app (DB_DRIVER, DB_SQLITE_PATH …).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\App;
use BookSpot\BatchImporter;
use BookSpot\MetadataService;
use BookSpot\RateLimiter;

$db = App::db();
$de = $db->value("SELECT locale FROM users WHERE username = 'anna'") === 'de';

if (in_array('--block-providers', $argv, true)) {
    $limiter = new RateLimiter();
    foreach (['open_library', 'dnb', 'google_books'] as $provider) {
        $limiter->penalize($provider, 3600);
    }
    echo "real sources paused\n";
}

if (in_array('--fill', $argv, true)) {
    $titles = [
        '101' => [$de ? 'Ruf der Wildnis' : 'The Call of the Wild', 'Jack London', 232],
        '102' => [$de ? 'Peter Pan' : 'Peter Pan', 'J. M. Barrie', 208],
    ];
    $fake = function (string $url) use ($titles): ?string {
        if (!preg_match('/ISBN:(978300(\d{6})\d)/', $url, $m) || !str_contains($url, 'openlibrary.org')) {
            return null;
        }
        $book = $titles[ltrim(substr($m[2], 3), '0')] ?? null;
        return $book === null ? null : (string) json_encode(["ISBN:$m[1]" => [
            'title' => $book[0], 'authors' => [['name' => $book[1]]], 'number_of_pages' => $book[2],
        ]]);
    };
    // Own limiter state: the real sources stay paused for the app.
    RateLimiter::$directory = sys_get_temp_dir() . '/bookspot-demo-ratelimit';
    $db->run("UPDATE metadata_queue SET next_attempt_at = ? WHERE status = 'pending'", [App::nowString()]);
    $result = (new BatchImporter($db, new MetadataService($db, $fake, new RateLimiter())))->process(20, 30, 1.0);
    echo json_encode($result), "\n";
}
