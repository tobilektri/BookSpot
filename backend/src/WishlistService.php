<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Personal wishlists.
 *
 * A wished book that is not part of the collection is a books row with
 * wishlist = 1: it stays out of the book list, shelves and statistics and only
 * appears on the wishlists. Who wishes for it is stored in wishes, so a book
 * can be on several lists. "Bought" / "borrowed" turns it into an ordinary
 * book of the collection and clears the wishes.
 *
 * Each list is private unless its owner shares it with everybody on the
 * instance or with everybody who has the link (no sign-in needed).
 */
final class WishlistService
{
    public const VISIBILITIES = ['private', 'instance', 'public'];

    public function __construct(private readonly Database $db, private readonly BookService $books)
    {
    }

    /** @return array{items: list<array<string, mixed>>, sharing: array{visibility: string, token: ?string}} */
    public function mine(int $userId): array
    {
        return ['items' => $this->items($userId, $userId), 'sharing' => $this->sharing($userId)];
    }

    /**
     * Wishlists other users share with the instance (or publicly).
     *
     * @return list<array{username: string, items: list<array<string, mixed>>}>
     */
    public function shared(int $viewerId): array
    {
        $owners = $this->db->all(
            "SELECT DISTINCT u.id, u.username FROM users u JOIN wishes w ON w.user_id = u.id
             WHERE u.id <> ? AND u.wishlist_visibility IN ('instance', 'public') ORDER BY u.username",
            [$viewerId]
        );
        return array_map(fn ($u) => ['username' => $u['username'], 'items' => $this->items((int) $u['id'], $viewerId)], $owners);
    }

    /**
     * Public list behind a share link – only bibliographic data, nothing personal.
     *
     * @return array{username: string, items: list<array<string, mixed>>}
     */
    public function public(string $token): array
    {
        $owner = $this->publicOwner($token) ?? throw ApiException::notFound('wishlist_not_found');
        $rows = $this->db->all(
            'SELECT b.id, b.title, b.authors, b.publisher, b.published_year, b.isbn13, b.cover_url, b.cover_status, b.cover_uploaded_at
             FROM wishes w JOIN books b ON b.id = w.book_id WHERE w.user_id = ? ORDER BY w.created_at DESC, w.id DESC',
            [(int) $owner['id']]
        );
        return [
            'username' => $owner['username'],
            'items' => array_map(fn ($b) => [
                'id' => (int) $b['id'],
                'title' => $b['title'],
                'authors' => $b['authors'],
                'publisher' => $b['publisher'],
                'published_year' => $b['published_year'] === null ? null : (int) $b['published_year'],
                'isbn13' => $b['isbn13'],
                'has_cover' => ($b['cover_status'] ?? null) !== 'none',
                'cover_version' => CoverService::version($b),
            ], $rows),
        ];
    }

    /** Book id behind a share link, if that book is on the list (for its cover). */
    public function publicBook(string $token, int $bookId): ?int
    {
        $owner = $this->publicOwner($token);
        if ($owner === null) {
            return null;
        }
        $found = $this->db->value('SELECT book_id FROM wishes WHERE user_id = ? AND book_id = ?', [(int) $owner['id'], $bookId]);
        return $found === null ? null : (int) $found;
    }

    /** Puts a book of the catalogue (e.g. a returned library loan) on the user's list. */
    public function add(int $userId, int $bookId): void
    {
        $this->books->get($bookId, $userId); // exists and visible
        if ($this->db->value('SELECT id FROM wishes WHERE user_id = ? AND book_id = ?', [$userId, $bookId]) === null) {
            $this->db->insert('wishes', ['user_id' => $userId, 'book_id' => $bookId, 'created_at' => App::nowString()]);
        }
    }

    /** Removes a wish; a wished-only book nobody wishes for any more is deleted. */
    public function remove(int $userId, int $bookId): void
    {
        $this->db->transaction(function () use ($userId, $bookId) {
            if ($this->db->run('DELETE FROM wishes WHERE user_id = ? AND book_id = ?', [$userId, $bookId])->rowCount() === 0) {
                throw ApiException::notFound('wish_not_found');
            }
            $orphan = $this->db->value(
                'SELECT b.id FROM books b WHERE b.id = ? AND b.wishlist = 1 AND NOT EXISTS (SELECT 1 FROM wishes w WHERE w.book_id = b.id)',
                [$bookId]
            );
            if ($orphan !== null) {
                $this->books->delete($bookId);
            }
        });
    }

