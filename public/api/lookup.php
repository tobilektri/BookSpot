<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\Auth;
use BookSpot\MetadataService;
use BookSpot\Request;

// GET lookup.php?isbn=978… -> metadata from Open Library (fallback: Google Books)
Api::run(function (Request $req) {
    Auth::requireUser();
    $meta = (new MetadataService(App::db()))->lookup((string) $req->query('isbn', ''));
    return ['found' => $meta !== null, 'metadata' => $meta];
});
