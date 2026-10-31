<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\BookService;
use BookSpot\CoverService;
use BookSpot\Http\Response;
use BookSpot\Request;
use BookSpot\ShelfService;
use BookSpot\WishlistService;

/** Personal wishlists, shared with the instance or by a public link (see WishlistService). */
final class WishlistController extends Controller
{
    private function service(): WishlistService
    {
        return new WishlistService($this->db(), new BookService($this->db(), new ShelfService($this->db())));
    }

    /** My list and its sharing setting */
    public function index(Request $request): array
    {
        return $this->service()->mine($this->user($request)['id']);
    }

    /** Lists other users share with the instance */
    public function shared(Request $request): array
    {
        return ['lists' => $this->service()->shared($this->user($request)['id'])];
    }

    /** {book_id}: put a book of the catalogue on my list (new books: POST /books with wishlist=true) */
    public function add(Request $request): array
    {
        $user = $this->user($request);
        $bookId = (int) $request->input('book_id', 0);
        if ($bookId <= 0) {
            throw ApiException::validation(['book_id' => 'required']);
        }
        $this->service()->add($user['id'], $bookId);
        return $this->service()->mine($user['id']);
    }

    public function remove(Request $request): array
    {
        $user = $this->user($request);
        $this->service()->remove($user['id'], $this->id($request, 'id', 'wish_not_found'));
        return $this->service()->mine($user['id']);
    }

    /** {entry_type: owned|library_loan}: bought or borrowed – the book joins the collection */
    public function fulfil(Request $request): array
    {
        $user = $this->user($request);
        return ['book' => $this->service()->fulfil($user['id'], $this->id($request), (string) $request->input('entry_type', 'owned'))];
    }

    /** {visibility: private|instance|public, renew?: bool} */
    public function share(Request $request): array
    {
        $user = $this->user($request);
        return ['sharing' => $this->service()->share($user['id'], (string) $request->input('visibility', ''), (bool) $request->input('renew', false))];
    }

    /** Public list behind a share link – no sign-in */
    public function public(Request $request): array
    {
        return $this->service()->public((string) ($request->params['token'] ?? ''));
    }

    /** Cover of a book on a public list */
    public function publicCover(Request $request): Response
    {
        $bookId = $this->service()->publicBook((string) ($request->params['token'] ?? ''), $this->id($request));
        $cover = $bookId === null ? null : (new CoverService($this->db()))->ensure($bookId);
        if ($cover === null) {
            return Response::empty(404, ['Cache-Control' => 'public, max-age=3600']);
        }
        $path = $cover['path'];
        return new Response(200, [
            'Content-Type' => $cover['mime'],
            'Cache-Control' => 'public, max-age=86400',
            'Content-Length' => (string) filesize($path),
        ], static function () use ($path): void {
            readfile($path);
        });
    }
}
