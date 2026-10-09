<?php

declare(strict_types=1);

namespace BookSpot\Import;

use BookSpot\App;
use BookSpot\Config;
use BookSpot\Database;
use BookSpot\Dates;
use BookSpot\Isbn;
use BookSpot\ShelfService;

/**
 * Imports Bookstats (and generic) CSV exports.
 *
 * For every row:
 *  1. normalise the ISBN and look the book up in the global catalogue
 *     (ISBN-13, ISBN-10, then title + author for rows without ISBN),
 *  2. create the catalogue entry if missing (empty catalogue fields of
 *     existing books are filled in, nothing is overwritten),
 *  3. resolve the location ("Standort" column, or a code like "L2" in
 *     "Notizen") against the named shelf compartments,
 *  4. create/update the personal user_books row of the importing user.
 *
 * Missing covers/descriptions are fetched later in small batches
 * (MetadataService::enrichBatch) so the import itself stays fast enough for
 * shared-hosting time limits.
 */
final class BookImporter
{
    /** Values Bookstats/Amazon put into "Genre" that are no genre at all. */
    private const GENRE_BLOCKLIST = [
        'taschenbücher', 'taschenbuch', 'hardcover', 'bücher', 'books', 'books all', 'top100', 'top 100',
        'bücher mit videotrailer', 'testx', 'audible app für android', 'fachbücher_ohnekindle',
        'top-buchempfehlungen', 'fremdsprachige ebooks', 'ravensburger', 'dopa', 'autoren', 'kindle ebooks',
        'undefined', 'unbekannt', 'unknown', 'sonstiges', 'neuerscheinungen',
    ];
    private const GENRE_BLOCK_PATTERNS = [
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', // Amazon browse-node ids
        '/^jahresbestseller/i',
        '/^gratis-?premiumversand/i',
        '/^ab \d+ jahren$/i',
        '/^\d+$/',
    ];

    /** @var array<string, array{shelf_id: int, shelf_col: int, shelf_row: int, label: string}> */
    private array $compartments = [];
    /** @var array<string, mixed> */
    private array $summary = [];
    /** @var array<string, true> */
    private array $seenInFile = [];

    /**
     * @param array{dry_run?: bool, overwrite_personal?: bool, overwrite_location?: bool, create_shelves?: bool, shelf_name?: string, import_personal?: bool} $options
     */
    public function __construct(
        private readonly Database $db,
        private readonly int $userId,
        private readonly array $options = [],
    ) {
    }

