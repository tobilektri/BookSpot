<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\Auth;
use BookSpot\Request;
use BookSpot\StatsService;

// GET stats.php?year=2026
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    $year = $req->queryInt('year');
    if ($year !== null && ($year < 1900 || $year > 2200)) {
        $year = null;
    }
    return (new StatsService(App::db()))->forUser($user['id'], $year);
});
