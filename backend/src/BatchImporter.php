<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Bulk scan ("beep & continue"): ISBNs are accepted immediately – new books are
 * created as placeholders (title = ISBN, metadata_status = 'pending') in the
 * chosen compartment – and their metadata is looked up asynchronously from the
 * metadata_queue. The queue is worked off by the app while the scanner is open
 * (POST /api/v1/books/batch/process) and by cron, always through the rate-limited
 * MetadataService, so 50+ scans never flood Open Library, DNB or Google Books.
 */
final class BatchImporter
{
    public const MAX_ITEMS = 100;
    /** Retry delays in minutes while the sources are unreachable or rate limited. */
    private const RETRY_MINUTES = [1, 5, 15, 60, 360];
    private const MAX_ATTEMPTS = 8;
    private const LOCK_SECONDS = 120;

    private BookService $books;
    private ShelfService $shelves;

    public function __construct(private readonly Database $db, private ?MetadataService $metadata = null)
    {
        $this->shelves = new ShelfService($db);
        $this->books = new BookService($db, $this->shelves);
    }

    /**
     * @param list<array<string, mixed>> $items    [{isbn, client_id?, shelf_id?, shelf_col?, shelf_row?}]
     * @param array<string, mixed>       $defaults location for items without their own
     * @param bool $moveExisting move books that are already in the catalogue into the scanned compartment
     * @return array{status: string, queued: int, items: list<array<string, mixed>>}
     */
    public function submit(array $items, array $defaults, int $userId, bool $moveExisting = true): array
    {
        if ($items === [] || count($items) > self::MAX_ITEMS) {
            throw ApiException::validation(['items' => $items === [] ? 'required' : 'too_many']);
        }
        $results = [];
        $seen = [];
        $queued = 0;
        $lookupEnabled = Config::bool('METADATA_LOOKUP', true);
        foreach ($items as $item) {
            $item = is_array($item) ? $item : ['isbn' => $item];
            $result = ['client_id' => isset($item['client_id']) ? mb_substr((string) $item['client_id'], 0, 64) : null, 'isbn' => (string) ($item['isbn'] ?? '')];
            [$isbn13, $isbn10] = Isbn::normalize($result['isbn']);
            if ($isbn13 === null) {
                $results[] = $result + ['status' => 'invalid'];
                continue;
            }
            $result['isbn'] = $isbn13;
            if (isset($seen[$isbn13])) {
                $results[] = $result + ['status' => 'duplicate', 'book_id' => $seen[$isbn13]];
                continue;
            }
            try {
                $position = $this->position($item, $defaults);
            } catch (ApiException $e) {
                $results[] = $result + ['status' => 'invalid_location', 'error' => $e->details['errors'] ?? $e->getMessage()];
                continue;
            }

            $existing = $this->db->one('SELECT id, shelf_id, shelf_col, shelf_row, entry_type, status FROM books WHERE isbn13 = ?', [$isbn13]);
            if ($existing !== null) {
                $id = (int) $existing['id'];
                $seen[$isbn13] = $id;
                $status = 'existing';
                $samePlace = (int) $existing['shelf_id'] === (int) $position['shelf_id']
                    && (int) $existing['shelf_col'] === (int) $position['shelf_col']
                    && (int) $existing['shelf_row'] === (int) $position['shelf_row'];
                $result['previous_location'] = $this->books->get($id, $userId)['location'];
                if ($position['shelf_id'] !== null && !$samePlace && $existing['entry_type'] === 'owned' && $moveExisting) {
                    $this->books->move([$id], $position['shelf_id'], $position['shelf_col'], $position['shelf_row']);
                    $status = 'moved';
                }
                $results[] = $result + ['status' => $samePlace ? 'existing_here' : $status, 'book' => $this->compact($id, $userId)];
                continue;
            }

            $now = App::nowString();
            $id = $this->db->insert('books', $position + [
                'isbn13' => $isbn13,
                'isbn10' => $isbn10,
                'title' => $isbn13, // placeholder until the queue found the metadata
                'entry_type' => 'owned',
                'metadata_status' => $lookupEnabled ? 'pending' : 'failed',
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if ($lookupEnabled) {
                $this->db->insert('metadata_queue', [
                    'book_id' => $id,
                    'user_id' => $userId,
                    'isbn' => $isbn13,
                    'status' => 'pending',
                    'next_attempt_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $queued++;
            }
            $seen[$isbn13] = $id;
            $results[] = $result + ['status' => 'created', 'book' => $this->compact($id, $userId)];
        }
        return ['status' => 'queued', 'queued' => $queued, 'items' => $results];
    }

    /**
     * Works off due queue entries within the time budget. Safe to run in parallel
     * (each entry is claimed atomically); stops early when the sources are down.
     *
     * @return array{processed: int, completed: int, failed: int, retry: int, remaining: int}
     */
    public function process(int $limit = 10, float $maxSeconds = 8.0, float $maxWait = 2.0): array
    {
        $start = microtime(true);
        $stats = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'retry' => 0];
        $now = App::nowString();
        // Entries of a crashed or timed-out run become due again.
        $this->db->run(
            "UPDATE metadata_queue SET status = 'pending', locked_until = NULL WHERE status = 'processing' AND locked_until < ?",
            [$now]
        );
        if (!Config::bool('METADATA_LOOKUP', true)) {
            return $stats + ['remaining' => $this->remaining()];
        }
        $metadata = $this->metadata ?? new MetadataService($this->db);
        $metadata->maxWait = $maxWait;

        $due = $this->db->all(
            "SELECT id FROM metadata_queue WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT " . max(1, min(100, $limit)),
            [$now]
        );
        foreach ($due as $row) {
            if (microtime(true) - $start > $maxSeconds) {
                break;
            }
            $claimed = $this->db->run(
                "UPDATE metadata_queue SET status = 'processing', locked_until = ? WHERE id = ? AND status = 'pending'",
                [App::now()->modify('+' . self::LOCK_SECONDS . ' seconds')->format('Y-m-d H:i:s'), $row['id']]
            )->rowCount();
            if ($claimed !== 1) {
                continue; // taken by a parallel run
            }
            $entry = $this->db->one('SELECT * FROM metadata_queue WHERE id = ?', [$row['id']]);
            $book = $entry === null ? null : $this->db->one('SELECT * FROM books WHERE id = ?', [$entry['book_id']]);
            if ($entry === null || $book === null) {
                continue;
            }
            $stats['processed']++;
            try {
                $meta = $metadata->lookup((string) $entry['isbn']);
            } catch (MetadataUnavailable $e) {
                $stats['retry']++;
                $this->retry($entry, $e->status === -1 ? 'rate_limited' : 'unavailable');
                if ($e->status !== -1) {
                    break; // network or server errors: the remaining entries wait for the next run
                }
                continue; // only a paused source is missing: other ISBNs may be found by the remaining ones
            } catch (ApiException) {
                $meta = null;
            }
            if ($meta === null) {
                $stats['failed']++;
                $this->finish($entry, $book, 'failed', 'not_found');
                continue;
            }
            $stats['completed']++;
            $this->finish($entry, $book, 'done', null, $meta);
        }
        return $stats + ['remaining' => $this->remaining()];
    }

    /**
     * Current state of books (e.g. the ones scanned in this session) for the scanner UI.
     *
     * @param list<mixed> $bookIds
     * @return list<array<string, mixed>>
     */
    public function status(array $bookIds, int $userId): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $bookIds)))), 0, 200);
        $books = [];
        foreach ($ids as $id) {
            try {
                $books[] = $this->compact($id, $userId);
            } catch (ApiException) {
                // deleted in the meantime
            }
        }
        return $books;
    }

    /** Entries still waiting (including retries later on). */
    public function remaining(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM metadata_queue WHERE status IN ('pending', 'processing')");
    }

    /** Finished entries are kept a week for reference, then removed (cron). */
    public function cleanup(): int
    {
        return $this->db->run(
            "DELETE FROM metadata_queue WHERE status IN ('done', 'failed') AND updated_at < ?",
            [App::now()->modify('-7 days')->format('Y-m-d H:i:s')]
        )->rowCount();
    }

    /**
     * @param array<string, mixed>      $entry
     * @param array<string, mixed>      $book
     * @param array<string, mixed>|null $meta
     */
    private function finish(array $entry, array $book, string $status, ?string $error, ?array $meta = null): void
    {
        $now = App::nowString();
        $this->db->transaction(function () use ($entry, $book, $status, $error, $meta, $now): void {
            $changes = [
                'metadata_status' => $status === 'done' ? null : 'failed',
                'metadata_attempts' => (int) $book['metadata_attempts'] + 1,
                'metadata_attempted_at' => $now,
                'updated_at' => $now,
            ];
            if ($meta !== null) {
                $changes += MetadataService::fillChanges($book, $meta, withTitle: true);
                // Partial answers (a source was down) are completed later by the regular enrichment.
                if (empty($meta['partial'])) {
                    $changes['metadata_checked_at'] = $now;
                }
            } else {
                $changes['metadata_checked_at'] = $now;
            }
            $this->db->update('books', $changes, ['id' => (int) $book['id']]);
            $this->db->update('metadata_queue', [
                'status' => $status,
                'attempts' => (int) $entry['attempts'] + 1,
                'locked_until' => null,
                'error_message' => $error,
                'updated_at' => $now,
            ], ['id' => (int) $entry['id']]);
        });
    }

    /** @param array<string, mixed> $entry */
    private function retry(array $entry, string $reason): void
    {
        $attempts = (int) $entry['attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $book = $this->db->one('SELECT * FROM books WHERE id = ?', [$entry['book_id']]);
            if ($book !== null) {
                $this->finish($entry, $book, 'failed', $reason);
            }
            return;
        }
        $minutes = self::RETRY_MINUTES[min($attempts - 1, count(self::RETRY_MINUTES) - 1)];
        $this->db->update('metadata_queue', [
            'status' => 'pending',
            'attempts' => $attempts,
            'locked_until' => null,
            'next_attempt_at' => App::now()->modify("+$minutes minutes")->format('Y-m-d H:i:s'),
            'error_message' => $reason,
            'updated_at' => App::nowString(),
        ], ['id' => (int) $entry['id']]);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $defaults
     * @return array{shelf_id: ?int, shelf_col: ?int, shelf_row: ?int}
     */
    private function position(array $item, array $defaults): array
    {
        $source = array_key_exists('shelf_id', $item) ? $item : $defaults;
        if (($source['shelf_id'] ?? null) === null || $source['shelf_id'] === '') {
            return ['shelf_id' => null, 'shelf_col' => null, 'shelf_row' => null];
        }
        return $this->shelves->resolvePosition($source['shelf_id'], $source['shelf_col'] ?? null, $source['shelf_row'] ?? null);
    }

    /** @return array<string, mixed> */
    private function compact(int $id, int $userId): array
    {
        $book = $this->books->get($id, $userId);
        unset($book['description'], $book['readers']);
        return $book;
    }
}
