<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\Http\Response;
use BookSpot\Request;
use BookSpot\ShelfService;

final class ShelfController extends Controller
{
    private function shelves(): ShelfService
    {
        return new ShelfService($this->db());
    }

    /** All shelves with compartment counts */
    public function index(Request $request): array
    {
        $this->user($request);
        return ['items' => $this->shelves()->list()];
    }

    /** One shelf incl. its books */
    public function show(Request $request): array
    {
        return ['shelf' => $this->shelves()->get($this->id($request), $this->user($request)['id'])];
    }

    /** {name, room, description, columns: [{label, rows: ["L1", …]}] | [4, 2, 5]} */
    public function store(Request $request): Response
    {
        $this->user($request);
        return self::created(['shelf' => $this->shelves()->create($request->body)]);
    }

    public function update(Request $request): array
    {
        $this->user($request);
        return ['shelf' => $this->shelves()->update($this->id($request), $request->body)];
    }

    public function destroy(Request $request): array
    {
        $this->user($request);
        return ['unassigned_books' => $this->shelves()->delete($this->id($request))];
    }

    /** shelves.php */
    public function legacy(Request $request): array|Response
    {
        return match ($request->method) {
            'GET' => $request->queryInt('id') !== null ? $this->show($request) : $this->index($request),
            'POST' => $this->store($request),
            'PUT', 'PATCH' => $this->update($request),
            'DELETE' => $this->destroy($request),
            default => throw new ApiException('method_not_allowed', 405),
        };
    }
}