    /**
     * "Bought" (owned) or "borrowed" (library loan): the book becomes part of the
     * collection – received today, a loan due after the user's default loan period
     * (otherwise 3 weeks) – and is no longer wished for by anybody.
     *
     * @return array<string, mixed> the book
     */
    public function fulfil(int $userId, int $bookId, string $type): array
    {
        if (!in_array($type, ['owned', 'library_loan'], true)) {
            throw ApiException::validation(['entry_type' => 'invalid_choice']);
        }
        // Only a wish: a wished-only book the user can see (e.g. a gift from a shared list), or a
        // book of the catalogue the user wishes for – never somebody else's loan or book.
        $wishlist = $this->db->value('SELECT wishlist FROM books WHERE id = ?', [$bookId]);
        $ownWish = $this->db->value('SELECT id FROM wishes WHERE user_id = ? AND book_id = ?', [$userId, $bookId]) !== null;
        if ($wishlist === null || !($ownWish || (!empty($wishlist) && BookService::canSee($this->db, $bookId, $userId)))) {
            throw ApiException::notFound('wish_not_found');
        }
        $today = App::today();
        $input = ['entry_type' => $type, 'status' => 'active'];
        if ($type === 'owned') {
            $input['acquired_at'] = $today;
        } else {
            $defaults = $this->db->one('SELECT library_name, library_loan_days FROM users WHERE id = ?', [$userId]) ?? [];
            $input += [
                'loan_from' => $defaults['library_name'] ?? null,
                'loan_start' => $today,
                'loan_due' => BookService::addDays($today, (int) ($defaults['library_loan_days'] ?? 0) ?: 21),
                'loan_user_id' => $userId,
            ];
        }
        return $this->db->transaction(function () use ($userId, $bookId, $input) {
            $this->db->run('UPDATE books SET wishlist = 0 WHERE id = ?', [$bookId]);
            $this->db->run('DELETE FROM wishes WHERE book_id = ?', [$bookId]);
            return $this->books->update($bookId, $input, $userId);
        });
    }

    /** @return array{visibility: string, token: ?string} */
    public function sharing(int $userId): array
    {
        $row = $this->db->one('SELECT wishlist_visibility, wishlist_token FROM users WHERE id = ?', [$userId]) ?? [];
        $visibility = in_array($row['wishlist_visibility'] ?? '', self::VISIBILITIES, true) ? $row['wishlist_visibility'] : 'private';
        return ['visibility' => $visibility, 'token' => $visibility === 'public' ? $row['wishlist_token'] : null];
    }

    /**
     * Who may see the list. A public link keeps its token until "new link"
     * ($renew) – then old links stop working.
     *
     * @return array{visibility: string, token: ?string}
     */
    public function share(int $userId, string $visibility, bool $renew = false): array
    {
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            throw ApiException::validation(['visibility' => 'invalid_choice']);
        }
        $data = ['wishlist_visibility' => $visibility];
        $current = $this->db->value('SELECT wishlist_token FROM users WHERE id = ?', [$userId]);
        if ($visibility === 'public' && ($renew || !is_string($current) || $current === '')) {
            $data['wishlist_token'] = bin2hex(random_bytes(16));
        }
        $this->db->update('users', $data, ['id' => $userId]);
        return $this->sharing($userId);
    }

    /** @return list<array<string, mixed>> books of $ownerId's list as seen by $viewerId */
    private function items(int $ownerId, int $viewerId): array
    {
        $ids = array_map('intval', array_column(
            $this->db->all('SELECT book_id FROM wishes WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$ownerId]),
            'book_id'
        ));
        $items = [];
        foreach ($ids as $id) {
            $book = $this->books->get($id, $viewerId);
            unset($book['readers']);
            $items[] = $book;
        }
        return $items;
    }

    /** @return array{id: int|string, username: string}|null */
    private function publicOwner(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        return $this->db->one("SELECT id, username FROM users WHERE wishlist_token = ? AND wishlist_visibility = 'public'", [$token]);
    }
}
