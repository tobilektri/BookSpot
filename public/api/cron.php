<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\ApiException;
use BookSpot\Config;
use BookSpot\Cron;
use BookSpot\Request;

/*
 * Web-cron endpoint for shared hosting (Lima-City "Cronjobs", cron-job.org, …):
 *   https://example.org/api/cron.php?token=<CRON_TOKEN>
 * Sends due-date push reminders and enriches book metadata. Safe to call hourly.
 * Docker / SSH users can run `php backend/bin/cron.php` instead.
 */
Api::run(function (Request $req) {
    $expected = (string) Config::get('CRON_TOKEN', '');
    $given = (string) ($req->query('token') ?? $req->header('X-Cron-Token') ?? '');
    if ($expected === '' || strlen($expected) < 16) {
        throw new ApiException('cron_token_not_configured', 503);
    }
    if (!hash_equals($expected, $given)) {
        throw new ApiException('forbidden', 403);
    }
    @set_time_limit(120);
    return Cron::run();
}, csrf: false);
