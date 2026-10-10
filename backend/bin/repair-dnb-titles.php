<?php

declare(strict_types=1);

// Corrects titles that older versions read from the DNB, all at once instead of
// 30 per cron run:  php backend/bin/repair-dnb-titles.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../bootstrap.php';

$db = BookSpot\App::db();
$metadata = new BookSpot\MetadataService($db);
$metadata->maxWait = 10.0;
$repair = new BookSpot\DnbTitleRepair($db, $metadata);
$fixed = 0;
do {
    $result = $repair->run(50, 120.0);
    $fixed += $result['fixed'];
    echo "checked {$result['checked']}, fixed {$result['fixed']}, remaining {$result['remaining']}", PHP_EOL;
} while ($result['remaining'] > 0 && $result['checked'] > 0);
echo $result['remaining'] > 0 ? "DNB not reachable – run again later (or let cron continue).\n" : "Done, $fixed titles corrected.\n";
