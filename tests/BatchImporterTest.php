<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\ApiException;
use BookSpot\App;
use BookSpot\Auth;
use BookSpot\BatchImporter;
use BookSpot\BookService;
use BookSpot\Config;
use BookSpot\Http\Kernel;
use BookSpot\MetadataService;
use BookSpot\MetadataUnavailable;
use BookSpot\RateLimiter;
use BookSpot\Request;
use BookSpot\ShelfService;

/** Bulk scan: immediate placeholders + rate-limited metadata queue (BatchImporter). */
final class BatchImporterTest extends TestCase
{
    private const ISBNS = ['9783548283951', '9783404139873', '9783442151479'];

    /** @var array<string, mixed> */
    private array $user;
    private int $shelfId;
    private float $now = 1000.0;
    /** @var list<string> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('METADATA_LOOKUP', 'true');
        Config::set('METADATA_DNB', 'false');
        $this->user = $this->createUser();
        $this->shelfId = (new ShelfService($this->db))->create(['name' => 'Wohnzimmer', 'columns' => [
            ['label' => 'Links', 'rows' => ['L1', 'L2']],
            ['label' => 'Mitte', 'rows' => ['M1', 'M2', 'M3']],
        ]])['id'];
    }

    protected function tearDown(): void
    {
        Config::set('METADATA_LOOKUP', 'false');
        Config::set('METADATA_DNB', 'true');
        parent::tearDown();
    }

    private static function openLibrary(string $isbn, string $title): string
    {
        return (string) json_encode(["ISBN:$isbn" => [
            'title' => $title,
            'authors' => [['name' => 'Andreas Eschbach']],
            'publishers' => [['name' => 'Bastei']],
            'publish_date' => '2005',
            'number_of_pages' => 400,
            'subjects' => [['name' => 'Thriller']],
            'cover' => ['large' => 'https://covers.openlibrary.org/b/id/7-L.jpg'],
        ]]);
    }

    /** Valid ISBN-13 978-3-00-000xxx-? with check digit. */
    private static function isbn13(int $n): string
    {
        $digits = '978300000' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }
        return $digits . ((10 - $sum % 10) % 10);
    }

    /** Metadata service with a fake HTTP client and a fake clock for the rate limiter. */
    private function metadata(callable $http): MetadataService
    {
        $limiter = new RateLimiter(fn (): float => $this->now, function (float $seconds): void {
            $this->now += $seconds;
        });
        return new MetadataService($this->db, function (string $url) use ($http) {
            $this->calls[] = $url;
            return $http($url);
        }, $limiter);
    }

    /** @return array<string, mixed> */
    private function submit(array $isbns, array $location = ['shelf_col' => 2, 'shelf_row' => 3], bool $move = true): array
    {
        $items = array_map(fn ($isbn) => ['isbn' => $isbn], $isbns);
        return (new BatchImporter($this->db))->submit($items, ['shelf_id' => $this->shelfId] + $location, $this->user['id'], $move);
    }

    public function testScansAreAcceptedAtOnceAsPlaceholdersInTheCompartment(): void
    {
        $result = $this->submit([self::ISBNS[0], '978-3-404-13987-3', 'not an isbn', self::ISBNS[0]]);

        self::assertSame('queued', $result['status']);
        self::assertSame(2, $result['queued']);
        self::assertSame(['created', 'created', 'invalid', 'duplicate'], array_column($result['items'], 'status'));
        $book = $result['items'][0]['book'];
        self::assertSame(self::ISBNS[0], $book['title'], 'placeholder title until the metadata arrives');
        self::assertSame('pending', $book['metadata_status']);
        self::assertSame(['shelf' => 'Wohnzimmer', 'column' => 'Mitte', 'compartment' => 'M3'], $book['location']);
        self::assertSame('9783404139873', $result['items'][1]['isbn'], 'ISBNs are normalised');
        self::assertSame(2, (new BatchImporter($this->db))->remaining());
        self::assertSame(0, $this->db->value('SELECT COUNT(*) FROM metadata_queue WHERE status <> ?', ['pending']) + 0);
    }

    public function testExistingBooksAreReportedAndMovedIntoTheScannedCompartment(): void
    {
        $books = new BookService($this->db, new ShelfService($this->db));
        $elsewhere = $books->create(['title' => 'Impact', 'isbn' => self::ISBNS[0], 'shelf_id' => $this->shelfId, 'shelf_col' => 1, 'shelf_row' => 1], $this->user['id']);
        $here = $books->create(['title' => 'Der Herr', 'isbn' => self::ISBNS[1], 'shelf_id' => $this->shelfId, 'shelf_col' => 2, 'shelf_row' => 3], $this->user['id']);
        $loan = $books->create(['title' => 'Leihbuch', 'isbn' => self::ISBNS[2], 'entry_type' => 'library_loan', 'loan_due' => '2026-11-01'], $this->user['id']);

        $result = $this->submit(self::ISBNS);
        self::assertSame(['moved', 'existing_here', 'existing'], array_column($result['items'], 'status'));
        self::assertSame('L1', $result['items'][0]['previous_location']['compartment']);
        self::assertSame('M3', $result['items'][0]['book']['location']['compartment']);
        self::assertNull($books->get($loan['id'], $this->user['id'])['shelf_id'], 'library loans are never shelved');
        self::assertSame(0, $result['queued']);
        self::assertSame($here['id'], $result['items'][1]['book']['id']);

        // Without move_existing the book stays where it is.
        $books->move([$elsewhere['id']], $this->shelfId, 1, 2);
        self::assertSame('existing', $this->submit([self::ISBNS[0]], move: false)['items'][0]['status']);
        self::assertSame('L2', $books->get($elsewhere['id'], $this->user['id'])['location']['compartment']);
    }

    public function testInvalidInputIsRejected(): void
    {
        self::assertSame('invalid_location', $this->submit([self::ISBNS[0]], ['shelf_col' => 9, 'shelf_row' => 9])['items'][0]['status']);
        $this->expectException(ApiException::class);
        (new BatchImporter($this->db))->submit(array_fill(0, BatchImporter::MAX_ITEMS + 1, ['isbn' => self::ISBNS[0]]), [], $this->user['id']);
    }

    public function testQueueFillsInMetadataAndMarksUnknownBooks(): void
    {
        $created = $this->submit([self::ISBNS[0], self::ISBNS[1]])['items'];
        $metadata = $this->metadata(fn (string $url) => match (true) {
            str_contains($url, 'openlibrary.org') && str_contains($url, self::ISBNS[0]) => self::openLibrary(self::ISBNS[0], 'Ein König für Deutschland'),
            default => null, // nothing anywhere for the second ISBN
        });

        $stats = (new BatchImporter($this->db, $metadata))->process(10, 30);
        self::assertSame(['processed' => 2, 'completed' => 1, 'failed' => 1, 'retry' => 0, 'remaining' => 0], $stats);

        $found = (new BookService($this->db, new ShelfService($this->db)))->get($created[0]['book']['id'], $this->user['id']);
        self::assertSame('Ein König für Deutschland', $found['title']);
        self::assertSame('Andreas Eschbach', $found['authors']);
        self::assertSame('https://covers.openlibrary.org/b/id/7-L.jpg', $found['cover_url']);
        self::assertNull($found['metadata_status']);
        self::assertSame('M3', $found['location']['compartment'], 'the location stays');

        $unknown = (new BatchImporter($this->db))->status([$created[1]['book']['id']], $this->user['id'])[0];
        self::assertSame('failed', $unknown['metadata_status']);
        self::assertSame(self::ISBNS[1], $unknown['title']);
        self::assertSame(0, (new MetadataService($this->db))->pendingCount(), 'placeholders are not picked up twice by the regular enrichment');
    }

    public function testUnreachableSourcesAreRetriedLaterWithoutBlockingTheQueue(): void
    {
        $this->submit([self::ISBNS[0], self::ISBNS[1]]);
        $down = $this->metadata(fn () => throw new MetadataUnavailable('HTTP status 0', 0));

        $stats = (new BatchImporter($this->db, $down))->process(10, 30);
        self::assertSame(1, $stats['processed'], 'stops at the first unreachable source');
        self::assertSame(1, $stats['retry']);
        self::assertSame(2, $stats['remaining']);
        self::assertSame('2026-10-09 12:01:00', $this->db->value('SELECT next_attempt_at FROM metadata_queue ORDER BY id LIMIT 1'));
        $again = (new BatchImporter($this->db, $down))->process(10, 30);
        self::assertSame([1, 1], [$again['processed'], $again['retry']], 'the first entry is not due yet, the second is tried and put back as well');
        self::assertSame(0, (new BatchImporter($this->db, $down))->process(10, 30)['processed'], 'nothing due within the next minute');

        App::setNow('2026-10-09 12:02:00');
        $up = $this->metadata(fn (string $url) => str_contains($url, 'openlibrary') ? self::openLibrary(str_contains($url, self::ISBNS[0]) ? self::ISBNS[0] : self::ISBNS[1], 'Gefunden') : null);
        self::assertSame(2, (new BatchImporter($this->db, $up))->process(10, 30)['completed']);
    }

    public function testAPausedSourceDoesNotHoldUpTheRestOfTheQueue(): void
    {
        // Google Books answered 429 earlier and is paused; the first ISBN is unknown to Open Library.
        $this->submit([self::ISBNS[0], self::ISBNS[1]]);
        $limiter = new RateLimiter(fn (): float => $this->now, function (float $s): void {
            $this->now += $s;
        });
        $limiter->penalize('google_books', 600);
        $metadata = new MetadataService($this->db, fn (string $url) => str_contains($url, 'openlibrary') && str_contains($url, self::ISBNS[1])
            ? self::openLibrary(self::ISBNS[1], 'Gefunden')
            : null, $limiter);

        $stats = (new BatchImporter($this->db, $metadata))->process(10, 30);
        self::assertSame(['processed' => 2, 'completed' => 1, 'failed' => 0, 'retry' => 1, 'remaining' => 1], $stats);
        self::assertSame(
            ['rate_limited', 'pending'],
            [$this->db->value('SELECT error_message FROM metadata_queue WHERE isbn = ?', [self::ISBNS[0]]), $this->db->value('SELECT metadata_status FROM books WHERE isbn13 = ?', [self::ISBNS[0]])],
            '"not found" is not final while Google Books could not be asked'
        );
    }

    public function testStaleClaimsAreReleasedAndClaimedEntriesAreNotProcessedTwice(): void
    {
        $this->submit([self::ISBNS[0]]);
        $this->db->run("UPDATE metadata_queue SET status = 'processing', locked_until = ?", ['2026-10-09 12:05:00']);
        $ok = $this->metadata(fn (string $url) => str_contains($url, 'openlibrary') ? self::openLibrary(self::ISBNS[0], 'Impact') : null);
        self::assertSame(0, (new BatchImporter($this->db, $ok))->process()['processed'], 'claimed by another run');

        App::setNow('2026-10-09 12:06:00');
        self::assertSame(1, (new BatchImporter($this->db, $ok))->process()['completed'], 'lock expired: picked up again');
    }

    /** 60 scans in a row: every source is called at its allowed rate, none answers 429. */
    public function testFiftyPlusScansStayWithinTheProviderRateLimits(): void
    {
        Config::set('RATE_LIMIT_OPEN_LIBRARY', '1');
        Config::set('RATE_LIMIT_GOOGLE_BOOKS', '5');
        $isbns = array_map(self::isbn13(...), range(1, 60));
        $this->submit($isbns);

        // Fake providers that answer 429 when called faster than their documented limit.
        $last = [];
        $tooFast = 0;
        $metadata = $this->metadata(function (string $url) use (&$last, &$tooFast): ?string {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $minGap = str_contains($host, 'googleapis') ? 0.2 : 1.0;
            if (isset($last[$host]) && $this->now - $last[$host] < $minGap - 1e-9) {
                $tooFast++;
                throw new MetadataUnavailable('HTTP status 429', 429);
            }
            $last[$host] = $this->now;
            if (str_contains($host, 'openlibrary')) {
                preg_match('/ISBN:(\d{13})/', $url, $m);
                return self::openLibrary($m[1], 'Buch ' . $m[1]);
            }
            return null;
        });

        $importer = new BatchImporter($this->db, $metadata);
        $stats = $importer->process(100, 300, 5);
        self::assertSame(0, $tooFast, 'no 429 at all');
        self::assertSame(60, $stats['completed']);
        self::assertSame(0, $stats['remaining']);
        self::assertGreaterThanOrEqual(59.0, $this->now - 1000.0, 'Open Library calls were spread over at least a minute');
    }

    public function testBatchEndpoints(): void
    {
        Auth::setUser($this->user);
        $call = fn (string $path, array $body) => Kernel::handle(new Request('POST', [], $body, [], ['x-requested-with' => 'BookSpot'], '10.0.0.5', $path));

        $response = $call('/v1/books/batch', ['shelf_id' => $this->shelfId, 'shelf_col' => 1, 'shelf_row' => 2, 'isbns' => [self::ISBNS[0], 'x']]);
        self::assertSame(200, $response->status);
        $data = $response->data();
        self::assertSame(['created', 'invalid'], array_column($data['items'], 'status'));
        self::assertSame('L2', $data['items'][0]['book']['location']['compartment']);

        // Items with client ids (offline queue of the app)
        $items = $call('/v1/books/batch', ['items' => [['client_id' => 'c-1', 'isbn' => self::ISBNS[1], 'shelf_id' => $this->shelfId, 'shelf_col' => 2, 'shelf_row' => 1]]])->data()['items'];
        self::assertSame('c-1', $items[0]['client_id']);
        self::assertSame('M1', $items[0]['book']['location']['compartment']);

        // No real network in tests: the queue is not worked off here (see the tests above).
        Config::set('METADATA_LOOKUP', 'false');
        $process = $call('/v1/books/batch/process', ['book_ids' => [$data['items'][0]['book']['id'], $items[0]['book']['id']]]);
        self::assertSame(200, $process->status);
        self::assertCount(2, $process->data()['books']);
        self::assertSame(['pending', 'pending'], array_column($process->data()['books'], 'metadata_status'));
        self::assertSame(2, $process->data()['remaining']);

        self::assertSame(422, $call('/v1/books/batch', ['isbns' => 'nope'])->status);
        Auth::setUser(null);
        self::assertSame(401, $call('/v1/books/batch', ['isbns' => [self::ISBNS[0]]])->status);
    }
}
