<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Re-reads: earlier reads of a book per user ("Erneut gelesen von … bis …").
 *
 * The current read stays in user_books (status, progress, start/end); earlier,
 * finished reads are kept in book_reads. Statistics count every finished read,
 * so a book read three times counts three times in the year it was read.
 */
final class ReadHistoryService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<int, list<array{id: int, started_at: ?string, finished_at: ?string}>> earlier reads per book id, newest first */
    public function forUser(int $userId, ?int $bookId = null): array
    {
        $sql = 'SELECT id, book_id, started_at, finished_at FROM book_reads WHERE user_id = ?'
            . ($bookId === null ? '' : ' AND book_id = ?')
            . ' ORDER BY finished_at IS NULL, finished_at DESC, id DESC';
        $reads = [];
        foreach ($this->db->all($sql, $bookId === null ? [$userId] : [$userId, $bookId]) as $row) {
            $reads[(int) $row['book_id']][] = ['id' => (int) $row['id'], 'started_at' => $row['started_at'], 'finished_at' => $row['finished_at']];
        }
        return $reads;
    }

    /**
     * Adds an earlier read entered afterwards.
     *
     * @return list<array{id: int, started_at: ?string, finished_at: ?string}>
     */
    public function add(int $userId, int $bookId, array $input): array
    {
        BookService::assertWritable($this->db, $bookId, $userId);
        $started = Dates::parse($input['started_at'] ?? null);
        $finished = Dates::parse($input['finished_at'] ?? null);
        $errors = [];
        if ($started === false) {
            $errors['started_at'] = 'invalid_date';
        }
        if ($finished === false) {
            $errors['finished_at'] = 'invalid_date';
        } elseif ($finished === null) {
            $errors['finished_at'] = 'required';
        } elseif ($finished > App::today()) {
            $errors['finished_at'] = 'in_future';
        } elseif (is_string($started) && $finished < $started) {
            $errors['finished_at'] = 'before_start';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $this->db->insert('book_reads', [
            'user_id' => $userId, 'book_id' => $bookId, 'started_at' => $started, 'finished_at' => $finished, 'created_at' => App::nowString(),
        ]);
        $this->touch($userId, $bookId);
        return $this->forUser($userId, $bookId)[$bookId] ?? [];
    }

    /**
     * "Read again": the finished current read moves into the history and a new
     * read starts today.
     *
     * @return array{personal: array<string, mixed>, reads: list<array{id: int, started_at: ?string, finished_at: ?string}>}
     */
    public function readAgain(int $userId, int $bookId): array
    {
        BookService::assertWritable($this->db, $bookId, $userId);
        $this->db->transaction(function () use ($userId, $bookId) {
            $current = $this->db->one('SELECT * FROM user_books WHERE user_id = ? AND book_id = ?', [$userId, $bookId]);
            if (($current['reading_status'] ?? null) !== 'read') {
                throw ApiException::validation(['reading_status' => 'not_read_yet']);
            }
            $this->db->insert('book_reads', [
                'user_id' => $userId, 'book_id' => $bookId,
                'started_at' => $current['started_at'], 'finished_at' => $current['finished_at'], 'created_at' => App::nowString(),
            ]);
            $this->db->update('user_books', [
                'reading_status' => 'reading', 'pages_read' => 0, 'started_at' => App::today(), 'finished_at' => null, 'updated_at' => App::nowString(),
            ], ['id' => (int) $current['id']]);
        });
        return [
            'personal' => (new UserBookService($this->db))->get($userId, $bookId),
            'reads' => $this->forUser($userId, $bookId)[$bookId] ?? [],
        ];
    }

    /** @return list<array{id: int, started_at: ?string, finished_at: ?string}> */
    public function delete(int $userId, int $bookId, int $readId): array
    {
        $deleted = $this->db->run('DELETE FROM book_reads WHERE id = ? AND user_id = ? AND book_id = ?', [$readId, $userId, $bookId])->rowCount();
        if ($deleted === 0) {
            throw ApiException::notFound('read_not_found');
        }
        $this->touch($userId, $bookId);
        return $this->forUser($userId, $bookId)[$bookId] ?? [];
    }

    /** Offline copies refresh the book (catalogue sync goes by updated_at). */
    private function touch(int $userId, int $bookId): void
    {
        $this->db->run('UPDATE user_books SET updated_at = ? WHERE user_id = ? AND book_id = ?', [App::nowString(), $userId, $bookId]);
        $this->db->run('UPDATE books SET updated_at = ? WHERE id = ?', [App::nowString(), $bookId]);
    }
}
