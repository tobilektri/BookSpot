<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Global book catalogue (shared by all users). Personal data lives in user_books
 * and is merged into the responses for the current user only.
 */
final class BookService
{
    public const ENTRY_TYPES = ['owned', 'library_loan', 'personal_loan'];
    public const STATUSES = ['active', 'returned', 'discarded'];

    private const TEXT_FIELDS = [
        'title' => 500, 'authors' => 255, 'publisher' => 150, 'genre' => 100, 'language' => 10,
        'format' => 30, 'loan_from' => 150, 'lent_to' => 100, 'asin' => 10,
    ];
    private const DATE_FIELDS = ['acquired_at', 'loan_start', 'loan_due', 'lent_at'];

    /** @var array<int, array<string, mixed>> */
    private array $shelfCache = [];

    public function __construct(private readonly Database $db, private readonly ShelfService $shelves)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByIsbn(string $raw, int $userId): ?array
    {
        [$isbn13, $isbn10] = Isbn::normalize($raw);
        if ($isbn13 === null && $isbn10 === null) {
            throw ApiException::validation(['isbn' => 'invalid_isbn']);
        }
        $row = $this->db->one(
            'SELECT id FROM books WHERE isbn13 = ? OR (isbn10 IS NOT NULL AND isbn10 = ?) ORDER BY id LIMIT 1',
            [$isbn13 ?? '', $isbn10 ?? '']
        );
        return $row === null ? null : $this->get((int) $row['id'], $userId);
    }