    /**
     * @param list<array<string, string>> $rows from CsvReader
     * @return array<string, mixed> summary
     */
    public function import(array $rows): array
    {
        $this->summary = [
            'total_rows' => count($rows),
            'created' => 0,
            'linked' => 0,
            'updated' => 0,
            'personal_saved' => 0,
            'skipped' => 0,
            'errors' => 0,
            'placed' => 0,
            'unplaced' => 0,
            'unmatched_locations' => [],
            'shelves_created' => [],
            'messages' => [],
            'dry_run' => (bool) ($this->options['dry_run'] ?? false),
        ];
        $this->seenInFile = [];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $shelves = new ShelfService($this->db);
            $this->compartments = $shelves->compartmentIndex();

            if (!empty($this->options['create_shelves'])) {
                $this->createShelfForUnknownCodes($rows, $shelves);
            }

            foreach ($rows as $row) {
                $this->importRow($row);
            }

            arsort($this->summary['unmatched_locations']);
            if ($this->summary['dry_run']) {
                $pdo->rollBack();
            } else {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $this->summary;
    }

    /** @param array<string, string> $row */
    private function importRow(array $row): void
    {
        $line = (int) $row['_row'];
        $title = trim($row['title'] ?? '');
        if ($title === '') {
            $this->message($line, 'skip', 'missing_title');
            $this->summary['skipped']++;
            return;
        }

        try {
            $book = $this->mapBook($row);
            $dedupeKey = $book['isbn13'] ?? ('t:' . mb_strtolower($book['title'] . '|' . $book['authors']));
            if (isset($this->seenInFile[$dedupeKey])) {
                $this->message($line, 'skip', 'duplicate_in_file', $title);
                $this->summary['skipped']++;
                return;
            }
            $this->seenInFile[$dedupeKey] = true;

            [$location, $locationFromNotes] = $this->resolveLocation($row);
            if ($location !== null) {
                $book += ['shelf_id' => $location['shelf_id'], 'shelf_col' => $location['shelf_col'], 'shelf_row' => $location['shelf_row']];
            }

            $existing = $this->findExisting($book);
            if ($existing === null) {
                $now = App::nowString();
                $bookId = $this->db->insert('books', $book + ['created_by' => $this->userId, 'created_at' => $now, 'updated_at' => $now]);
                $this->summary['created']++;
            } else {
                $bookId = (int) $existing['id'];
                $changes = $this->mergeMissing($existing, $book);
                if ($changes !== []) {
                    $changes['updated_at'] = App::nowString();
                    $this->db->update('books', $changes, ['id' => $bookId]);
                    $this->summary['updated']++;
                } else {
                    $this->summary['linked']++;
                }
            }

            if ($location !== null) {
                $this->summary['placed']++;
            } elseif (($book['entry_type'] ?? 'owned') === 'owned' && ($existing['shelf_id'] ?? null) === null) {
                $this->summary['unplaced']++;
            }

            if ($this->options['import_personal'] ?? true) {
                $this->savePersonal($bookId, $row, $book, $locationFromNotes);
            }
        } catch (\Throwable $e) {
            $this->summary['errors']++;
            $this->message($line, 'error', 'row_failed', $title . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $row
     * @return array<string, mixed> books columns
     */
    private function mapBook(array $row): array
    {
        [$isbn13, $isbn10] = Isbn::normalize($row['isbn'] ?? '');
        $asin = strtoupper(trim($row['asin'] ?? ''));
        // Bookstats' ASIN column contains the ISBN-10 for printed books and a B0… id for Kindle editions.
        if ($asin !== '' && $isbn10 === null && Isbn::isValid10($asin)) {
            [$fromAsin13, $isbn10] = Isbn::normalize($asin);
            $isbn13 ??= $fromAsin13;
        }
        if (!empty($row['isbn10']) && $isbn10 === null) {
            [$alt13, $isbn10] = Isbn::normalize($row['isbn10']);
            $isbn13 ??= $alt13;
        }
        $asin = preg_match('/^B[0-9A-Z]{9}$/', $asin) ? $asin : null;

        $year = (int) preg_replace('/\D.*$/', '', $row['published_year'] ?? '');
        if ($year === 0 && preg_match('/(\d{4})/', $row['published_year'] ?? '', $m)) {
            $year = (int) $m[1];
        }
        $price = str_replace(',', '.', preg_replace('/[^\d,.]/', '', $row['price'] ?? '') ?? '');
        $pages = (int) preg_replace('/\D/', '', $row['pages'] ?? '');
        $authors = self::cleanText($row['authors'] ?? '');
        if (in_array(mb_strtolower($authors), ['unbekannt', 'unknown', 'n/a'], true)) {
            $authors = '';
        }
        $format = self::cleanText($row['format'] ?? '');
        if (in_array(mb_strtolower($format), ['undefined', 'unbekannt', ''], true)) {
            $format = null;
        }
        $acquired = Dates::parse($row['acquired_at'] ?? '');
        $coverUrl = trim($row['cover_url'] ?? '');

        return [
            'isbn13' => $isbn13,
            'isbn10' => $isbn10,
            'asin' => $asin,
            'title' => mb_substr(self::cleanText($row['title']), 0, 500),
            'authors' => mb_substr($authors, 0, 255),
            'publisher' => self::nullable(mb_substr(self::cleanText($row['publisher'] ?? ''), 0, 150)),
            'published_year' => ($year > 0 && $year < 3000) ? $year : null,
            'pages' => min($pages, 100000),
            'genre' => $this->cleanGenre($row['genre'] ?? '') ?? $this->cleanGenre($row['category'] ?? ''),
            'language' => self::nullable(mb_substr(trim($row['language'] ?? ''), 0, 10)),
            'description' => self::nullable(trim($row['description'] ?? '')),
            'cover_url' => preg_match('#^https?://#i', $coverUrl) ? mb_substr($coverUrl, 0, 500) : null,
            'format' => $format === null ? null : mb_substr($format, 0, 30),
            'price' => is_numeric($price) && (float) $price > 0 ? round((float) $price, 2) : null,
            'acquired_at' => $acquired === false ? null : $acquired,
            'entry_type' => self::entryType($row['acquired_via'] ?? ''),
            'status' => 'active',
        ];
    }

    /** @return array<string, mixed>|null */
    private function findExisting(array $book): ?array
    {
        if ($book['isbn13'] !== null) {
            $found = $this->db->one('SELECT * FROM books WHERE isbn13 = ?', [$book['isbn13']]);
            if ($found !== null) {
                return $found;
            }
        }
        if ($book['isbn10'] !== null) {
            $found = $this->db->one('SELECT * FROM books WHERE isbn10 = ? ORDER BY id LIMIT 1', [$book['isbn10']]);
            if ($found !== null) {
                return $found;
            }
        }
        if ($book['isbn13'] === null) {
            return $this->db->one(
                'SELECT * FROM books WHERE isbn13 IS NULL AND LOWER(title) = LOWER(?) AND LOWER(authors) = LOWER(?) ORDER BY id LIMIT 1',
                [$book['title'], $book['authors']]
            );
        }
        return null;
    }

    /**
     * Only fills empty catalogue fields of an existing book; the location is
     * replaced only when the "overwrite_location" option is set.
     *
     * @return array<string, mixed>
     */
    private function mergeMissing(array $existing, array $book): array
    {
        $changes = [];
        foreach (['isbn10', 'asin', 'authors', 'publisher', 'published_year', 'genre', 'language', 'description', 'cover_url', 'format', 'price', 'acquired_at'] as $field) {
            if (($existing[$field] === null || $existing[$field] === '') && $book[$field] !== null && $book[$field] !== '') {
                $changes[$field] = $book[$field];
            }
        }
        if ((int) $existing['pages'] === 0 && $book['pages'] > 0) {
            $changes['pages'] = $book['pages'];
        }
        if (isset($book['shelf_id']) && ($existing['shelf_id'] === null || !empty($this->options['overwrite_location']))) {
            if ((int) $existing['shelf_id'] !== $book['shelf_id'] || (int) $existing['shelf_col'] !== $book['shelf_col'] || (int) $existing['shelf_row'] !== $book['shelf_row']) {
                $changes['shelf_id'] = $book['shelf_id'];
                $changes['shelf_col'] = $book['shelf_col'];
                $changes['shelf_row'] = $book['shelf_row'];
            }
        }
        return $changes;
    }

    /**
     * @param array<string, string> $row
     * @return array{0: ?array{shelf_id: int, shelf_col: int, shelf_row: int, label: string}, 1: bool} [position, came from notes column]
     */
    private function resolveLocation(array $row): array
    {
        $candidates = [];
        if (!empty($row['location'])) {
            $candidates[] = [$row['location'], false];
        }
        if (!empty($row['notes'])) {
            $candidates[] = [$row['notes'], true];
        }
        foreach ($candidates as [$value, $fromNotes]) {
            $key = ShelfService::key($value);
            if (isset($this->compartments[$key])) {
                return [$this->compartments[$key], $fromNotes];
            }
            // Only report values that look like a location code; free-text notes are fine.
            if (!$fromNotes || self::looksLikeLocationCode($value)) {
                $label = trim($value);
                $this->summary['unmatched_locations'][$label] = ($this->summary['unmatched_locations'][$label] ?? 0) + 1;
            }
        }
        return [null, false];
    }

    public static function looksLikeLocationCode(string $value): bool
    {
        return (bool) preg_match('/^\s*\p{L}{1,4}\s*[-_ ]?\s*\d{1,3}\s*$/u', $value);
    }

    /** @param array<string, string> $row */
    private function savePersonal(int $bookId, array $row, array $book, bool $notesWereLocation): void
    {
        $existing = $this->db->one('SELECT * FROM user_books WHERE user_id = ? AND book_id = ?', [$this->userId, $bookId]);
        if ($existing !== null && !($this->options['overwrite_personal'] ?? true)) {
            return;
        }

        $status = self::readingStatus($row['reading_status'] ?? '');
        $started = Dates::parse($row['started_at'] ?? '') ?: null;
        $finished = Dates::parse($row['finished_at'] ?? '') ?: null;
        if ($status === null) {
            $status = $finished !== null ? 'read' : ($started !== null ? 'reading' : 'unread');
        }
        $rating = self::rating($row['rating'] ?? '');
        $notes = $notesWereLocation || self::looksLikeLocationCode($row['notes'] ?? '') ? null : self::nullable(trim($row['notes'] ?? ''));

        $data = [
            'reading_status' => $status,
            'rating' => $rating,
            'pages_read' => $status === 'read' ? (int) $book['pages'] : 0,
            'started_at' => $started,
            'finished_at' => $finished,
            'updated_at' => App::nowString(),
        ];
        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        if ($existing === null) {
            $this->db->insert('user_books', $data + ['user_id' => $this->userId, 'book_id' => $bookId]);
        } else {
            // Keep progress/rating the user already tracked in BookSpot if the CSV has no value.
            if ($data['rating'] === null) {
                unset($data['rating']);
            }
            if ($status !== 'read' && (int) $existing['pages_read'] > 0) {
                unset($data['pages_read']);
            }
            foreach (['started_at', 'finished_at'] as $field) {
                if ($data[$field] === null) {
                    unset($data[$field]);
                }
            }
            $this->db->update('user_books', $data, ['id' => (int) $existing['id']]);
        }
        $this->summary['personal_saved']++;
    }

    /**
     * Creates one shelf whose columns/compartments are derived from all location
     * codes in the file that do not match an existing compartment
     * (e.g. L2…L9, M2…M6, R1…R9 -> columns L, M, R with 9, 6 and 9 compartments).
     *
     * @param list<array<string, string>> $rows
     */
    private function createShelfForUnknownCodes(array $rows, ShelfService $shelves): void
    {
        $codes = [];
        foreach ($rows as $row) {
            foreach (['location', 'notes'] as $field) {
                $value = trim($row[$field] ?? '');
                if ($value !== '' && self::looksLikeLocationCode($value) && !isset($this->compartments[ShelfService::key($value)])) {
                    $codes[] = $value;
                }
            }
        }
        $layout = ShelfService::layoutFromCodes(array_values(array_unique($codes)));
        if ($layout === []) {
            return;
        }
        $name = trim((string) ($this->options['shelf_name'] ?? '')) ?: 'Import ' . App::today();
        $shelf = $shelves->create(['name' => $name, 'columns' => $layout, 'description' => 'CSV-Import ' . App::today()]);
        $this->summary['shelves_created'][] = ['id' => $shelf['id'], 'name' => $shelf['name'], 'columns' => array_map(
            fn ($c) => ['label' => $c['label'], 'rows' => count($c['rows'])],
            $shelf['columns']
        )];
        $this->compartments = $shelves->compartmentIndex();
    }

    public static function readingStatus(string $value): ?string
    {
        $v = mb_strtolower(trim($value));
        if ($v === '') {
            return null;
        }
        return match (true) {
            (bool) preg_match('/abgebrochen|abandon|dnf|did-not-finish|aufgegeben/u', $v) => 'abandoned',
            (bool) preg_match('/ungelesen|unread|to-read|nicht gelesen|wunsch|sub\b|noch nicht|want/u', $v) => 'unread',
            (bool) preg_match('/gerade|currently|am lesen|lese ich|lesend|reading|aktuell/u', $v) => 'reading',
            (bool) preg_match('/gelesen|read|beendet|fertig|finished|done/u', $v) => 'read',
            default => null,
        };
    }

    public static function entryType(string $value): string
    {
        $v = mb_strtolower(trim($value));
        return match (true) {
            (bool) preg_match('/bibliothek|bücherei|buecherei|library|onleihe/u', $v) => 'library_loan',
            (bool) preg_match('/geliehen|leihgabe|ausleihe|borrowed/u', $v) => 'personal_loan',
            default => 'owned',
        };
    }

    public static function rating(string $value): ?int
    {
        $value = str_replace(',', '.', trim($value));
        if (!is_numeric($value) || (float) $value <= 0) {
            return null;
        }
        $rating = (float) $value;
        if ($rating > 5) {
            $rating /= 2; // 10-point scales
        }
        return max(1, min(5, (int) round($rating)));
    }

    public function cleanGenre(string $genre): ?string
    {
        $genre = self::cleanText($genre);
        $genre = preg_replace('/^RH_/', '', $genre) ?? $genre;
        if ($genre === '') {
            return null;
        }
        $lower = mb_strtolower($genre);
        $extra = array_filter(array_map(fn ($g) => mb_strtolower(trim($g)), explode(',', (string) Config::get('IMPORT_GENRE_BLOCKLIST', ''))));
        if (in_array($lower, self::GENRE_BLOCKLIST, true) || in_array($lower, $extra, true)) {
            return null;
        }
        foreach (self::GENRE_BLOCK_PATTERNS as $pattern) {
            if (preg_match($pattern, $genre)) {
                return null;
            }
        }
        return mb_substr($genre, 0, 100);
    }

    private static function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)) ?? $value);
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private function message(int $line, string $level, string $code, string $detail = ''): void
    {
        if (count($this->summary['messages']) < 300) {
            $this->summary['messages'][] = ['row' => $line, 'level' => $level, 'code' => $code, 'detail' => $detail];
        }
    }
}
