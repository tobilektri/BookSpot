<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\BookService;
use BookSpot\Http\Response;
use BookSpot\MetadataService;
use BookSpot\Request;
use BookSpot\ShelfService;
use BookSpot\UserBookService;

final class BookController extends Controller
{
    private function books(): BookService
    {
        return new BookService($this->db(), new ShelfService($this->db()));
    }

    /** List: q, reading_status, entry_type, genre, shelf_id, col, row, unplaced, lent, sort, limit, offset */
    public function index(Request $request): array
    {
        return $this->books()->list($request->query, $this->user($request)['id']);
    }

    public function show(Request $request): array
    {
        return ['book' => $this->books()->get($this->id($request), $this->user($request)['id'])];
    }

    /** Catalogue lookup by ISBN -> {found, book} */
    public function byIsbn(Request $request): array
    {
        $book = $this->books()->findByIsbn((string) ($request->params['isbn'] ?? $request->query('isbn', '')), $this->user($request)['id']);
        return ['found' => $book !== null, 'book' => $book];
    }

    public function store(Request $request): Response
    {
        return self::created(['book' => $this->books()->create($request->body, $this->user($request)['id'])]);
    }

    public function update(Request $request): array
    {
        return ['book' => $this->books()->update($this->id($request), $request->body, $this->user($request)['id'])];
    }

    public function destroy(Request $request): array
    {
        $this->user($request);
        $this->books()->delete($this->id($request));
        return ['ok' => true];
    }

    /** Active loans ordered by due date */
    public function due(Request $request): array
    {
        return ['items' => $this->books()->dueLoans($this->user($request)['id'])];
    }

    public function genres(Request $request): array
    {
        $this->user($request);
        return ['items' => $this->books()->genres()];
    }

    /** Number of books whose metadata was never looked up */
    public function enrichStatus(Request $request): array
    {
        $this->user($request);
        return ['remaining' => (new MetadataService($this->db()))->pendingCount()];
    }

    /** Fill missing metadata for a few books */
    public function enrich(Request $request): array
    {
        $this->user($request);
        @set_time_limit(60);
        return (new MetadataService($this->db()))->enrichBatch((int) ($request->input('limit') ?? 5));
    }

    /** {ids: [], shelf_id, shelf_col, shelf_row} */
    public function move(Request $request): array
    {
        $this->user($request);
        $ids = $request->input('ids', []);
        if (!is_array($ids)) {
            throw ApiException::validation(['ids' => 'required']);
        }
        return ['moved' => $this->books()->move($ids, $request->input('shelf_id'), $request->input('shelf_col'), $request->input('shelf_row'))];
    }

    /** Personal reading data of the logged-in user */
    public function personal(Request $request): array
    {
        return ['personal' => (new UserBookService($this->db()))->get($this->user($request)['id'], $this->bookId($request))];
    }

    /** {reading_status, rating, pages_read, started_at, finished_at, notes} */
    public function updatePersonal(Request $request): array
    {
        return (new UserBookService($this->db()))->upsert($this->user($request)['id'], $this->bookId($request), $request->body);
    }

    private function bookId(Request $request): int
    {
        return $request->paramInt('id') ?? $request->queryInt('book_id') ?? throw ApiException::validation(['book_id' => 'required']);
    }

    /** books.php */
    public function legacy(Request $request): array|Response
    {
        $this->user($request);
        if ($request->isSafe()) {
            return match (true) {
                $request->action() === 'due' => $this->due($request),
                $request->action() === 'enrich_status' => $this->enrichStatus($request),
                $request->action() === 'genres' => $this->genres($request),
                $request->query('isbn') !== null => $this->byIsbn($request),
                $request->queryInt('id') !== null => $this->show($request),
                default => $this->index($request),
            };
        }
        if ($request->method === 'POST' && $request->action() === 'move') {
            return $this->move($request);
        }
        if ($request->method === 'POST' && $request->action() === 'enrich') {
            return $this->enrich($request);
        }
        return match ($request->method) {
            'POST' => $this->store($request),
            'PUT', 'PATCH' => $this->update($request),
            'DELETE' => $this->destroy($request),
            default => throw new ApiException('method_not_allowed', 405),
        };
    }

    /** user_books.php?book_id= */
    public function legacyPersonal(Request $request): array
    {
        $this->bookId($request);
        return match ($request->method) {
            'GET' => $this->personal($request),
            'PUT', 'PATCH', 'POST' => $this->updatePersonal($request),
            default => throw new ApiException('method_not_allowed', 405),
        };
    }
}