    /** @return array<string, mixed> */
    public function get(int $id, int $userId): array
    {
        $row = $this->db->one(
            'SELECT b.*, ub.reading_status, ub.rating, ub.pages_read, ub.started_at, ub.finished_at, ub.notes,
                    ub.updated_at AS personal_updated_at, u.username AS loan_username, c.username AS created_by_name
             FROM books b
             LEFT JOIN user_books ub ON ub.book_id = b.id AND ub.user_id = ?
             LEFT JOIN users u ON u.id = b.loan_user_id
             LEFT JOIN users c ON c.id = b.created_by
             WHERE b.id = ?',
            [$userId, $id]
        ) ?? throw ApiException::notFound('book_not_found');

        $book = $this->present($row);
        // Other readers of the shared copy (only status, no private notes).
        $book['readers'] = array_map(fn ($r) => [
            'username' => $r['username'],
            'reading_status' => $r['reading_status'],
            'rating' => $r['rating'] === null ? null : (int) $r['rating'],
        ], $this->db->all(
            "SELECT u.username, ub.reading_status, ub.rating FROM user_books ub JOIN users u ON u.id = ub.user_id
             WHERE ub.book_id = ? AND ub.user_id <> ? AND ub.reading_status <> 'unread' ORDER BY u.username",
            [$id, $userId]
        ));
        return $book;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(array $filters, int $userId): array
    {
        $where = [];
        $params = ['uid' => $userId];

        $status = (string) ($filters['status'] ?? 'active');
        if ($status !== 'all') {
            $where[] = 'b.status = :status';
            $params['status'] = in_array($status, self::STATUSES, true) ? $status : 'active';
        }
        if (!empty($filters['q'])) {
            $q = (string) $filters['q'];
            $clean = Isbn::clean($q);
            $parts = ["b.title LIKE :q ESCAPE '!'", "b.authors LIKE :q ESCAPE '!'", "b.genre LIKE :q ESCAPE '!'"];
            if (strlen($clean) >= 4 && ctype_digit(rtrim($clean, 'X'))) {
                $parts[] = "b.isbn13 LIKE :isbnq ESCAPE '!'";
                $parts[] = "b.isbn10 LIKE :isbnq ESCAPE '!'";
                $params['isbnq'] = Database::likePattern($clean);
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
            $params['q'] = Database::likePattern($q);
        }
        if (!empty($filters['entry_type']) && in_array($filters['entry_type'], self::ENTRY_TYPES, true)) {
            $where[] = 'b.entry_type = :entry_type';
            $params['entry_type'] = $filters['entry_type'];
        }
        if (!empty($filters['reading_status'])) {
            if ($filters['reading_status'] === 'unread') {
                $where[] = "(ub.reading_status IS NULL OR ub.reading_status = 'unread')";
            } elseif (in_array($filters['reading_status'], ['reading', 'read', 'abandoned'], true)) {
                $where[] = 'ub.reading_status = :reading_status';
                $params['reading_status'] = $filters['reading_status'];
            }
        }
        if (!empty($filters['genre'])) {
            $where[] = 'b.genre = :genre';
            $params['genre'] = (string) $filters['genre'];
        }
        if (!empty($filters['unplaced'])) {
            $where[] = "b.shelf_id IS NULL AND b.entry_type = 'owned'";
        }
        if (!empty($filters['lent'])) {
            $where[] = "b.lent_to IS NOT NULL AND b.lent_to <> ''";
        }
        if (isset($filters['shelf_id']) && is_numeric($filters['shelf_id'])) {
            $where[] = 'b.shelf_id = :shelf_id';
            $params['shelf_id'] = (int) $filters['shelf_id'];
            if (isset($filters['col'], $filters['row']) && is_numeric($filters['col']) && is_numeric($filters['row'])) {
                $where[] = 'b.shelf_col = :col AND b.shelf_row = :row';
                $params['col'] = (int) $filters['col'];
                $params['row'] = (int) $filters['row'];
            }
        }
        if (!empty($filters['updated_since'])) {
            $where[] = 'b.updated_at >= :updated_since';
            $params['updated_since'] = (string) $filters['updated_since'];
        }

        $order = match ((string) ($filters['sort'] ?? 'title')) {
            'authors' => 'b.authors, b.title',
            'recent' => 'b.created_at DESC, b.id DESC',
            'pages' => 'b.pages DESC, b.title',
            'year' => 'b.published_year DESC, b.title',
            'due' => 'b.loan_due IS NULL, b.loan_due, b.title',
            'updated' => 'COALESCE(ub.updated_at, b.updated_at) DESC',
            default => 'b.title, b.authors',
        };
        $limit = max(1, min(5000, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $from = 'FROM books b LEFT JOIN user_books ub ON ub.book_id = b.id AND ub.user_id = :uid'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        $total = (int) $this->db->value("SELECT COUNT(*) $from", $params);
        $rows = $this->db->all(
            "SELECT b.*, ub.reading_status, ub.rating, ub.pages_read, ub.started_at, ub.finished_at, ub.notes,
                    ub.updated_at AS personal_updated_at
             $from ORDER BY $order LIMIT $limit OFFSET $offset",
            $params
        );
        $items = array_map($this->present(...), $rows);
        if (!empty($filters['compact'])) {
            // Lean payload for the offline catalogue cache.
            foreach ($items as &$item) {
                unset($item['description']);
            }
            unset($item);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @return list<array<string, mixed>> active library/private loans ordered by due date */
    public function dueLoans(int $userId, int $limit = 50): array
    {
        $rows = $this->db->all(
            "SELECT b.*, ub.reading_status, ub.rating, ub.pages_read, ub.started_at, ub.finished_at, ub.notes,
                    u.username AS loan_username
             FROM books b
             LEFT JOIN user_books ub ON ub.book_id = b.id AND ub.user_id = :uid
             LEFT JOIN users u ON u.id = b.loan_user_id
             WHERE b.status = 'active' AND b.entry_type IN ('library_loan', 'personal_loan')
             ORDER BY b.loan_due IS NULL, b.loan_due, b.title LIMIT $limit",
            ['uid' => $userId]
        );
        return array_map($this->present(...), $rows);
    }

    /** @return list<array{genre: string, count: int}> */
    public function genres(): array
    {
        return array_map(
            fn ($r) => ['genre' => $r['genre'], 'count' => (int) $r['n']],
            $this->db->all("SELECT genre, COUNT(*) AS n FROM books WHERE genre IS NOT NULL AND genre <> '' GROUP BY genre ORDER BY n DESC, genre")
        );
    }

    /** @return array<string, mixed> */
    public function create(array $input, int $userId): array
    {
        $data = $this->validate($input, null, $userId);
        if (!empty($data['isbn13'])) {
            $existing = $this->db->value('SELECT id FROM books WHERE isbn13 = ?', [$data['isbn13']]);
            if ($existing !== null) {
                throw new ApiException('duplicate_isbn', 409, ['book_id' => (int) $existing]);
            }
        }
        $now = App::nowString();
        $id = $this->db->insert('books', $data + ['created_by' => $userId, 'created_at' => $now, 'updated_at' => $now]);

        // Personal data may be submitted together with the catalogue entry (scan -> "add & mark as reading").
        if (isset($input['personal']) && is_array($input['personal'])) {
            (new UserBookService($this->db))->upsert($userId, $id, $input['personal']);
        }
        return $this->get($id, $userId);
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $input, int $userId): array
    {
        $existing = $this->db->one('SELECT * FROM books WHERE id = ?', [$id]) ?? throw ApiException::notFound('book_not_found');
        $data = $this->validate($input, $existing, $userId);
        if (!empty($data['isbn13']) && $data['isbn13'] !== $existing['isbn13']) {
            $dupe = $this->db->value('SELECT id FROM books WHERE isbn13 = ? AND id <> ?', [$data['isbn13'], $id]);
            if ($dupe !== null) {
                throw new ApiException('duplicate_isbn', 409, ['book_id' => (int) $dupe]);
            }
        }
        $data['updated_at'] = App::nowString();
        $this->db->update('books', $data, ['id' => $id]);
        $coverSourceChanged = (array_key_exists('cover_url', $data) && $data['cover_url'] !== $existing['cover_url'])
            || (array_key_exists('isbn13', $data) && $data['isbn13'] !== $existing['isbn13']);
        if ($coverSourceChanged) {
            (new CoverService($this->db))->forget($id, $existing['cover_file'] ?? null);
        }
        return $this->get($id, $userId);
    }

    /**
     * Moves several books into one compartment (drag & drop, bulk actions).
     *
     * @param list<int> $ids
     */
    public function move(array $ids, mixed $shelfId, mixed $col, mixed $row): int
    {
        $position = $this->shelves->resolvePosition($shelfId, $col, $row);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->run(
            "UPDATE books SET shelf_id = ?, shelf_col = ?, shelf_row = ?, updated_at = ? WHERE id IN ($placeholders)",
            [$position['shelf_id'], $position['shelf_col'], $position['shelf_row'], App::nowString(), ...$ids]
        )->rowCount();
    }

    public function delete(int $id): void
    {
        $file = $this->db->value('SELECT cover_file FROM books WHERE id = ?', [$id]);
        if ($this->db->run('DELETE FROM books WHERE id = ?', [$id])->rowCount() === 0) {
            throw ApiException::notFound('book_not_found');
        }
        if (is_string($file) && $file !== '') {
            @unlink(CoverService::dir() . '/' . basename($file));
        }
    }

    /**
     * Validates and normalises input. On update ($existing given) only
     * submitted keys are changed.
     *
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    public function validate(array $input, ?array $existing, int $userId): array
    {
        $data = [];
        $errors = [];
        $isUpdate = $existing !== null;
        $has = fn (string $k) => array_key_exists($k, $input);

        // ISBN: "isbn" accepts any format, explicit isbn13/isbn10 are normalised as well.
        $isbnInput = $input['isbn'] ?? $input['isbn13'] ?? null;
        if ($has('isbn') || $has('isbn13') || !$isUpdate) {
            if ($isbnInput === null || trim((string) $isbnInput) === '') {
                $data['isbn13'] = null;
                $data['isbn10'] = $has('isbn10') ? (Isbn::normalize((string) $input['isbn10'])[1]) : null;
            } else {
                [$isbn13, $isbn10] = Isbn::normalize((string) $isbnInput);
                if ($isbn13 === null) {
                    $errors['isbn'] = 'invalid_isbn';
                } else {
                    $data['isbn13'] = $isbn13;
                    $data['isbn10'] = $isbn10;
                }
            }
        }

        foreach (self::TEXT_FIELDS as $field => $max) {
            if ($has($field) || (!$isUpdate && $field === 'authors')) {
                $value = trim(preg_replace('/\s+/u', ' ', (string) ($input[$field] ?? '')) ?? '');
                $data[$field] = $value === '' ? ($field === 'authors' ? '' : null) : mb_substr($value, 0, $max);
            }
        }
        if (!$isUpdate || $has('title')) {
            if (empty($data['title'])) {
                $errors['title'] = 'required';
            }
        }
        if ($has('description')) {
            $description = trim((string) $input['description']);
            $data['description'] = $description === '' ? null : mb_substr($description, 0, 20000);
        }
        if ($has('cover_url')) {
            $url = trim((string) $input['cover_url']);
            if ($url === '') {
                $data['cover_url'] = null;
            } elseif (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 500) {
                $errors['cover_url'] = 'invalid_url';
            } else {
                $data['cover_url'] = preg_replace('#^http://#i', 'https://', $url);
            }
        }
        if ($has('pages') || !$isUpdate) {
            $pages = $input['pages'] ?? 0;
            $data['pages'] = is_numeric($pages) ? max(0, min(100000, (int) $pages)) : 0;
        }
        if ($has('published_year')) {
            $year = $input['published_year'];
            $data['published_year'] = (is_numeric($year) && (int) $year > 0 && (int) $year < 3000) ? (int) $year : null;
        }
        if ($has('price')) {
            $price = str_replace(',', '.', trim((string) $input['price']));
            $data['price'] = is_numeric($price) && (float) $price >= 0 ? round((float) $price, 2) : null;
        }
        foreach (self::DATE_FIELDS as $field) {
            if ($has($field)) {
                $value = Dates::parse($input[$field]);
                if ($value === false) {
                    $errors[$field] = 'invalid_date';
                } else {
                    $data[$field] = $value;
                }
            }
        }
        if ($has('entry_type') || !$isUpdate) {
            $type = (string) ($input['entry_type'] ?? 'owned');
            if (!in_array($type, self::ENTRY_TYPES, true)) {
                $errors['entry_type'] = 'invalid_choice';
            }
            $data['entry_type'] = $type;
        }
        if ($has('status')) {
            if (!in_array($input['status'], self::STATUSES, true)) {
                $errors['status'] = 'invalid_choice';
            } else {
                $data['status'] = $input['status'];
            }
        }
        if ($has('loan_user_id')) {
            $data['loan_user_id'] = is_numeric($input['loan_user_id']) && (int) $input['loan_user_id'] > 0 ? (int) $input['loan_user_id'] : null;
        }
        if ($has('shelf_id') || $has('shelf_col') || $has('shelf_row')) {
            try {
                $data += $this->shelves->resolvePosition(
                    $input['shelf_id'] ?? $existing['shelf_id'] ?? null,
                    $input['shelf_col'] ?? null,
                    $input['shelf_row'] ?? null,
                );
            } catch (ApiException $e) {
                $errors += $e->details['errors'] ?? ['shelf_id' => 'invalid'];
            }
        }

        // Loans: a library loan needs a due date; the borrowing user defaults to the creator.
        $type = $data['entry_type'] ?? $existing['entry_type'] ?? 'owned';
        if ($type === 'library_loan') {
            $due = array_key_exists('loan_due', $data) ? $data['loan_due'] : ($existing['loan_due'] ?? null);
            if ($due === null) {
                $errors['loan_due'] = 'required';
            }
        }
        if ($type !== 'owned' && !$isUpdate && !array_key_exists('loan_user_id', $data)) {
            $data['loan_user_id'] = $userId;
        }
        if ($type !== 'owned' && !$isUpdate && !array_key_exists('loan_start', $data)) {
            $data['loan_start'] = App::today();
        }
        if ($type === 'owned' && array_key_exists('entry_type', $data) && $isUpdate && $existing['entry_type'] !== 'owned') {
            // Converted from loan to owned (e.g. bought the book): clear loan fields.
            $data += ['loan_from' => null, 'loan_start' => null, 'loan_due' => null, 'loan_user_id' => null];
        }
        if (array_key_exists('lent_to', $data) && $data['lent_to'] !== null && !array_key_exists('lent_at', $data) && empty($existing['lent_at'])) {
            $data['lent_at'] = App::today();
        }
        if (array_key_exists('lent_to', $data) && $data['lent_to'] === null) {
            $data['lent_at'] = null;
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $row books row optionally joined with user_books columns
     * @return array<string, mixed>
     */
    public function present(array $row): array
    {
        $int = fn ($v) => $v === null ? null : (int) $v;
        $today = new \DateTimeImmutable(App::today());
        $daysLeft = null;
        if (!empty($row['loan_due'])) {
            $daysLeft = (int) $today->diff(new \DateTimeImmutable((string) $row['loan_due']))->format('%r%a');
        }
        $book = [
            'id' => (int) $row['id'],
            'isbn13' => $row['isbn13'],
            'isbn10' => $row['isbn10'],
            'asin' => $row['asin'],
            'title' => $row['title'],
            'authors' => $row['authors'],
            'publisher' => $row['publisher'],
            'published_year' => $int($row['published_year']),
            'pages' => (int) $row['pages'],
            'genre' => $row['genre'],
            'language' => $row['language'],
            'description' => $row['description'],
            'cover_url' => $row['cover_url'],
            // Covers are served locally by api/cover.php; the version changes with the source.
            'cover_version' => CoverService::version($row),
            'has_cover' => ($row['cover_status'] ?? null) !== 'none',
            'format' => $row['format'],
            'price' => $row['price'] === null ? null : (float) $row['price'],
            'acquired_at' => $row['acquired_at'],
            'entry_type' => $row['entry_type'],
            'shelf_id' => $int($row['shelf_id']),
            'shelf_col' => $int($row['shelf_col']),
            'shelf_row' => $int($row['shelf_row']),
            'location' => $this->locationLabel($int($row['shelf_id']), $int($row['shelf_col']), $int($row['shelf_row'])),
            'loan_from' => $row['loan_from'],
            'loan_start' => $row['loan_start'],
            'loan_due' => $row['loan_due'],
            'loan_days_left' => $daysLeft,
            'loan_user_id' => $int($row['loan_user_id']),
            'loan_username' => $row['loan_username'] ?? null,
            'lent_to' => $row['lent_to'],
            'lent_at' => $row['lent_at'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'personal' => [
                'reading_status' => $row['reading_status'] ?? 'unread',
                'rating' => $int($row['rating'] ?? null),
                'pages_read' => (int) ($row['pages_read'] ?? 0),
                'started_at' => $row['started_at'] ?? null,
                'finished_at' => $row['finished_at'] ?? null,
                'notes' => $row['notes'] ?? null,
                'updated_at' => $row['personal_updated_at'] ?? null,
            ],
        ];
        if (array_key_exists('created_by_name', $row)) {
            $book['created_by_name'] = $row['created_by_name'];
        }
        return $book;
    }

    /** @return array{shelf: string, compartment: ?string, column: ?string}|null */
    private function locationLabel(?int $shelfId, ?int $col, ?int $row): ?array
    {
        if ($shelfId === null) {
            return null;
        }
        if (!isset($this->shelfCache[$shelfId])) {
            $shelf = $this->db->one('SELECT name, columns_config FROM shelves WHERE id = ?', [$shelfId]);
            $this->shelfCache[$shelfId] = $shelf === null ? [] : [
                'name' => $shelf['name'],
                'columns' => ShelfService::decodeColumns($shelf['columns_config']),
            ];
        }
        $shelf = $this->shelfCache[$shelfId];
        if ($shelf === []) {
            return null;
        }
        $column = $col !== null ? ($shelf['columns'][$col - 1] ?? null) : null;
        return [
            'shelf' => $shelf['name'],
            'column' => $column['label'] ?? null,
            'compartment' => ($column !== null && $row !== null) ? ($column['rows'][$row - 1] ?? null) : null,
        ];
    }
}
