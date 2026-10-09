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
        self::assertSame('owned', BookImporter::entryType('Kauf'));
    }

    private function runImport(int $userId, array $options = []): array
    {
        $rows = (new CsvReader())->parseFile(self::FIXTURE);
        return (new BookImporter($this->db, $userId, $options))->import($rows);
    }
}
