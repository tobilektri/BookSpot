<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\BookService;
use BookSpot\MetadataService;
use BookSpot\Request;
use BookSpot\ShelfService;

/*
 * GET    books.php                    list (q, reading_status, entry_type, genre, shelf_id, col, row, unplaced, lent, sort, limit, offset)
 * GET    books.php?isbn=978…          catalogue lookup by ISBN -> {found, book}
 * GET    books.php?id=1               details incl. personal data
 * GET    books.php?action=due         active loans ordered by due date
 * GET    books.php?action=genres      genres with counts
 * POST   books.php                    create
 * PUT    books.php?id=1               update
 * DELETE books.php?id=1               delete
 * POST   books.php?action=move        {ids: [], shelf_id, shelf_col, shelf_row}
 * POST   books.php?action=enrich      fill missing metadata for a few books
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    $db = App::db();
    $books = new BookService($db, new ShelfService($db));
    $id = $req->queryInt('id');

    if ($req->isSafe()) {
        if ($req->action() === 'due') {
            return ['items' => $books->dueLoans($user['id'])];
        }
        if ($req->action() === 'genres') {
            return ['items' => $books->genres()];
        }
        if ($req->query('isbn') !== null) {
            $book = $books->findByIsbn((string) $req->query('isbn'), $user['id']);
            return ['found' => $book !== null, 'book' => $book];
        }
        if ($id !== null) {
            return ['book' => $books->get($id, $user['id'])];
        }
        return $books->list($req->query, $user['id']);
    }

    if ($req->method === 'POST' && $req->action() === 'move') {
        $ids = $req->input('ids', []);
        if (!is_array($ids)) {
            throw ApiException::validation(['ids' => 'required']);
        }
        return ['moved' => $books->move($ids, $req->input('shelf_id'), $req->input('shelf_col'), $req->input('shelf_row'))];
    }
    if ($req->method === 'POST' && $req->action() === 'enrich') {
        @set_time_limit(60);
        return (new MetadataService($db))->enrichBatch((int) ($req->input('limit') ?? 5));
    }

    return match ($req->method) {
        'POST' => (function () use ($books, $req, $user) {
            Api::status(201);
            return ['book' => $books->create($req->body, $user['id'])];
        })(),
        'PUT', 'PATCH' => ['book' => $books->update($id ?? throw ApiException::notFound(), $req->body, $user['id'])],
        'DELETE' => (function () use ($books, $id) {
            $books->delete($id ?? throw ApiException::notFound());
            return ['ok' => true];
        })(),
        default => Api::methodNotAllowed(),
    };
});
