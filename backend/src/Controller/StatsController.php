<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\Request;
use BookSpot\StatsService;

final class StatsController extends Controller
{
    /** ?year=2026 */
    public function stats(Request $request): array
    {
        $user = $this->user($request);
        $year = $request->queryInt('year');
        if ($year !== null && ($year < 1900 || $year > 2200)) {
            $year = null;
        }
        return (new StatsService($this->db()))->forUser($user['id'], $year);
    }
}
