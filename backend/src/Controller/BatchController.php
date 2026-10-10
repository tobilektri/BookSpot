<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\BatchImporter;
use BookSpot\Request;

/**
 * Bulk scan.
 *
 * POST /api/v1/books/batch
 *   {shelf_id, shelf_col, shelf_row, isbns: ["978…", …]}                      same compartment for all
 *   {items: [{client_id, isbn, shelf_id, shelf_col, shelf_row}, …]}           per scan (offline queue)
 *   move_existing: true (default) moves books already in the catalogue into that compartment
 *   -> {status: "queued", queued, items: [{client_id, isbn, status, book}]}
 *      status: created | existing | existing_here | moved | duplicate | invalid | invalid_location
 *
 * POST /api/v1/books/batch/process  {book_ids: [...]}
 *   works off a few queue entries (rate limited) and returns the current state of those books
 */
final class BatchController extends Controller
{
    public function store(Request $request): array
    {
        $user = $this->user($request);
        $items = $request->input('items');
        if ($items === null) {
            $isbns = $request->input('isbns', []);
            $items = is_array($isbns) ? array_map(fn ($isbn) => ['isbn' => (string) $isbn], array_values($isbns)) : null;
        }
        if (!is_array($items)) {
            throw ApiException::validation(['items' => 'required']);
        }
        $defaults = [
            'shelf_id' => $request->input('shelf_id'),
            'shelf_col' => $request->input('shelf_col'),
            'shelf_row' => $request->input('shelf_row'),
        ];
        $move = filter_var($request->input('move_existing', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        return (new BatchImporter($this->db()))->submit(array_values($items), $defaults, $user['id'], $move);
    }

    public function process(Request $request): array
    {
        $user = $this->user($request);
        @set_time_limit(60);
        $service = new BatchImporter($this->db());
        $result = $service->process(limit: 5, maxSeconds: 8.0);
        $ids = $request->input('book_ids', []);
        $result['books'] = is_array($ids) ? $service->status($ids, $user['id']) : [];
        return $result;
    }
}
