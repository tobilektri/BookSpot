<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\BookService;
use BookSpot\Request;
use BookSpot\ShelfService;
use BookSpot\UserBookService;

/*
 * Offline queue replay. The PWA stores changes made while offline in
 * IndexedDB (Dexie) and posts them here in order once the connection is back:
 *
 * POST sync.php {ops: [
 *   {id: "uuid", type: "personal", book_id: 1, data: {...}, client_updated_at: "2026-10-09T10:00:00Z"},
 *   {id: "uuid", type: "move", book_ids: [1, 2], shelf_id: 3, shelf_col: 1, shelf_row: 2},
 * ]}
 *
 * Every op is applied independently; the response lists the result per op id.
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    if ($req->method !== 'POST') {
        Api::methodNotAllowed();
    }
    $ops = $req->input('ops', []);
    if (!is_array($ops) || count($ops) > 500) {
        throw ApiException::validation(['ops' => 'invalid']);
    }
    $db = App::db();
    $personal = new UserBookService($db);
    $books = new BookService($db, new ShelfService($db));

    $results = [];
    foreach ($ops as $op) {
        $opId = is_array($op) ? (string) ($op['id'] ?? '') : '';
        try {
            if (!is_array($op)) {
                throw new ApiException('invalid_op');
            }
            $result = match ($op['type'] ?? '') {
                'personal' => $personal->upsert(
                    $user['id'],
                    (int) ($op['book_id'] ?? 0),
                    (is_array($op['data'] ?? null) ? $op['data'] : []) + ['client_updated_at' => $op['client_updated_at'] ?? null]
                ),
                'move' => ['status' => 'saved', 'moved' => $books->move(
                    is_array($op['book_ids'] ?? null) ? $op['book_ids'] : [],
                    $op['shelf_id'] ?? null,
                    $op['shelf_col'] ?? null,
                    $op['shelf_row'] ?? null,
                )],
                default => throw new ApiException('unknown_op_type'),
            };
            $results[] = ['id' => $opId] + $result;
        } catch (ApiException $e) {
            $results[] = ['id' => $opId, 'status' => 'error', 'error' => $e->getMessage()] + $e->details;
        }
    }
    return ['results' => $results, 'server_time' => App::now()->format(DATE_ATOM)];
});
