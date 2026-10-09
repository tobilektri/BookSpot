<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Personal, per-user reading data (status, rating, progress, dates, notes).
 */
final class UserBookService
{
    public const READING_STATUSES = ['unread', 'reading', 'read', 'abandoned'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function get(int $userId, int $bookId): array
    {
        $row = $this->db->one('SELECT * FROM user_books WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
        return self::present($row);
    }

    /**
     * Creates or updates the personal entry. Only submitted keys change.
     *
     * When `client_updated_at` is given (offline sync) and the stored entry was
     * changed after that moment, the update is rejected as a conflict so a stale
     * offline edit never overwrites a newer one from another device.
     *
     * @return array{status: 'saved'|'conflict', personal: array<string, mixed>}
     */
    public function upsert(int $userId, int $bookId, array $input, bool $autoFill = true): array
    {
        return $this->db->transaction(function () use ($userId, $bookId, $input, $autoFill) {
            $book = $this->db->one('SELECT id, pages FROM books WHERE id = ?', [$bookId]) ?? throw ApiException::notFound('book_not_found');
            $existing = $this->db->one('SELECT * FROM user_books WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);

            if ($existing !== null && !empty($input['client_updated_at'])) {
                try {
                    $client = new \DateTimeImmutable((string) $input['client_updated_at']);
                    $server = new \DateTimeImmutable((string) $existing['updated_at'], App::timezone());
                    if ($server > $client) {
                        return ['status' => 'conflict', 'personal' => self::present($existing)];
                    }
                } catch (\Exception) {
                    // Unparseable client timestamp: treat as a normal update.
                }
            }
            unset($input['client_updated_at']);

            $data = $this->validate($input);
            $current = array_merge(self::present($existing), $data);
            $pages = (int) $book['pages'];

            if ($autoFill && array_key_exists('reading_status', $data)) {
                $previous = $existing['reading_status'] ?? 'unread';
                if ($data['reading_status'] === 'reading' && $previous !== 'reading' && empty($current['started_at'])) {
                    $data['started_at'] = App::today();
                }
                if ($data['reading_status'] === 'read' && $previous !== 'read') {
                    if (empty($current['finished_at'])) {
                        $data['finished_at'] = App::today();
                    }
                    if (!array_key_exists('pages_read', $data) && $pages > 0) {
                        $data['pages_read'] = $pages;
                    }
                }
            }
            if ($autoFill && isset($data['pages_read']) && !array_key_exists('reading_status', $data)
                && ($existing['reading_status'] ?? 'unread') === 'unread' && $data['pages_read'] > 0) {
                // Logging progress on an unread book means you started reading it.
                $data['reading_status'] = 'reading';
                if (empty($current['started_at'])) {
                    $data['started_at'] = App::today();
                }
            }

            $data['updated_at'] = App::nowString();
            if ($existing === null) {
                $this->db->insert('user_books', $data + ['user_id' => $userId, 'book_id' => $bookId]);
            } else {
                $this->db->update('user_books', $data, ['id' => (int) $existing['id']]);
            }
            return ['status' => 'saved', 'personal' => $this->get($userId, $bookId)];
        });
    }

    /** @return array<string, mixed> */
    private function validate(array $input): array
    {
        $data = [];
        $errors = [];
        if (array_key_exists('reading_status', $input)) {
            if (!in_array($input['reading_status'], self::READING_STATUSES, true)) {
                $errors['reading_status'] = 'invalid_choice';
            } else {
                $data['reading_status'] = $input['reading_status'];
            }
        }
        if (array_key_exists('rating', $input)) {
            $rating = $input['rating'];
            if ($rating === null || $rating === '' || (int) $rating === 0) {
                $data['rating'] = null;
            } elseif (!is_numeric($rating) || (int) $rating < 1 || (int) $rating > 5) {
                $errors['rating'] = 'invalid_rating';
            } else {
                $data['rating'] = (int) $rating;
            }
        }
        if (array_key_exists('pages_read', $input)) {
            $data['pages_read'] = is_numeric($input['pages_read']) ? max(0, min(100000, (int) $input['pages_read'])) : 0;
        }
        foreach (['started_at', 'finished_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $date = Dates::parse($input[$field]);
                if ($date === false) {
                    $errors[$field] = 'invalid_date';
                } else {
                    $data[$field] = $date;
                }
            }
        }
        if (array_key_exists('notes', $input)) {
            $notes = trim((string) $input['notes']);
            $data['notes'] = $notes === '' ? null : mb_substr($notes, 0, 10000);
        }
        if (isset($data['started_at'], $data['finished_at']) && $data['finished_at'] < $data['started_at']) {
            $errors['finished_at'] = 'before_start';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return $data;
    }

    /** @param array<string, mixed>|null $row */
    public static function present(?array $row): array
    {
        return [
            'reading_status' => $row['reading_status'] ?? 'unread',
            'rating' => isset($row['rating']) ? (int) $row['rating'] : null,
            'pages_read' => (int) ($row['pages_read'] ?? 0),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'notes' => $row['notes'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
