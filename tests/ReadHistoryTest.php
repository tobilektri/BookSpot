<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\BookService;
use BookSpot\ExportService;
use BookSpot\Import\BookImporter;
use BookSpot\Import\CsvReader;
use BookSpot\ReadHistoryService;
use BookSpot\ShelfService;
use BookSpot\StatsService;
use BookSpot\UserBookService;

final class ReadHistoryTest extends TestCase
{
    private BookService $books;
    private ReadHistoryService $reads;
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
        $this->books = new BookService($this->db, new ShelfService($this->db));
        $this->reads = new ReadHistoryService($this->db);
    }

    private function readBook(string $title, int $pages, string $started, string $finished): int
    {
        $id = (int) $this->books->create(['title' => $title, 'pages' => $pages], $this->user['id'])['id'];
        (new UserBookService($this->db))->upsert($this->user['id'], $id, ['reading_status' => 'read', 'started_at' => $started, 'finished_at' => $finished]);
        return $id;
    }

    public function testReadAgainKeepsTheFinishedReadAndStartsANewOne(): void
    {
        $id = $this->readBook('Lieblingsbuch', 300, '2025-01-01', '2025-01-10');

        $result = $this->reads->readAgain($this->user['id'], $id);
        self::assertSame(['reading', App::today(), null, 0], [
            $result['personal']['reading_status'], $result['personal']['started_at'], $result['personal']['finished_at'], $result['personal']['pages_read'],
        ]);
        self::assertSame([['started_at' => '2025-01-01', 'finished_at' => '2025-01-10']], array_map(fn ($r) => array_diff_key($r, ['id' => 1]), $result['reads']));

        // Shown with the book (detail and catalogue list).
        self::assertCount(1, $this->books->get($id, $this->user['id'])['personal']['reads']);
        self::assertCount(1, $this->books->list([], $this->user['id'])['items'][0]['personal']['reads']);
        self::assertSame([], $this->books->list([], $this->createUser('ben')['id'])['items'][0]['personal']['reads'], 'reads are personal');

        $this->expectException(ApiException::class);
        $this->reads->readAgain($this->user['id'], $id); // currently reading, nothing to keep
    }

    public function testEarlierReadsAreEnteredValidatedAndDeleted(): void
    {
        $id = $this->readBook('Klassiker', 200, '2026-03-01', '2026-03-05');
        $reads = $this->reads->add($this->user['id'], $id, ['started_at' => '01.06.2024', 'finished_at' => '20.06.2024']);
        $reads = $this->reads->add($this->user['id'], $id, ['finished_at' => '2019-12-24']);
        self::assertSame(['2024-06-20', '2019-12-24'], array_column($reads, 'finished_at'), 'newest first');

        foreach ([
            [['finished_at' => ''], 'required'],
            [['finished_at' => '2099-01-01'], 'in_future'],
            [['started_at' => '2024-05-10', 'finished_at' => '2024-05-01'], 'before_start'],
        ] as [$input, $error]) {
            try {
                $this->reads->add($this->user['id'], $id, $input);
                self::fail("expected $error");
            } catch (ApiException $e) {
                self::assertSame($error, $e->details['errors']['finished_at'] ?? null);
            }
        }

        $other = $this->createUser('ben');
        try {
            $this->reads->delete($other['id'], $id, $reads[0]['id']);
            self::fail('others cannot delete my reads');
        } catch (ApiException $e) {
            self::assertSame(404, $e->getCode());
        }
        self::assertCount(1, $this->reads->delete($this->user['id'], $id, $reads[0]['id']));
    }

    public function testStatisticsCountEveryFinishedRead(): void
    {
        $id = $this->readBook('Dreimal gelesen', 300, '2026-09-01', '2026-09-10');
        $this->reads->add($this->user['id'], $id, ['started_at' => '2026-02-01', 'finished_at' => '2026-02-05']);
        $this->reads->add($this->user['id'], $id, ['finished_at' => '2025-07-01']);
        $this->readBook('Einmal', 100, '2026-09-20', '2026-09-21');

        $stats = (new StatsService($this->db))->forUser($this->user['id'], 2026);
        $months = array_column($stats['months'], null, 'month');
        self::assertSame(['books' => 1, 'pages' => 300], array_intersect_key($months['2026-02'], ['books' => 1, 'pages' => 1]));
        self::assertSame(['books' => 2, 'pages' => 400], array_intersect_key($months['2026-09'], ['books' => 1, 'pages' => 1]));
        self::assertSame(3, $stats['pace']['books_this_year']);
        self::assertSame([2025 => 1, 2026 => 3], array_column($stats['years'], 'books', 'year'));
        self::assertSame(2, $stats['totals']['rereads']);
        self::assertSame(2, $stats['status']['read'], 'a book is still one read book');
        self::assertSame(3, $stats['pace']['books_with_dates'], 'pace uses every read with start and end');
    }

    public function testExportAndImportKeepEarlierReads(): void
    {
        $id = $this->readBook('Mehrfach', 120, '2026-09-01', '2026-09-10');
        $this->books->update($id, ['isbn' => '9783548283951'], $this->user['id']);
        $this->reads->add($this->user['id'], $id, ['started_at' => '2024-06-01', 'finished_at' => '2024-06-20']);
        $this->reads->add($this->user['id'], $id, ['finished_at' => '2019-12-24']);

        $out = fopen('php://memory', 'w+');
        (new ExportService($this->db))->writeCsv($this->user['id'], $out);
        rewind($out);
        $csv = (string) stream_get_contents($out);
        self::assertStringContainsString('"– 24.12.2019; 01.06.2024 – 20.06.2024"', $csv);

        // Re-import into a fresh account: the reads come along, a second import adds nothing.
        $ben = $this->createUser('ben');
        $rows = (new CsvReader())->parseString($csv);
        (new BookImporter($this->db, $ben['id']))->import($rows);
        (new BookImporter($this->db, $ben['id']))->import($rows);
        self::assertSame(['2024-06-20', '2019-12-24'], array_column($this->reads->forUser($ben['id'], $id)[$id] ?? [], 'finished_at'));
        self::assertSame([[null, '2019-12-24'], ['2024-06-01', '2024-06-20']], BookImporter::parseReads('– 24.12.2019; 01.06.2024 bis 20.06.2024; kaputt'));
    }
}
