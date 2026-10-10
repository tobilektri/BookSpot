<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\BookService;
use BookSpot\CoverService;
use BookSpot\RateLimiter;
use BookSpot\ShelfService;

final class CoverServiceTest extends TestCase
{
    private BookService $books;
    private array $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createUser();
        $this->books = new BookService($this->db, new ShelfService($this->db));
        CoverService::$directory = sys_get_temp_dir() . '/bookspot-covers-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob(CoverService::dir() . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(CoverService::dir());
        CoverService::$directory = null;
        parent::tearDown();
    }

    private static function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 180, 83, 9));
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    public function testDownloadsStoresAndScalesCover(): void
    {
        $book = $this->books->create(['title' => 'Mit Cover', 'isbn' => '9783548283951', 'cover_url' => 'https://example.org/c.png'], $this->user['id']);
        $calls = [];
        $service = new CoverService($this->db, function (string $url) use (&$calls) {
            $calls[] = $url;
            return ['status' => 200, 'body' => self::png(400, 1200)];
        });
        $cover = $service->ensure($book['id']);
        self::assertSame('image/jpeg', $cover['mime'], 'large images are scaled down to JPEG');
        self::assertSame([200, 600], array_slice(getimagesize($cover['path']), 0, 2));
        self::assertSame(['https://example.org/c.png'], $calls, 'cover_url is tried first');

        // Served from disk afterwards – no further download.
        self::assertSame($cover['path'], $service->ensure($book['id'])['path']);
        self::assertCount(1, $calls);
        self::assertTrue($this->books->get($book['id'], $this->user['id'])['has_cover']);
    }

    public function testFallsBackToOpenLibraryAndRemembersMissingCovers(): void
    {
        $book = $this->books->create(['title' => 'Ohne Cover', 'isbn' => '9783548283951'], $this->user['id']);
        $calls = 0;
        $service = new CoverService($this->db, function (string $url) use (&$calls) {
            $calls++;
            self::assertStringContainsString('covers.openlibrary.org/b/isbn/9783548283951-L.jpg?default=false', $url);
            return ['status' => 404, 'body' => null];
        });
        self::assertNull($service->ensure($book['id']));
        self::assertNull($service->ensure($book['id']));
        self::assertSame(1, $calls, '"none" is not retried on every view');
        self::assertFalse($this->books->get($book['id'], $this->user['id'])['has_cover']);

        App::setNow('2026-11-10 12:00:00');
        $service->ensure($book['id']);
        self::assertSame(2, $calls, 'retried after 30 days');
    }

    public function testRateLimitIsRetriedLaterAndStopsTheBatch(): void
    {
        $a = $this->books->create(['title' => 'A', 'isbn' => '9783548283951'], $this->user['id']);
        $this->books->create(['title' => 'B', 'isbn' => '9783404139873'], $this->user['id']);
        $calls = 0;
        $limited = new CoverService($this->db, function () use (&$calls) {
            $calls++;
            return ['status' => 403, 'body' => null]; // Open Library rate limit
        });
        $result = $limited->prefetchBatch();
        self::assertSame(1, $result['processed'], 'batch stops at the first rate limit');
        self::assertSame(2, $result['remaining']);
        self::assertNull($limited->ensure($a['id']), 'not retried within the hour');
        self::assertSame(1, $calls);

        // The 403 also paused Open Library covers in the rate limiter (real time) …
        self::assertGreaterThan(0, (new RateLimiter())->blockedFor('open_library_covers'));

        // … so "an hour later" needs both clocks moved forward.
        App::setNow('2026-10-09 13:30:00');
        $later = new RateLimiter(fn (): float => microtime(true) + 3600);
        $ok = new CoverService($this->db, fn () => ['status' => 200, 'body' => self::png(100, 150)], $later);
        self::assertSame(['processed' => 2, 'stored' => 2, 'remaining' => 0], $ok->prefetchBatch());
    }

    public function testPlaceholderPixelsAndInvalidDataAreRejected(): void
    {
        $book = $this->books->create(['title' => 'X', 'isbn' => '9783548283951', 'cover_url' => 'https://example.org/1px.gif'], $this->user['id']);
        $service = new CoverService($this->db, fn (string $url) => str_contains($url, '1px')
            ? ['status' => 200, 'body' => self::png(1, 1)]
            : ['status' => 200, 'body' => '<html>not an image</html>']);
        self::assertNull($service->ensure($book['id']));
        self::assertSame('none', $this->db->value('SELECT cover_status FROM books WHERE id = ?', [$book['id']]));
    }

    public function testChangingTheCoverUrlForgetsTheOldFile(): void
    {
        $book = $this->books->create(['title' => 'X', 'cover_url' => 'https://example.org/old.png'], $this->user['id']);
        $service = new CoverService($this->db, fn () => ['status' => 200, 'body' => self::png(100, 150)]);
        $path = $service->ensure($book['id'])['path'];
        self::assertFileExists($path);
        $version = $book['cover_version'];

        $updated = $this->books->update($book['id'], ['cover_url' => 'https://example.org/new.png'], $this->user['id']);
        self::assertFileDoesNotExist($path);
        self::assertNotSame($version, $updated['cover_version']);
        self::assertNotNull($service->ensure($book['id']));

        $path = $service->ensure($book['id'])['path'];
        $this->books->delete($book['id']);
        self::assertFileDoesNotExist($path, 'deleted with the book');
    }

    public function testOnlyPublicHostsAreFetched(): void
    {
        foreach ([
            'http://127.0.0.1/cover.jpg', 'http://localhost:8080/x.png', 'http://10.0.0.5/x.png', 'http://192.168.1.10/x.png',
            'http://172.16.0.1/x.png', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/x.png', 'http://0.0.0.0/',
            'ftp://93.184.215.14/x.png', 'file:///etc/passwd', 'http://user:pass@93.184.215.14/x.png', 'not a url',
        ] as $url) {
            self::assertNull(CoverService::publicTarget($url), $url);
        }
        self::assertSame(['host' => '93.184.215.14', 'port' => 443, 'ip' => '93.184.215.14'], CoverService::publicTarget('https://93.184.215.14/cover.jpg'));

        // A blocked target counts as "no cover", not as a temporary error.
        $book = $this->books->create(['title' => 'Intern', 'cover_url' => 'http://127.0.0.1:8081/secret.png'], $this->user['id']);
        self::assertNull((new CoverService($this->db))->ensure($book['id']));
        self::assertSame('none', $this->db->value('SELECT cover_status FROM books WHERE id = ?', [$book['id']]));
    }

    public function testPreviewIsFetchedButNotStored(): void
    {
        $calls = [];
        $service = new CoverService($this->db, function (string $url) use (&$calls) {
            $calls[] = $url;
            return str_contains($url, 'openlibrary') ? ['status' => 200, 'body' => self::png(80, 120)] : ['status' => 404, 'body' => null];
        });
        $preview = $service->preview('https://example.org/missing.jpg', '9783548283951');
        self::assertSame('image/png', $preview['mime']);
        self::assertSame(['https://example.org/missing.jpg', 'https://covers.openlibrary.org/b/isbn/9783548283951-M.jpg?default=false'], $calls);
        self::assertSame([], glob(CoverService::dir() . '/*') ?: []);
        self::assertNull($service->preview('javascript:alert(1)', 'nope'));
    }
}
