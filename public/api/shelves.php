<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Request;
use BookSpot\ShelfService;

/*
 * GET    shelves.php          all shelves with compartment counts
 * GET    shelves.php?id=1     one shelf incl. its books
 * POST   shelves.php          {name, room, description, columns: [{label, rows: ["L1", …]}] | [4, 2, 5]}
 * PUT    shelves.php?id=1
 * DELETE shelves.php?id=1
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    $shelves = new ShelfService(App::db());
    $id = $req->queryInt('id');

    return match ($req->method) {
        'GET' => $id !== null ? ['shelf' => $shelves->get($id, $user['id'])] : ['items' => $shelves->list()],
        'POST' => (function () use ($shelves, $req) {
            Api::status(201);
            return ['shelf' => $shelves->create($req->body)];
        })(),
        'PUT', 'PATCH' => ['shelf' => $shelves->update($id ?? throw ApiException::notFound(), $req->body)],
        'DELETE' => ['unassigned_books' => $shelves->delete($id ?? throw ApiException::notFound())],
        default => Api::methodNotAllowed(),
    };
});
