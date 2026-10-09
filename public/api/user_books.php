<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Request;
use BookSpot\UserBookService;

/*
 * Personal reading data of the logged-in user.
 * GET user_books.php?book_id=1
 * PUT user_books.php?book_id=1  {reading_status, rating, pages_read, started_at, finished_at, notes}
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    $service = new UserBookService(App::db());
    $bookId = $req->queryInt('book_id') ?? throw ApiException::validation(['book_id' => 'required']);

    return match ($req->method) {
        'GET' => ['personal' => $service->get($user['id'], $bookId)],
        'PUT', 'PATCH', 'POST' => $service->upsert($user['id'], $bookId, $req->body),
        default => Api::methodNotAllowed(),
    };
});
