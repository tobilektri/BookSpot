<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Downloads book covers once and serves them from var/covers/.
 *
 * Why not hotlinking: the PWA's Content-Security-Policy only allows its own
 * origin for fetch() (service worker), external hosts may be slow, rate
 * limited or gone, and local files also work offline.
 *
 * Sources in order: the book's cover_url, then Open Library by ISBN. Results
 * are remembered (cover_status) so missing covers are not requested again on
 * every page view: "none" is retried after 30 days, transient errors (rate
 * limit, network) after an hour.
 */
final class CoverService
{
    private const MAX_BYTES = 4 * 1024 * 1024;
    private const MAX_HEIGHT = 600;
    private const RETRY_ERROR_MINUTES = 60;
    private const RETRY_NONE_DAYS = 30;
    private const MIME = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    /** @var (callable(string): array{status: int, body: ?string})|null test double */
    private $httpGet;

    public function __construct(private readonly Database $db, ?callable $httpGet = null)
    {
        $this->httpGet = $httpGet;
    }

    /** Storage directory override (tests). */
    public static ?string $directory = null;

    public static function dir(): string
    {
        $dir = self::$directory ?? App::varDir() . '/covers';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Version string for the cover URL: changes when the source of the cover changes. */
    public static function version(array $book): string
    {
        return substr(md5((string) ($book['cover_url'] ?? '') . '|' . ($book['isbn13'] ?? '')), 0, 8);
    }

    /**
     * Local file for the book, downloading it first when due.
     *
     * @return array{path: string, mime: string}|null
     */
    public function ensure(int $bookId): ?array
    {
        $book = $this->db->one('SELECT id, isbn13, cover_url, cover_file, cover_status, cover_checked_at FROM books WHERE id = ?', [$bookId]);
        if ($book === null) {
            return null;
        }
        $local = $this->local($book);
        if ($local !== null) {
            return $local;
        }
        if (!$this->due($book)) {
            return null;
        }
        $this->fetch($book);
        $book = $this->db->one('SELECT cover_file FROM books WHERE id = ?', [$bookId]) ?? [];
        return $this->local($book);
    }

    /**
     * Tries all sources and stores the first valid image.
     *
     * @return string "ok" | "none" | "error"
     */
    public function fetch(array $book): string
    {
        $transient = false;
        $stored = null;
        foreach (self::candidates($book) as $url) {
            $response = $this->download($url);
            if ($response['status'] === 200 && $response['body'] !== null) {
                $stored = $this->store((int) $book['id'], $response['body']);
                if ($stored !== null) {
                    break;
                }
            } elseif (self::isTransient($response['status'], $url)) {
                $transient = true;
            }
        }
        $status = $stored !== null ? 'ok' : ($transient ? 'error' : 'none');
        if ($stored === null && !empty($book['cover_file'])) {
            @unlink(self::dir() . '/' . basename((string) $book['cover_file']));
        }
        $this->db->update('books', [
            'cover_file' => $stored,
            'cover_status' => $status,
            'cover_checked_at' => App::nowString(),
        ], ['id' => (int) $book['id']]);
        return $status;
    }

    /**
     * Downloads covers that were never tried (or are due again) – used by cron
     * so most covers are local before anyone looks at them.
     *
     * @return array{processed: int, stored: int, remaining: int}
     */
    public function prefetchBatch(int $limit = 30, float $maxSeconds = 40.0): array
    {
        $start = microtime(true);
        $processed = 0;
        $stored = 0;
        $rows = $this->db->all(
            "SELECT id, isbn13, cover_url, cover_file, cover_status, cover_checked_at FROM books
             WHERE cover_file IS NULL AND (cover_status IS NULL OR cover_status = 'error')
               AND (cover_url IS NOT NULL OR isbn13 IS NOT NULL)
             ORDER BY cover_checked_at IS NOT NULL, cover_checked_at, id LIMIT " . max(1, min(200, $limit))
        );
        foreach ($rows as $book) {
            if (microtime(true) - $start > $maxSeconds) {
                break;
            }
            if (!$this->due($book)) {
                continue;
            }
            $processed++;
            $status = $this->fetch($book);
            if ($status === 'ok') {
                $stored++;
            } elseif ($status === 'error') {
                break; // rate limited or offline: try again with the next run
            }
        }
        $remaining = (int) $this->db->value(
            "SELECT COUNT(*) FROM books WHERE cover_file IS NULL AND (cover_status IS NULL OR cover_status = 'error') AND (cover_url IS NOT NULL OR isbn13 IS NOT NULL)"
        );
        return ['processed' => $processed, 'stored' => $stored, 'remaining' => $remaining];
    }

    /** Removes the local file and forgets the status (cover URL/ISBN changed, book deleted). */
    public function forget(int $bookId, ?string $file = null): void
    {
        if ($file === null) {
            $file = $this->db->value('SELECT cover_file FROM books WHERE id = ?', [$bookId]);
        }
        if (is_string($file) && $file !== '') {
            @unlink(self::dir() . '/' . basename($file));
        }
        $this->db->run('UPDATE books SET cover_file = NULL, cover_status = NULL, cover_checked_at = NULL WHERE id = ?', [$bookId]);
    }

    /** @return list<string> */
    public static function candidates(array $book): array
    {
        $urls = [];
        $coverUrl = trim((string) ($book['cover_url'] ?? ''));
        if (preg_match('#^https?://#i', $coverUrl)) {
            $urls[] = $coverUrl; // downloaded server-side, so plain http is fine too
        }
        if (!empty($book['isbn13'])) {
            // default=false: 404 instead of a blank placeholder image when Open Library has no cover.
            $urls[] = 'https://covers.openlibrary.org/b/isbn/' . $book['isbn13'] . '-L.jpg?default=false';
        }
        return array_values(array_unique($urls));
    }

    /** Network errors, rate limits and server errors are worth retrying; Open Library rate-limits with 403. */
    private static function isTransient(int $status, string $url): bool
    {
        return $status === 0 || $status === 429 || $status >= 500
            || ($status === 403 && str_contains($url, 'covers.openlibrary.org'));
    }

    private function due(array $book): bool
    {
        if (empty($book['cover_checked_at']) || empty($book['cover_status'])) {
            return true;
        }
        $checked = new \DateTimeImmutable((string) $book['cover_checked_at'], App::timezone());
        return match ($book['cover_status']) {
            'error' => $checked < App::now()->modify('-' . self::RETRY_ERROR_MINUTES . ' minutes'),
            'none' => $checked < App::now()->modify('-' . self::RETRY_NONE_DAYS . ' days'),
            default => true, // "ok" but file missing (e.g. var/ was cleaned): download again
        };
    }

    /** @return array{path: string, mime: string}|null */
    private function local(array $book): ?array
    {
        $file = (string) ($book['cover_file'] ?? '');
        if ($file === '') {
            return null;
        }
        $path = self::dir() . '/' . basename($file);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return is_file($path) && isset(self::MIME[$ext]) ? ['path' => $path, 'mime' => self::MIME[$ext]] : null;
    }

    /**
     * Validates the image and stores it (downscaled to MAX_HEIGHT as JPEG when GD
     * is available). Returns the file name or null for invalid images.
     */
    private function store(int $bookId, string $body): ?string
    {
        if (strlen($body) > self::MAX_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($body);
        // Tiny images are placeholders ("no cover" pixels), not covers.
        if ($info === false || $info[0] < 20 || $info[1] < 20) {
            return null;
        }
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
        if ($ext === null) {
            return null;
        }
        $dir = self::dir();
        foreach (glob($dir . '/' . $bookId . '.*') ?: [] as $old) {
            @unlink($old);
        }
        if (function_exists('imagecreatefromstring') && $info[1] > self::MAX_HEIGHT) {
            $image = @imagecreatefromstring($body);
            if ($image !== false) {
                $height = self::MAX_HEIGHT;
                $width = max(1, (int) round($info[0] * $height / $info[1]));
                $scaled = imagescale($image, $width, $height, IMG_BICUBIC);
                if ($scaled !== false && imagejpeg($scaled, "$dir/$bookId.jpg", 82)) {
                    return "$bookId.jpg";
                }
            }
        }
        return @file_put_contents("$dir/$bookId.$ext", $body) === false ? null : "$bookId.$ext";
    }

    /** @return array{status: int, body: ?string} status 0 = network error */
    private function download(string $url): array
    {
        if ($this->httpGet !== null) {
            return ($this->httpGet)($url);
        }
        $userAgent = 'BookSpot/' . App::VERSION . ' (self-hosted book inventory)';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => is_string($body) ? $status : 0, 'body' => is_string($body) ? $body : null];
        }
        $context = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: $userAgent\r\n", 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? null : $body];
    }
}
