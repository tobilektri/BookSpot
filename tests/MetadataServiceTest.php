<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\BookService;
use BookSpot\Config;
use BookSpot\MetadataService;
use BookSpot\MetadataUnavailable;
use BookSpot\ShelfService;

final class MetadataServiceTest extends TestCase
{
    private const OPEN_LIBRARY = '{"ISBN:9783548283951":{"title":"Impact","authors":[{"name":"Bernd Steinhardt"}],"publishers":[{"name":"Ullstein"}],"publish_date":"2011","number_of_pages":528,"subjects":[{"name":"Accessible book"},{"name":"Thriller / Spannung"}],"cover":{"medium":"http://covers.openlibrary.org/b/id/1-M.jpg","large":"http://covers.openlibrary.org/b/id/1-L.jpg"}}}';
    private const GOOGLE = '{"items":[{"volumeInfo":{"title":"Impact","description":"<p>Ein Meteorit …</p>","categories":["Fiction"],"language":"de","imageLinks":{"thumbnail":"http://books.google.com/x&edge=curl"}}}]}';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('METADATA_LOOKUP', 'true');
    }

    protected function tearDown(): void
    {
        Config::set('METADATA_LOOKUP', 'false');
        parent::tearDown();
    }

    public function testMergesOpenLibraryAndGoogleBooks(): void
    {
        $service = new MetadataService($this->db, fn (string $url) => str_contains($url, 'openlibrary') ? self::OPEN_LIBRARY : self::GOOGLE);
        $meta = $service->lookup('978-3-548-28395-1');
        self::assertSame('Impact', $meta['title']);
        self::assertSame('Bernd Steinhardt', $meta['authors']);
        self::assertSame('Ullstein', $meta['publisher']);
        self::assertSame(2011, $meta['published_year']);
        self::assertSame(528, $meta['pages']);
        self::assertSame('Thriller', $meta['genre'], 'housekeeping subjects are skipped');
        self::assertSame('https://covers.openlibrary.org/b/id/1-L.jpg', $meta['cover_url']);
        self::assertSame('Ein Meteorit …', $meta['description']);
        self::assertSame('de', $meta['language']);
        self::assertSame(['open_library', 'google_books'], $meta['sources']);
    }

    /** Real Open Library answer for this ISBN (no authors/pages/year/subjects). */
    private const OPEN_LIBRARY_SPARSE = '{"ISBN:9783548283951": {"url": "http://openlibrary.org/books/OL27469599M/Impact", "key": "/books/OL27469599M", "title": "Impact", "identifiers": {"amazon": ["3548283950"], "isbn_10": ["3548283950"], "isbn_13": ["9783548283951"], "openlibrary": ["OL27469599M"]}, "publishers": [{"name": "Ullstein Taschenbuchvlg."}], "cover": {"small": "https://covers.openlibrary.org/b/id/8927996-S.jpg", "medium": "https://covers.openlibrary.org/b/id/8927996-M.jpg", "large": "https://covers.openlibrary.org/b/id/8927996-L.jpg"}}}';
    private const GOOGLE_FULL = '{"items":[{"volumeInfo":{"title":"Impact","authors":["Bernd Steinhardt"],"publisher":"Ullstein","publishedDate":"2011-05-12","pageCount":528,"categories":["Fiction"],"language":"de"}}]}';

    public function testSparseOpenLibraryAnswerIsCompletedByGoogle(): void
    {
        $service = new MetadataService($this->db, fn (string $url) => str_contains($url, 'openlibrary') ? self::OPEN_LIBRARY_SPARSE : self::GOOGLE_FULL);
        $meta = $service->lookup('9783548283951');
        self::assertSame('Impact', $meta['title']);
        self::assertSame('Ullstein Taschenbuchvlg.', $meta['publisher'], 'Open Library wins where it has data');
        self::assertSame('https://covers.openlibrary.org/b/id/8927996-L.jpg', $meta['cover_url']);
        self::assertSame('Bernd Steinhardt', $meta['authors']);
        self::assertSame(528, $meta['pages']);
        self::assertSame(2011, $meta['published_year']);
        self::assertFalse($meta['partial']);
    }

    public function testSparseAnswerWithGoogleRateLimitStaysQueued(): void
    {
        $user = $this->createUser();
        $books = new BookService($this->db, new ShelfService($this->db));
        $book = $books->create(['isbn' => '9783548283951', 'title' => 'Impact'], $user['id']);

        // Open Library answers sparsely for every ISBN, Google Books is over quota.
        $service = new MetadataService($this->db, fn (string $url) => str_contains($url, 'openlibrary')
            ? str_replace('9783548283951', (string) preg_replace('/.*ISBN:(\d+).*/', '$1', $url), self::OPEN_LIBRARY_SPARSE)
            : throw new MetadataUnavailable('HTTP status 429'));
        $meta = $service->lookup('9783548283951');
        self::assertTrue($meta['partial']);

        $second = $books->create(['isbn' => '9783404139873', 'title' => 'Zweites'], $user['id']);

        $result = $service->enrichBatch();
        self::assertFalse($result['unavailable'], 'Open Library answered, so the batch continues');
        self::assertSame(2, $result['processed'], 'a partial answer does not block the next book');
        self::assertSame(2, $result['partial']);
        self::assertSame(0, $result['remaining'], 'partial books wait for the retry interval');
        self::assertSame('https://covers.openlibrary.org/b/id/8927996-L.jpg', $books->get($book['id'], $user['id'])['cover_url']);

        // Retried after 6 hours; after MAX_ATTEMPTS the Open Library data counts as final.
        for ($i = 2; $i <= MetadataService::MAX_ATTEMPTS; $i++) {
            App::setNow(App::now()->modify('+7 hours')->format('Y-m-d H:i:s'));
            self::assertSame(2, $service->pendingCount());
            $service->enrichBatch();
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM books WHERE metadata_checked_at IS NULL'));
        self::assertNotNull($second);
    }

    public function testParsesDnbMarcRecord(): void
    {
        $record = MetadataService::parseDnbRecord((string) file_get_contents(__DIR__ . '/fixtures/dnb_9783548283951.xml'));
        self::assertSame('Impact: Thriller', $record['title']);
        self::assertSame('Bernd Steinhardt', $record['authors'], 'MARC "Last, First" is inverted');
        self::assertSame('Ullstein', $record['publisher']);
        self::assertSame(2012, $record['published_year']);
        self::assertSame(518, $record['pages']);
        self::assertSame('Spannung', $record['genre'], 'product form notes and numeric keywords are skipped');
        self::assertSame('de', $record['language']);
        self::assertSame('https://services.dnb.de/plus/idn/1011687097/blurb/', $record['blurb_url']);

        self::assertNull(MetadataService::parseDnbRecord('<searchRetrieveResponse xmlns="http://www.loc.gov/zing/srw/"><numberOfRecords>0</numberOfRecords></searchRetrieveResponse>'));
        self::assertNull(MetadataService::parseDnbRecord('not xml'));
    }

    public function testDnbFillsGapsOfSparseOpenLibraryAnswer(): void
    {
        $calls = [];
        $dnb = (string) file_get_contents(__DIR__ . '/fixtures/dnb_9783548283951.xml');
        $blurb = (string) file_get_contents(__DIR__ . '/fixtures/dnb_blurb_1011687097.html');
        $service = new MetadataService($this->db, function (string $url) use (&$calls, $dnb, $blurb) {
            $calls[] = (string) parse_url($url, PHP_URL_HOST);
            return match (true) {
                str_contains($url, 'openlibrary.org') => self::OPEN_LIBRARY_SPARSE,
                str_contains($url, '/sru/dnb') => $dnb,
                str_contains($url, '/blurb/') => $blurb,
                default => '{"kind": "books#volumes", "totalItems": 0}',
            };
        });
        $meta = $service->lookup('9783548283951');
        self::assertSame('Impact', $meta['title'], 'first source wins');
        self::assertSame('Ullstein Taschenbuchvlg.', $meta['publisher']);
        self::assertSame('https://covers.openlibrary.org/b/id/8927996-L.jpg', $meta['cover_url']);
        self::assertSame('Bernd Steinhardt', $meta['authors']);
        self::assertSame(518, $meta['pages']);
        self::assertSame(2012, $meta['published_year']);
        self::assertSame('Spannung', $meta['genre']);
        self::assertStringStartsWith('Mit Erfrierungen und unerklärlichen Kopfschmerzen', $meta['description']);
        self::assertStringEndsWith('vernichtet zu werden.', $meta['description']);
        self::assertStringNotContainsString('<', $meta['description']);
        self::assertSame(['open_library', 'dnb'], $meta['sources']);
        self::assertFalse($meta['partial']);
        self::assertNotContains('www.googleapis.com', $calls, 'Google is not asked when nothing is missing');
    }

    public function testBlurbIsOnlyFetchedFromDnbHosts(): void
    {
        $xml = str_replace('https://services.dnb.de/plus/idn/1011687097/blurb/', 'https://evil.example/blurb/', (string) file_get_contents(__DIR__ . '/fixtures/dnb_9783548283951.xml'));
        $fetched = [];
        $service = new MetadataService($this->db, function (string $url) use (&$fetched, $xml) {
            $fetched[] = $url;
            return str_contains($url, '/sru/dnb') ? $xml : null;
        });
        $meta = $service->lookup('9783548283951');
        self::assertSame('Impact: Thriller', $meta['title']);
        self::assertNull($meta['description'] ?? null);
        self::assertSame([], array_values(array_filter($fetched, fn ($u) => str_contains($u, 'evil.example'))));
    }

    public function testNotFoundVersusUnavailable(): void
    {
        $empty = new MetadataService($this->db, fn () => '{}');
        self::assertNull($empty->lookup('9783548283951'));

        $down = new MetadataService($this->db, fn () => throw new MetadataUnavailable('timeout'));
        $this->expectException(MetadataUnavailable::class);
        $down->lookup('9783548283951');
    }

    public function testEnrichBatchFillsGapsAndRetriesAfterOutage(): void
    {
        $user = $this->createUser();
        $books = new BookService($this->db, new ShelfService($this->db));
        $book = $books->create(['isbn' => '9783548283951', 'title' => 'Impact', 'genre' => 'Eigenes Genre'], $user['id']);

        $down = new MetadataService($this->db, fn () => throw new MetadataUnavailable('429'));
        $result = $down->enrichBatch();
        self::assertTrue($result['unavailable']);
        self::assertSame(0, $result['processed']);
        self::assertSame(1, $result['remaining'], 'not marked as checked during an outage');

        $up = new MetadataService($this->db, fn (string $url) => str_contains($url, 'openlibrary') ? self::OPEN_LIBRARY : self::GOOGLE);
        $result = $up->enrichBatch();
        self::assertSame(['processed' => 1, 'updated' => 1, 'partial' => 0, 'remaining' => 0, 'unavailable' => false], $result);
        $enriched = $books->get($book['id'], $user['id']);
        self::assertSame('Eigenes Genre', $enriched['genre'], 'existing values are never overwritten');
        self::assertSame(528, $enriched['pages']);
        self::assertSame('Ullstein', $enriched['publisher']);
        self::assertStringStartsWith('https://covers.openlibrary.org', $enriched['cover_url']);
    }
}
