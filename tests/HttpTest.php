<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\BookService;
use BookSpot\Config;
use BookSpot\CoverService;
use BookSpot\HttpStream;
use BookSpot\MetadataService;
use BookSpot\MetadataUnavailable;
use BookSpot\ShelfService;

/**
 * Real HTTP requests against a local `php -S` server – the other tests replace
 * HTTP with fakes, so this is what catches problems in the transport code
 * (e.g. functions that a new PHP version deprecates).
 */
final class HttpTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        for ($attempt = 0; $attempt < 5 && self::$server === null; $attempt++) {
            $port = random_int(20000, 45000);
            $process = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fixtures/http-server.php'],
                [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $pipes
            );
            for ($i = 0; $i < 50; $i++) {
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    self::$server = $process;
                    self::$base = "http://127.0.0.1:$port";
                    break;
                }
                usleep(50_000);
            }
            if (self::$server === null && is_resource($process)) {
                proc_terminate($process);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        self::$server = null;
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$server === null) {
            self::markTestSkipped('local PHP server could not be started');
        }
    }

    public function testMetadataRequests(): void
    {
        $json = json_decode((string) MetadataService::httpGet(self::$base . '/json', 'application/xml'), true);
        self::assertSame(App::userAgent(), $json['ua']);
        self::assertSame('application/xml', $json['accept']);

        self::assertNull(MetadataService::httpGet(self::$base . '/missing'), '404 = not found');

        try {
            MetadataService::httpGet(self::$base . '/limited');
            self::fail('429 must throw');
        } catch (MetadataUnavailable $e) {
            self::assertSame(429, $e->status);
            self::assertSame(120, $e->retryAfter);
        }
        $this->expectException(MetadataUnavailable::class);
        MetadataService::httpGet(self::$base . '/broken');
    }

    public function testStreamFallback(): void
    {
        $ok = HttpStream::get(self::$base . '/json', ['header' => "User-Agent: test\r\n"]);
        self::assertSame(200, $ok['status']);
        self::assertSame('test', json_decode((string) $ok['body'], true)['ua']);

        $limited = HttpStream::get(self::$base . '/limited', []);
        self::assertSame(429, $limited['status']);
        self::assertContains('Retry-After: 120', $limited['headers']);

        $redirect = HttpStream::get(self::$base . '/redirect', ['follow_location' => 0]);
        self::assertSame(302, $redirect['status']);
        self::assertContains('Location: /cover.png', $redirect['headers']);

        self::assertSame(0, HttpStream::get('http://127.0.0.1:1/', ['timeout' => 1])['status'], 'connection refused');
    }

    public function testStreamFallbackConnectsToTheCheckedAddressOnly(): void
    {
        $port = (int) parse_url(self::$base, PHP_URL_PORT);
        // The host name does not resolve – the request still reaches the pinned, checked IP.
        $pinned = HttpStream::get("http://covers.invalid:$port/json", ['header' => "User-Agent: pin\r\n"], ['host' => 'covers.invalid', 'port' => $port, 'ip' => '127.0.0.1']);
        self::assertSame(200, $pinned['status']);
        self::assertSame('pin', json_decode((string) $pinned['body'], true)['ua']);

        $tooLarge = HttpStream::get(self::$base . '/cover.png', [], null, 100);
        self::assertSame([0, null], [$tooLarge['status'], $tooLarge['body']], 'bodies above the limit are refused');
    }

    public function testCoverDownloadFollowsCheckedRedirects(): void
    {
        Config::set('COVER_ALLOW_PRIVATE_HOSTS', 'true');
        CoverService::$directory = sys_get_temp_dir() . '/bookspot-http-covers-' . bin2hex(random_bytes(4));
        try {
            $user = $this->createUser();
            $books = new BookService($this->db, new ShelfService($this->db));
            $book = $books->create(['title' => 'Lokal'], $user['id']);
            // Set directly: the form upgrades http:// cover URLs to https://.
            $this->db->update('books', ['cover_url' => self::$base . '/redirect'], ['id' => (int) $book['id']]);
            $file = (new CoverService($this->db))->ensure((int) $book['id']);
            self::assertNotNull($file);
            self::assertSame([60, 90], array_slice((array) getimagesize($file['path']), 0, 2));
        } finally {
            Config::set('COVER_ALLOW_PRIVATE_HOSTS', null);
            array_map('unlink', glob(CoverService::dir() . '/*') ?: []);
            @rmdir(CoverService::dir());
            CoverService::$directory = null;
        }
    }

    public function testLogWorksWithoutErrorLogFunction(): void
    {
        App::$logFile = sys_get_temp_dir() . '/bookspot-log-' . bin2hex(random_bytes(4));
        try {
            App::log('[BookSpot] test entry');
            self::assertMatchesRegularExpression('/^\[\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2} [^\]]+\] \[BookSpot\] test entry$/', trim((string) file_get_contents(App::$logFile)));
        } finally {
            @unlink(App::$logFile);
            App::$logFile = null;
        }
    }
}
