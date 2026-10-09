<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\Cron;
use BookSpot\Request;

/*
 * Web-cron endpoint for shared hosting (Lima-City "Cronjobs", cron-job.org, …):
 *   https://example.org/api/cron.php?token=<token>
 * The token is CRON_TOKEN from .env or – if empty – generated automatically; admins
 * find the complete URL under Settings → Cronjob.
 * Sends due-date push reminders and enriches book metadata. Safe to call hourly.
 * Docker / SSH users can run `php backend/bin/cron.php` instead.
 */
Api::run(function (Request $req) {
    // CRON_TOKEN from .env, otherwise generated automatically (see Settings → Cronjob as admin).
    $expected = Cron::token(App::db());
    $given = (string) ($req->query('token') ?? $req->header('X-Cron-Token') ?? '');
    if (!hash_equals($expected, $given)) {
        throw new ApiException('forbidden', 403);
    }
    @set_time_limit(120);
    return Cron::run();
}, csrf: false);
