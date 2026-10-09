<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Looks up book metadata by ISBN: Open Library first, Google Books as fallback
 * for missing fields. Runs server-side so the browser needs no third-party
 * connect-src and results can be reused for background enrichment.
 */
final class MetadataService
{
    private const TIMEOUT = 8;
    /** Partial answers are retried this often before the available data counts as final. */
    public const MAX_ATTEMPTS = 3;
    private const RETRY_AFTER_HOURS = 6;

    /** @var (callable(string): ?string)|null injectable HTTP client for tests */
    private $httpGet;

    public function __construct(private readonly Database $db, ?callable $httpGet = null)
    {
        $this->httpGet = $httpGet;
    }

    /**
     * @return array<string, mixed>|null normalised metadata or null when nothing was found
     */
    public function lookup(string $rawIsbn): ?array
    {
        [$isbn13, $isbn10] = Isbn::normalize($rawIsbn);
        if ($isbn13 === null) {
            throw ApiException::validation(['isbn' => 'invalid_isbn']);
        }
        if (!Config::bool('METADATA_LOOKUP', true)) {
            return null;
        }

        // Transport errors are counted: "not found" is only final when every source answered.
        $failures = 0;
        try {
            $result = $this->openLibrary($isbn13) ?? [];
        } catch (MetadataUnavailable) {
            $result = [];
            $failures++;
        }
        $missing = array_filter(['title', 'authors', 'pages', 'cover_url', 'description', 'genre', 'publisher'], fn ($k) => empty($result[$k]));
        if ($missing !== []) {
            try {
                $google = $this->googleBooks($isbn13) ?? [];
            } catch (MetadataUnavailable) {
                $google = [];
                $failures++;
            }
            foreach ($google as $key => $value) {
                if (empty($result[$key]) && !empty($value)) {
                    $result[$key] = $value;
                }
            }
            if ($google !== []) {
                $result['sources'][] = 'google_books';
            }
        }
        if (empty($result['title'])) {
            if ($failures > 0) {
                throw new MetadataUnavailable('Metadata services unreachable');
            }
            return null;
        }
        // partial: a source failed, so missing fields may still be found later.
        return $result + ['isbn13' => $isbn13, 'isbn10' => $isbn10, 'partial' => $failures > 0];
    }

