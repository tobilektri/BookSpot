<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\Cron;
use BookSpot\Request;

/**
 * Web-cron for shared hosting (Lima-City "Cronjobs", cron-job.org, …):
 *   https://example.org/api/v1/cron?token=<token>   (old URL api/cron.php?token= keeps working)
 * The token is CRON_TOKEN from .env or – if empty – generated automatically; admins
 * find the complete URL under Settings → Cronjob. Safe to call every few minutes.
 * Docker / SSH users can run `php backend/bin/cron.php` instead.
 */
final class CronController extends Controller
{
    public function run(Request $request): array
    {
        $expected = Cron::token($this->db());
        $given = (string) ($request->query('token') ?? $request->header('X-Cron-Token') ?? '');
        if (!hash_equals($expected, $given)) {
            throw new ApiException('forbidden', 403);
        }
        @set_time_limit(120);
        return Cron::run();
    }
}
