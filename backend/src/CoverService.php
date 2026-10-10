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
 * Sources in order: the book's cover_url, then Open Library and the German
 * National Library's cover service (publisher covers from the VLB) by ISBN –
 * for German ISBNs (978-3) the DNB first. Results
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

    /** Longest wait for a free slot of a rate-limited source; cron allows more than a page view. */
    public float $maxWait = 2.0;

    private RateLimiter $limiter;

    public function __construct(private readonly Database $db, ?callable $httpGet = null, ?RateLimiter $limiter = null)
    {
        $this->limiter = $limiter ?? new RateLimiter();
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
            $provider = self::rateLimitedProvider($url);
            if ($provider !== null && !$this->limiter->throttle($provider, RateLimiter::providerInterval($provider), $this->maxWait)) {
                $transient = true; // provider busy or blocked after a rate limit: retry later
                continue;
            }
            $response = $this->download($url);
            if ($provider !== null) {
                if ($response['status'] === 429 || $response['status'] === 403) {
                    $this->limiter->penalize($provider);
                } elseif ($response['status'] === 200) {
                    $this->limiter->recover($provider);
                }
            }
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
            array_push($urls, ...self::isbnCandidates((string) $book['isbn13'], 'L'));
        }
        return array_values(array_unique($urls));
    }

    /** @return list<string> */
    private static function isbnCandidates(string $isbn13, string $size): array
    {
        if (!preg_match('/^97[89]\d{10}$/', $isbn13)) {
            return [];
        }
        // default=false: 404 instead of a blank placeholder image when Open Library has no cover.
        $openLibrary = 'https://covers.openlibrary.org/b/isbn/' . $isbn13 . '-' . $size . '.jpg?default=false';
        if (!Config::bool('COVER_DNB', true)) {
            return [$openLibrary];
        }
        // Publisher covers (VLB) via the DNB; 404 when there is none.
        $dnb = 'https://portal.dnb.de/opac/mvb/cover?isbn=' . $isbn13;
        // German-language ISBNs: the DNB has them far more often and it spares Open Library's tight limit.
        return str_starts_with($isbn13, '9783') ? [$dnb, $openLibrary] : [$openLibrary, $dnb];
    }

    /**
     * Open Library allows only 100 cover requests by ISBN per 5 minutes and IP
     * (covers by ID are not limited); the DNB is throttled out of politeness.
     */
    private static function rateLimitedProvider(string $url): ?string
    {
        return match (true) {
            (bool) preg_match('#^https://covers\.openlibrary\.org/b/(isbn|olid)/#', $url) => 'open_library_covers',
            str_starts_with($url, 'https://portal.dnb.de/opac/mvb/cover') => 'dnb_covers',
            default => null,
        };
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

    /**
     * Image for the form preview of a book that is not saved yet (cover URL or
     * ISBN typed in). Fetched through the server so the browser never loads
     * external images; nothing is stored.
     *
     * @return array{body: string, mime: string}|null
     */
    public function preview(?string $url, ?string $isbn13): ?array
    {
        $candidates = [];
        if ($url !== null && preg_match('#^https?://#i', $url)) {
            $candidates[] = $url;
        }
        if ($isbn13 !== null) {
            array_push($candidates, ...self::isbnCandidates($isbn13, 'M'));
        }
        foreach ($candidates as $candidate) {
            $response = $this->download($candidate);
            if ($response['status'] !== 200 || $response['body'] === null || strlen($response['body']) > self::MAX_BYTES) {
                continue;
            }
            $info = @getimagesizefromstring($response['body']);
            if ($info !== false && $info[0] >= 20 && $info[1] >= 20 && in_array($info['mime'] ?? '', self::MIME, true)) {
                return ['body' => $response['body'], 'mime' => $info['mime']];
            }
        }
        return null;
    }

    /**
     * Only public internet hosts may be fetched: a cover URL is user input, and
     * without this check the server could be made to request internal services
     * (SSRF: 127.0.0.1, 10.x, 192.168.x, cloud metadata 169.254.169.254, …).
     * Returns the resolved public IP, or null when the URL must not be fetched.
     */
    public static function publicTarget(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return null;
        }
        if (!Config::bool('COVER_ALLOW_PRIVATE_HOSTS', false)) {
            foreach ($ips as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return null;
                }
            }
        }
        return ['host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    /** @return array{status: int, body: ?string} status 0 = network error, -1 = blocked target */
    private function download(string $url, int $redirects = 3): array
    {
        if ($this->httpGet !== null) {
            return ($this->httpGet)($url);
        }
        $target = self::publicTarget($url);
        if ($target === null) {
            return ['status' => -1, 'body' => null];
        }
        $userAgent = App::userAgent();
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false, // redirects are checked one by one below
                CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // Connect to the IP that was checked (no DNS rebinding between check and request).
                CURLOPT_RESOLVE => [$target['host'] . ':' . $target['port'] . ':' . $target['ip']],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($status >= 300 && $status < 400 && $location !== '') {
                return $redirects > 0 ? $this->download($location, $redirects - 1) : ['status' => -1, 'body' => null];
            }
            return ['status' => is_string($body) ? $status : 0, 'body' => is_string($body) ? $body : null];
        }
        $context = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: $userAgent\r\n", 'ignore_errors' => true, 'follow_location' => 0]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        $location = '';
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            if (!preg_match('#^https?://#i', $location)) {
                $location = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . '/' . ltrim($location, '/');
            }
            return $redirects > 0 ? $this->download($location, $redirects - 1) : ['status' => -1, 'body' => null];
        }
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? null : $body];
    }
}