    /** @return array<string, mixed>|null */
    private function openLibrary(string $isbn): ?array
    {
        $json = $this->fetchJson('https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data');
        $data = $json['ISBN:' . $isbn] ?? null;
        if (!is_array($data) || empty($data['title'])) {
            return null;
        }
        $title = (string) $data['title'];
        if (!empty($data['subtitle'])) {
            $title .= ': ' . $data['subtitle'];
        }
        $year = null;
        if (!empty($data['publish_date']) && preg_match('/(\d{4})/', (string) $data['publish_date'], $m)) {
            $year = (int) $m[1];
        }
        $subjects = array_map(fn ($s) => is_array($s) ? ($s['name'] ?? '') : (string) $s, $data['subjects'] ?? []);
        $cover = $data['cover']['large'] ?? $data['cover']['medium'] ?? null;

        return [
            'title' => $title,
            'authors' => implode(', ', array_filter(array_map(fn ($a) => $a['name'] ?? '', $data['authors'] ?? []))),
            'publisher' => $data['publishers'][0]['name'] ?? null,
            'published_year' => $year,
            'pages' => (int) ($data['number_of_pages'] ?? 0),
            'genre' => self::pickGenre($subjects),
            'cover_url' => $cover ? preg_replace('#^http://#', 'https://', (string) $cover) : null,
            'description' => is_string($data['notes'] ?? null) ? $data['notes'] : null,
            'language' => null,
            'sources' => ['open_library'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function googleBooks(string $isbn): ?array
    {
        $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn;
        if ($key = Config::get('GOOGLE_BOOKS_API_KEY')) {
            $url .= '&key=' . rawurlencode($key);
        }
        $json = $this->fetchJson($url);
        $info = $json['items'][0]['volumeInfo'] ?? null;
        if (!is_array($info) || empty($info['title'])) {
            return null;
        }
        $title = (string) $info['title'];
        if (!empty($info['subtitle'])) {
            $title .= ': ' . $info['subtitle'];
        }
        $cover = $info['imageLinks']['thumbnail'] ?? $info['imageLinks']['smallThumbnail'] ?? null;
        if ($cover) {
            $cover = str_replace(['http://', '&edge=curl'], ['https://', ''], (string) $cover);
        }
        $year = null;
        if (!empty($info['publishedDate']) && preg_match('/(\d{4})/', (string) $info['publishedDate'], $m)) {
            $year = (int) $m[1];
        }
        return [
            'title' => $title,
            'authors' => implode(', ', $info['authors'] ?? []),
            'publisher' => $info['publisher'] ?? null,
            'published_year' => $year,
            'pages' => (int) ($info['pageCount'] ?? 0),
            'genre' => self::pickGenre($info['categories'] ?? []),
            'cover_url' => $cover,
            'description' => isset($info['description']) ? strip_tags((string) $info['description']) : null,
            'language' => $info['language'] ?? null,
        ];
    }

    /**
     * Fills empty catalogue fields for books that have never been checked.
     * Called repeatedly by the frontend after an import and by cron.php.
     *
     * Books are only marked as checked when the lookup really answered; on
     * network/rate-limit errors the batch stops and they are retried later.
     *
     * @return array{processed: int, updated: int, partial: int, remaining: int, unavailable: bool}
     */
    public function enrichBatch(int $limit = 5, float $maxSeconds = 20.0): array
    {
        $start = microtime(true);
        [$where, $params] = $this->dueCondition();
        $books = $this->db->all(
            "SELECT * FROM books WHERE $where ORDER BY metadata_attempts, id LIMIT " . max(1, min(50, $limit)),
            $params
        );
        $processed = 0;
        $updated = 0;
        $partials = 0;
        $unavailable = false;
        foreach ($books as $book) {
            if (microtime(true) - $start > $maxSeconds) {
                break;
            }
            try {
                $meta = $this->lookup((string) $book['isbn13']);
            } catch (MetadataUnavailable) {
                // No source answered (network down): stop and retry the whole batch later.
                $unavailable = true;
                break;
            } catch (ApiException) {
                $meta = null; // invalid ISBN: nothing to look up, mark as checked
            }
            $processed++;
            $now = App::nowString();
            $attempts = (int) $book['metadata_attempts'] + 1;
            // A partial answer (one source failed, e.g. Google Books without API key) fills what
            // it can; the book is retried later and accepted as final after MAX_ATTEMPTS.
            $partial = (bool) ($meta['partial'] ?? false) && $attempts < self::MAX_ATTEMPTS;
            $changes = ['metadata_attempts' => $attempts, 'metadata_attempted_at' => $now];
            if (!$partial) {
                $changes['metadata_checked_at'] = $now;
            } else {
                $partials++;
            }
            if ($meta !== null) {
                $fields = 0;
                foreach (['publisher', 'published_year', 'genre', 'language', 'description', 'cover_url'] as $field) {
                    if (empty($book[$field]) && !empty($meta[$field])) {
                        $changes[$field] = is_string($meta[$field]) ? mb_substr($meta[$field], 0, $field === 'description' ? 20000 : ($field === 'cover_url' ? 500 : 100)) : $meta[$field];
                        $fields++;
                    }
                }
                if ((int) $book['pages'] === 0 && !empty($meta['pages'])) {
                    $changes['pages'] = (int) $meta['pages'];
                    $fields++;
                }
                if (trim((string) $book['authors']) === '' && !empty($meta['authors'])) {
                    $changes['authors'] = mb_substr((string) $meta['authors'], 0, 255);
                    $fields++;
                }
                if ($fields > 0) {
                    $updated++;
                    $changes['updated_at'] = $now;
                }
            }
            $this->db->update('books', $changes, ['id' => (int) $book['id']]);
        }
        return [
            'processed' => $processed,
            'updated' => $updated,
            'partial' => $partials,
            'remaining' => $this->pendingCount(),
            'unavailable' => $unavailable,
        ];
    }

    /** Books that are due for a (first or repeated) metadata lookup right now. */
    public function pendingCount(): int
    {
        [$where, $params] = $this->dueCondition();
        return (int) $this->db->value("SELECT COUNT(*) FROM books WHERE $where", $params);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function dueCondition(): array
    {
        return [
            'metadata_checked_at IS NULL AND isbn13 IS NOT NULL AND (metadata_attempted_at IS NULL OR metadata_attempted_at < :retry_before)',
            ['retry_before' => App::now()->modify('-' . self::RETRY_AFTER_HOURS . ' hours')->format('Y-m-d H:i:s')],
        ];
    }

    /** @param list<string> $subjects */
    private static function pickGenre(array $subjects): ?string
    {
        foreach ($subjects as $subject) {
            $subject = trim((string) $subject);
            // Skip Open Library housekeeping subjects like "nyt:..." or "Accessible book".
            if ($subject === '' || str_contains($subject, ':') || preg_match('/accessible book|protected daisy|in library|large type/i', $subject)) {
                continue;
            }
            return mb_substr(preg_replace('/\s*\/.*$/', '', $subject) ?? $subject, 0, 100);
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null null when the service answered "nothing found"
     * @throws MetadataUnavailable on network errors, rate limits and server errors
     */
    private function fetchJson(string $url): ?array
    {
        $body = $this->httpGet !== null ? ($this->httpGet)($url) : self::httpGet($url);
        if ($body === null || $body === '') {
            return null;
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    public static function httpGet(string $url): ?string
    {
        $userAgent = 'BookSpot/' . App::VERSION . ' (self-hosted book inventory; ' . (Config::get('APP_URL') ?? 'https://github.com/tobilektri/BookSpot') . ')';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_USERAGENT => $userAgent,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return self::interpret(is_string($body) ? $body : null, $status);
        }
        $context = stream_context_create(['http' => [
            'timeout' => self::TIMEOUT,
            'header' => "User-Agent: $userAgent\r\nAccept: application/json\r\n",
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return self::interpret($body === false ? null : $body, $status);
    }

    private static function interpret(?string $body, int $status): ?string
    {
        if ($status === 0 || $status === 429 || $status >= 500) {
            throw new MetadataUnavailable("HTTP status $status");
        }
        return ($body !== null && $status >= 200 && $status < 300) ? $body : null;
    }
}
