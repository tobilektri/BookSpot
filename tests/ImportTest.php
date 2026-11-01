<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\BookService;
use BookSpot\Import\BookImporter;
use BookSpot\Import\CsvReader;
use BookSpot\ShelfService;
use BookSpot\StatsService;

final class ImportTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/bookstats_sample.csv';

    public function testReaderDetectsBookstatsFormat(): void
    {
        $reader = new CsvReader();
        $rows = $reader->parseFile(self::FIXTURE);
        self::assertSame(';', $reader->delimiter);
        self::assertCount(7, $rows);
        foreach (['title', 'authors', 'isbn', 'asin', 'genre', 'pages', 'reading_status', 'started_at', 'finished_at', 'rating', 'notes', 'acquired_at', 'category'] as $field) {
            self::assertArrayHasKey($field, $reader->mapping, $field);
        }
        self::assertSame('Die Nebelinsel: Thriller', $rows[0]['title']);
    }

    public function testReaderHandlesCommaAndLatin1(): void
    {
        $csv = mb_convert_encoding("Title,Author,ISBN13,My Rating,Exclusive Shelf,Date Read\n\"Bär, der\",Jürgen,=\"9783000000010\",4,read,2025/01/31\n", 'Windows-1252', 'UTF-8');
        $reader = new CsvReader();
        $rows = $reader->parseString($csv);
        self::assertSame(',', $reader->delimiter);
        self::assertSame('Bär, der', $rows[0]['title']);
        self::assertSame('Jürgen', $rows[0]['authors']);
        self::assertSame('9783000000010', $rows[0]['isbn']);
    }

    public function testImportWithAutoCreatedShelf(): void
    {
        $user = $this->createUser();
        $summary = $this->runImport($user['id'], ['create_shelves' => true, 'shelf_name' => 'Wohnzimmer']);

        self::assertSame(7, $summary['total_rows']);
        self::assertSame(6, $summary['created']);
        self::assertSame(1, $summary['skipped'], 'duplicate row in file');
        self::assertSame(0, $summary['errors']);
        self::assertSame(3, $summary['placed']);
        self::assertSame(['L', 'M', 'R'], array_column($summary['shelves_created'][0]['columns'], 'label'));
        self::assertSame([], $summary['unmatched_locations']);

        $books = new BookService($this->db, new ShelfService($this->db));
        $nebel = $books->findByIsbn('9783000000010', $user['id']);
        self::assertSame(['shelf' => 'Wohnzimmer', 'column' => 'L', 'compartment' => 'L2'], $nebel['location']);
        self::assertNull($nebel['genre'], 'Amazon browse node id is not a genre');
        self::assertSame('3000000011', $nebel['isbn10']);
        self::assertSame(4.19, $nebel['price']);
        self::assertSame('2025-08-31', $nebel['acquired_at']);
        self::assertSame('read', $nebel['personal']['reading_status']);
        self::assertSame(412, $nebel['personal']['pages_read']);

        $haus = $books->findByIsbn('9783000000034', $user['id']);
        self::assertSame('Fantasy', $haus['genre'], 'falls back to Kategorie');
        self::assertSame('', $haus['authors']);
        self::assertNull($haus['published_year']);
        self::assertNull($haus['format']);
        self::assertSame(4, $haus['personal']['rating']);
        self::assertSame('2026-08-20', $haus['personal']['finished_at']);

        $kurz = $books->findByIsbn('9783000000041', $user['id']);
        self::assertNull($kurz['shelf_id']);
        self::assertSame('Cara', $kurz['personal']['notes'], 'free text notes are kept');

        $kindle = $books->findByIsbn('9783000000072', $user['id']);
        self::assertSame('B00TESTXYZ', $kindle['asin']);
        self::assertSame('reading', $kindle['personal']['reading_status']);

        $stats = (new StatsService($this->db))->forUser($user['id'], 2026);
        self::assertSame(['unread' => 2, 'reading' => 1, 'read' => 3, 'abandoned' => 0], $stats['status']);
        self::assertSame(412 + 300 + 200, $stats['totals']['pages_read']);
        self::assertSame(1, $stats['months'][7]['books']);
    }

    public function testUnmatchedLocationsAreReportedAndDryRunWritesNothing(): void
    {
        $user = $this->createUser();
        $summary = $this->runImport($user['id'], ['dry_run' => true]);
        self::assertTrue($summary['dry_run']);
        self::assertSame(['L2' => 1, 'M4' => 1, 'R3' => 1], $summary['unmatched_locations']);
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM books'));
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM shelves'));
    }

    public function testReimportMatchesExistingShelvesAndKeepsData(): void
    {
        $user = $this->createUser();
        $other = $this->createUser('ben');
        (new ShelfService($this->db))->create(['name' => 'Regal', 'columns' => [
            ['label' => 'L', 'rows' => ['L1', 'L2']],
            ['label' => 'M', 'rows' => ['M1', 'M2', 'M3', 'M4']],
            ['label' => 'R', 'rows' => ['R1', 'R2', 'R3']],
        ]]);
        $this->runImport($user['id']);
        $second = $this->runImport($other['id']);

        self::assertSame(0, $second['created']);
        self::assertSame(6, $second['linked'] + $second['updated']);
        self::assertSame(6, (int) $this->db->value('SELECT COUNT(*) FROM books'));
        self::assertSame(12, (int) $this->db->value('SELECT COUNT(*) FROM user_books'));
        self::assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM books WHERE shelf_id IS NOT NULL'));
    }

    public function testStatusAndRatingMapping(): void
    {
        self::assertSame('read', BookImporter::readingStatus('Gelesen'));
        self::assertSame('unread', BookImporter::readingStatus('Ungelesen'));
        self::assertSame('reading', BookImporter::readingStatus('Lese gerade'));
        self::assertSame('reading', BookImporter::readingStatus('currently-reading'));
        self::assertSame('unread', BookImporter::readingStatus('to-read'));
        self::assertSame('abandoned', BookImporter::readingStatus('Abgebrochen'));
        self::assertNull(BookImporter::rating('0'));
        self::assertSame(4, BookImporter::rating('4'));
        self::assertSame(4, BookImporter::rating('8'));
        self::assertSame('library_loan', BookImporter::entryType('Bibliothek'));
        self::assertSame('library_loan', BookImporter::entryType('Leihe'));
        self::assertSame('personal_loan', BookImporter::entryType('Ausleihe'));
        self::assertSame('owned', BookImporter::entryType('Kauf'));
    }

    public function testLibraryLoansFromBookstats(): void
    {
        // App clock: 2026-10-09. Default loan period of this user: 21 days (Onleihe).
        $user = $this->createUser();
        $this->db->update('users', ['library_name' => 'Onleihe', 'library_loan_days' => 21], ['id' => $user['id']]);
        $summary = $this->importCsv($user['id'], [
            ['Altes E-Book', '9783000001017', 'Leihe', '01.08.2026'],
            ['Aktuelle Leihe', '9783000001024', 'Leihe', '01.10.2026'],
            ['Von Anna', '9783000001031', 'Ausleihe', '01.10.2026'],
            ['Gekauft', '9783000001048', 'Kauf', '01.08.2026'],
        ]);
        self::assertSame(4, $summary['created']);

        $old = $this->bookByTitle('Altes E-Book');
        self::assertSame(['library_loan', 'Onleihe', '2026-08-01', '2026-08-22', 'returned', $user['id']],
            [$old['entry_type'], $old['loan_from'], $old['loan_start'], $old['loan_due'], $old['status'], (int) $old['loan_user_id']]);
        $current = $this->bookByTitle('Aktuelle Leihe');
        self::assertSame(['2026-10-22', 'active'], [$current['loan_due'], $current['status']], 'still running: reminders as usual');
        self::assertSame('personal_loan', $this->bookByTitle('Von Anna')['entry_type']);
        self::assertSame(['owned', null], [$this->bookByTitle('Gekauft')['entry_type'], $this->bookByTitle('Gekauft')['loan_due']]);
    }

    public function testLibraryLoansUseThreeWeeksWithoutUserSetting(): void
    {
        $user = $this->createUser();
        $this->importCsv($user['id'], [['E-Book', '9783000001017', 'Leihe', '01.10.2026']]);
        self::assertSame('2026-10-22', $this->bookByTitle('E-Book')['loan_due']);
    }

    public function testReimportTakesOverLoansOfBooksImportedAsOwn(): void
    {
        $user = $this->createUser();
        $rows = [
            ['Altes E-Book', '9783000001017', 'Leihe', '01.08.2026'],
            ['Im Regal', '9783000001024', 'Leihe', '01.08.2026'],
            ['Selbst geändert', '9783000001031', 'Leihe', '01.08.2026'],
        ];
        // As older versions imported them: own books ("Leihe" was not recognised).
        foreach ($rows as [$title, $isbn]) {
            $this->db->insert('books', ['title' => $title, 'isbn13' => $isbn, 'entry_type' => 'owned', 'acquired_at' => '2026-08-01',
                'created_by' => $user['id'], 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
        }
        $shelf = (new ShelfService($this->db))->create(['name' => 'Regal', 'columns' => [['label' => 'L', 'rows' => ['L1']]]]);
        $this->db->update('books', ['shelf_id' => $shelf['id'], 'shelf_col' => 0, 'shelf_row' => 0], ['isbn13' => '9783000001024']);
        $this->db->update('books', ['entry_type' => 'personal_loan'], ['isbn13' => '9783000001031']);

        $without = $this->importCsv($user['id'], $rows);
        self::assertSame(0, $without['loans_taken_over'], 'only with the option');
        self::assertSame('owned', $this->bookByTitle('Altes E-Book')['entry_type']);

        $with = $this->importCsv($user['id'], $rows, ['take_over_loans' => true]);
        self::assertSame(1, $with['loans_taken_over']);
        $book = $this->bookByTitle('Altes E-Book');
        self::assertSame(['library_loan', '2026-08-01', '2026-08-22', 'returned'], [$book['entry_type'], $book['loan_start'], $book['loan_due'], $book['status']]);
        self::assertSame('owned', $this->bookByTitle('Im Regal')['entry_type'], 'a shelved book stays an own book');
        self::assertSame('personal_loan', $this->bookByTitle('Selbst geändert')['entry_type'], 'types changed by hand stay');

        // A book somebody else added is never taken over.
        $ben = $this->createUser('ben');
        $this->db->insert('books', ['title' => 'Bens E-Book', 'isbn13' => '9783000001048', 'entry_type' => 'owned',
            'created_by' => $ben['id'], 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
        $this->importCsv($user['id'], [['Bens E-Book', '9783000001048', 'Leihe', '01.08.2026']], ['take_over_loans' => true]);
        self::assertSame(['owned', 'active'], [$this->bookByTitle('Bens E-Book')['entry_type'], $this->bookByTitle('Bens E-Book')['status']]);
    }

    public function testImportingAWishedBookBringsItIntoTheCollection(): void
    {
        $anna = $this->createUser('anna');
        $ben = $this->createUser('ben');
        $books = new BookService($this->db, new ShelfService($this->db));
        $wish = $books->create(['title' => 'Annas Wunsch', 'isbn' => '9783000001017', 'wishlist' => true], $anna['id']);

        $summary = $this->importCsv($ben['id'], [['Annas Wunsch', '9783000001017', 'Kauf', '01.10.2026']]);
        self::assertSame(1, $summary['updated']);
        self::assertSame([$wish['id']], array_column($books->list([], $ben['id'])['items'], 'id'), 'visible in the collection now');
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM wishes'));
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $books title, ISBN, "Erhalten als", "Erhalten am" */
    private function importCsv(int $userId, array $books, array $options = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'bookspot-csv');
        $lines = ['"Titel";"Autor(en)";"ISBN";"Erhalten als";"Lesestatus";"Erhalten am"'];
        foreach ($books as [$title, $isbn, $via, $date]) {
            $lines[] = "\"$title\";\"Autorin\";\"$isbn\";\"$via\";\"Gelesen\";\"$date\"";
        }
        file_put_contents($file, implode("\n", $lines) . "\n");
        try {
            return (new BookImporter($this->db, $userId, $options))->import((new CsvReader())->parseFile($file));
        } finally {
            unlink($file);
        }
    }

    private function bookByTitle(string $title): array
    {
        return $this->db->one('SELECT * FROM books WHERE title = ?', [$title]) ?? [];
    }

    private function runImport(int $userId, array $options = []): array
    {
        $rows = (new CsvReader())->parseFile(self::FIXTURE);
        return (new BookImporter($this->db, $userId, $options))->import($rows);
    }
}
