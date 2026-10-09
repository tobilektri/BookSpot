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

        $result = $this->openLibrary($isbn13) ?? [];
        $missing = array_filter(['title', 'authors', 'pages', 'cover_url', 'description', 'genre', 'publisher'], fn ($k) => empty($result[$k]));
        if ($missing !== []) {
            $google = $this->googleBooks($isbn13) ?? [];
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
            return null;
        }
        return $result + ['isbn13' => $isbn13, 'isbn10' => $isbn10];
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
     * @return array{processed: int, updated: int, remaining: int}
     */
    public function enrichBatch(int $limit = 5, float $maxSeconds = 20.0): array
    {
        $start = microtime(true);
        $books = $this->db->all(
            'SELECT * FROM books WHERE metadata_checked_at IS NULL AND isbn13 IS NOT NULL ORDER BY id LIMIT ' . max(1, min(50, $limit))
        );
        $processed = 0;
        $updated = 0;
        foreach ($books as $book) {
            if (microtime(true) - $start > $maxSeconds) {
                break;
            }
            $processed++;
            $changes = ['metadata_checked_at' => App::nowString()];
            try {
                $meta = $this->lookup((string) $book['isbn13']);
            } catch (\Throwable) {
                $meta = null;
            }
            if ($meta !== null) {
                foreach (['publisher', 'published_year', 'genre', 'language', 'description', 'cover_url'] as $field) {
                    if (empty($book[$field]) && !empty($meta[$field])) {
                        $changes[$field] = is_string($meta[$field]) ? mb_substr($meta[$field], 0, $field === 'description' ? 20000 : ($field === 'cover_url' ? 500 : 100)) : $meta[$field];
                    }
                }
                if ((int) $book['pages'] === 0 && !empty($meta['pages'])) {
                    $changes['pages'] = (int) $meta['pages'];
                }
                if (trim((string) $book['authors']) === '' && !empty($meta['authors'])) {
                    $changes['authors'] = mb_substr((string) $meta['authors'], 0, 255);
                }
                if (count($changes) > 1) {
                    $updated++;
                    $changes['updated_at'] = App::nowString();
                }
            }
            $this->db->update('books', $changes, ['id' => (int) $book['id']]);
        }
        $remaining = (int) $this->db->value('SELECT COUNT(*) FROM books WHERE metadata_checked_at IS NULL AND isbn13 IS NOT NULL');
        return ['processed' => $processed, 'updated' => $updated, 'remaining' => $remaining];
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

    /** @return array<string, mixed>|null */
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
            return (is_string($body) && $status >= 200 && $status < 300) ? $body : null;
        }
        $context = stream_context_create(['http' => [
            'timeout' => self::TIMEOUT,
            'header' => "User-Agent: $userAgent\r\nAccept: application/json\r\n",
            'ignore_errors' => false,
        ]]);
        $body = @file_get_contents($url, false, $context);
        return $body === false ? null : $body;
    }
}
