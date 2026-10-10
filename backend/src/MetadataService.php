<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Looks up book metadata by ISBN: Open Library first, then the Deutsche
 * Nationalbibliothek and Google Books for fields that are still missing.
 * Runs server-side so the browser needs no third-party
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

    /**
     * Longest wait in seconds for a free slot of a provider (see RateLimiter). A busy or
     * blocked provider is skipped – the next source fills in, the book is retried later.
     */
    public float $maxWait = 3.0;

    private RateLimiter $limiter;

    public function __construct(private readonly Database $db, ?callable $httpGet = null, ?RateLimiter $limiter = null)
    {
        $this->httpGet = $httpGet;
        $this->limiter = $limiter ?? new RateLimiter();
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

        // Sources in order of preference; later sources only fill fields that are still empty.
        // Transport errors are counted: "not found" is only final when every source answered.
        $sources = [
            'open_library' => $this->openLibrary(...),
            'dnb' => $this->dnb(...),
            'google_books' => $this->googleBooks(...),
        ];
        $result = [];
        $failures = 0;
        foreach ($sources as $name => $source) {
            if ($result !== [] && self::missingFields($result) === []) {
                break;
            }
            try {
                $data = $source($isbn13) ?? [];
            } catch (MetadataUnavailable) {
                $failures++;
                continue;
            }
            $added = false;
            foreach ($data as $key => $value) {
                if (empty($result[$key]) && !empty($value)) {
                    $result[$key] = $value;
                    $added = true;
                }
            }
            if ($added) {
                $result['sources'][] = $name;
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
        $json = $this->fetchJson('open_library', 'https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data');
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
        ];
    }

    /** @return list<string> */
    private static function missingFields(array $result): array
    {
        $wanted = ['title', 'authors', 'pages', 'published_year', 'publisher', 'genre', 'cover_url', 'description'];
        return array_values(array_filter($wanted, fn ($k) => empty($result[$k])));
    }

    /**
     * Deutsche Nationalbibliothek (SRU, MARC21-xml): free, no API key, very complete for
     * German-language editions. The "Inhaltstext" (blurb) is fetched as description.
     *
     * @return array<string, mixed>|null
     */
    private function dnb(string $isbn): ?array
    {
        if (!Config::bool('METADATA_DNB', true) || !class_exists(\DOMDocument::class)) {
            return null;
        }
        $url = 'https://services.dnb.de/sru/dnb?version=1.1&operation=searchRetrieve&maximumRecords=1&recordSchema=MARC21-xml&query='
            . rawurlencode('num=' . $isbn);
        $xml = $this->fetchText('dnb', $url, 'application/xml');
        $record = $xml === null ? null : self::parseDnbRecord($xml);
        if ($record === null) {
            return null;
        }
        if ($record['blurb_url'] !== null) {
            try {
                $record['description'] = $this->dnbBlurb($record['blurb_url']);
            } catch (MetadataUnavailable) {
                // The record itself is complete enough; the blurb is optional.
            }
        }
        unset($record['blurb_url']);
        return $record;
    }

    /**
     * Parses the first MARC21 record of a DNB SRU response.
     *
     * @return array<string, mixed>|null
     */
    public static function parseDnbRecord(string $xml): ?array
    {
        $dom = new \DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            return null;
        }
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('m', 'http://www.loc.gov/MARC21/slim');
        $record = $xpath->query('//m:record')?->item(0);
        if ($record === null) {
            return null;
        }
        $fields = function (string $tag) use ($xpath, $record): array {
            $result = [];
            foreach ($xpath->query("m:datafield[@tag='$tag']", $record) ?: [] as $field) {
                $subfields = [];
                foreach ($xpath->query('m:subfield', $field) ?: [] as $subfield) {
                    $subfields[$subfield->getAttribute('code')][] = trim($subfield->textContent);
                }
                $result[] = $subfields;
            }
            return $result;
        };
        $first = fn (string $tag, string $code) => $fields($tag)[0][$code][0] ?? null;
        // MARC keeps ISBD punctuation ("Impact :", "Berlin ;"), strip it.
        $clean = fn (?string $v) => $v === null ? null : (trim(rtrim(trim($v), ' /:;,.='), " []") ?: null);

        $title = $clean($first('245', 'a'));
        if ($title === null) {
            return null;
        }
        $subtitle = $clean($first('245', 'b'));

        $authors = [];
        foreach ([...$fields('100'), ...$fields('700')] as $field) {
            $relator = $field['4'][0] ?? 'aut';
            if ($relator === 'aut' && isset($field['a'][0])) {
                $authors[] = self::invertName($field['a'][0]);
            }
        }

        $imprint = $fields('264')[0] ?? $fields('260')[0] ?? [];
        $year = null;
        if (preg_match('/(\d{4})/', $imprint['c'][0] ?? '', $m)) {
            $year = (int) $m[1];
        } else {
            $control008 = $xpath->query("m:controlfield[@tag='008']", $record)?->item(0)?->textContent ?? '';
            if (preg_match('/^\d{4}$/', substr($control008, 7, 4))) {
                $year = (int) substr($control008, 7, 4);
            }
        }

        $pages = 0;
        if (preg_match_all('/(\d+)\s*(?:S\.|Seiten|p\.|pages)/u', $first('300', 'a') ?? '', $m)) {
            $pages = (int) end($m[1]);
        }

        $genre = null;
        foreach ($fields('653') as $field) {
            $keyword = $field['a'][0] ?? '';
            // Skip product form notes "(Produktform)…" and numeric codes.
            if ($keyword !== '' && !str_starts_with($keyword, '(') && !ctype_digit($keyword)) {
                $genre = mb_substr($keyword, 0, 100);
                break;
            }
        }

        $blurb = null;
        foreach ($fields('856') as $field) {
            if (($field['3'][0] ?? '') === 'Inhaltstext' && isset($field['u'][0])) {
                $blurb = $field['u'][0];
                break;
            }
        }

        $languages = ['ger' => 'de', 'eng' => 'en', 'fre' => 'fr', 'spa' => 'es', 'ita' => 'it', 'dut' => 'nl', 'swe' => 'sv', 'dan' => 'da', 'nor' => 'no', 'pol' => 'pl'];
        $language = $first('041', 'a');

        return [
            'title' => $subtitle ? "$title: $subtitle" : $title,
            'authors' => implode(', ', array_unique($authors)),
            'publisher' => $clean($imprint['b'][0] ?? null),
            'published_year' => $year,
            'pages' => $pages,
            'genre' => $genre,
            'language' => $language === null ? null : ($languages[$language] ?? $language),
            'cover_url' => null,
            'description' => null,
            'blurb_url' => $blurb,
        ];
    }

    /** "Steinhardt, Bernd" -> "Bernd Steinhardt" */
    private static function invertName(string $name): string
    {
        $parts = array_map('trim', explode(',', $name, 2));
        return count($parts) === 2 && $parts[1] !== '' ? $parts[1] . ' ' . $parts[0] : $name;
    }

    /** Blurb ("Inhaltstext") page linked from the DNB record, as plain text. */
    private function dnbBlurb(string $url): ?string
    {
        // Only follow links to DNB hosts – the URL comes from external data.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!str_starts_with($url, 'https://') || !preg_match('/(^|\.)(dnb\.de|d-nb\.info)$/', $host)) {
            return null;
        }
        $html = $this->fetchText('dnb', $url, 'text/html');
        return $html === null ? null : self::htmlToText($html);
    }

    public static function htmlToText(string $html): ?string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6])>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\s*\n\s*/u", "\n", $text) ?? $text) ?? $text);
        return mb_strlen($text) >= 20 ? mb_substr($text, 0, 20000) : null;
    }

    /** @return array<string, mixed>|null */
    private function googleBooks(string $isbn): ?array
    {
        $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn;
        if ($key = Config::get('GOOGLE_BOOKS_API_KEY')) {
            $url .= '&key=' . rawurlencode($key);
        }
        $json = $this->fetchJson('google_books', $url);
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
                if (isset($changes['cover_url'])) {
                    // New cover source: let CoverService download it (again).
                    $changes['cover_status'] = null;
                    $changes['cover_checked_at'] = null;
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
    private function fetchJson(string $provider, string $url): ?array
    {
        $body = $this->fetchText($provider, $url, 'application/json');
        $json = $body === null ? null : json_decode($body, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Rate-limited request to a provider. 429 blocks the provider with exponential
     * backoff (or its Retry-After), so following lookups go straight to the next source.
     *
     * @throws MetadataUnavailable
     */
    private function fetchText(string $provider, string $url, string $accept): ?string
    {
        if (!$this->limiter->throttle($provider, RateLimiter::providerInterval($provider), $this->maxWait)) {
            throw new MetadataUnavailable("$provider is rate limited", -1);
        }
        try {
            $body = $this->httpGet !== null ? ($this->httpGet)($url) : self::httpGet($url, $accept);
        } catch (MetadataUnavailable $e) {
            if ($e->status === 429) {
                $seconds = $this->limiter->penalize($provider, $e->retryAfter);
                error_log("[BookSpot] $provider answered 429 – paused for {$seconds}s");
            }
            throw $e;
        }
        $this->limiter->recover($provider);
        return ($body === null || $body === '') ? null : $body;
    }

    /** Seconds a provider is still paused after a 429 (status display). */
    public function providerBlockedFor(string $provider): int
    {
        return (int) ceil($this->limiter->blockedFor($provider));
    }

    public static function httpGet(string $url, string $accept = 'application/json'): ?string
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
                CURLOPT_HTTPHEADER => ['Accept: ' . $accept],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                    if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $m)) {
                        $retryAfter = (int) $m[1];
                    }
                    return strlen($line);
                },
            ]);
            $retryAfter = null;
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return self::interpret(is_string($body) ? $body : null, $status, $retryAfter);
        }
        $context = stream_context_create(['http' => [
            'timeout' => self::TIMEOUT,
            'header' => "User-Agent: $userAgent\r\nAccept: $accept\r\n",
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        $retryAfter = null;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            } elseif (preg_match('/^Retry-After:\s*(\d+)/i', $header, $m)) {
                $retryAfter = (int) $m[1];
            }
        }
        return self::interpret($body === false ? null : $body, $status, $retryAfter);
    }

    private static function interpret(?string $body, int $status, ?int $retryAfter = null): ?string
    {
        if ($status === 0 || $status === 429 || $status >= 500) {
            throw new MetadataUnavailable("HTTP status $status", $status, $retryAfter);
        }
        return ($body !== null && $status >= 200 && $status < 300) ? $body : null;
    }
}
