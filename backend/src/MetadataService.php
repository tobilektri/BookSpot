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
    /**
     * Partial answers (a source was down, e.g. Google Books with its frequent 503) are retried
     * this often – at growing intervals – before the available data counts as final.
     */
    public const MAX_ATTEMPTS = 8;
    /** Hours until the next try after attempt 1, 2, … (the last value repeats). */
    private const RETRY_HOURS = [6, 12, 24, 48, 96, 168, 336];
    /** Books that still have no cover are looked at again once a month. */
    private const COVER_RECHECK_DAYS = 30;

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
        $onlySkipped = true; // all failures were sources paused by the rate limiter (no network problem)
        foreach ($sources as $name => $source) {
            if ($result !== [] && self::missingFields($result) === []) {
                break;
            }
            try {
                $data = $source($isbn13) ?? [];
            } catch (MetadataUnavailable $e) {
                $failures++;
                $onlySkipped = $onlySkipped && $e->status === -1;
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
                // status -1: only rate-limited sources are missing, the others answered
                throw new MetadataUnavailable('Metadata services unreachable', $onlySkipped ? -1 : 0);
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
        $clean = self::cleanMarc(...);

        $title = $clean($first('245', 'a'));
        if ($title === null) {
            return null;
        }
        // Volume of a multi-part work: $n number, $p title of the part
        // ("Die fünf Temponauten" $p "Treffpunkt 2321" -> "Die fünf Temponauten. Treffpunkt 2321").
        $number = $clean($first('245', 'n'));
        $part = $clean($first('245', 'p'));
        if ($number !== null) {
            $title .= '. ' . $number;
        }
        if ($part !== null) {
            $title .= ($number !== null ? ', ' : '. ') . $part;
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
            'publisher' => self::expandPublisher($clean($imprint['b'][0] ?? null)),
            'published_year' => $year,
            'pages' => $pages,
            'genre' => $genre,
            'language' => $language === null ? null : ($languages[$language] ?? $language),
            'cover_url' => null,
            'description' => null,
            'blurb_url' => $blurb,
        ];
    }

    /**
     * MARC values carry cataloguing conventions that don't belong in a title:
     * ISBD punctuation ("Impact :", "Berlin ;"), non-sorting markers around
     * articles, the spelled-out form of numbers for sorting ("Die große 5[Fünf]",
     * "Die 3 [drei] ???") and brackets around values added by the cataloguer.
     */
    public static function cleanMarc(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[\x{0088}\x{0089}\x{0098}\x{009C}]/u', '', $value) ?? $value;
        $value = preg_replace('/(\d)\s*\[[\p{L}\s-]+\]/u', '$1', $value) ?? $value;
        $value = preg_replace('/(\d)\s*\[[\p{L}\s-]*$/u', '$1', $value) ?? $value; // closing bracket in the next subfield
        $value = trim(rtrim(trim($value), ' /:;,.='));
        if (preg_match('/^\[([^\[\]]*)\]$/u', $value, $m)) {
            $value = trim($m[1]);
        } elseif (str_starts_with($value, '[') && !str_contains($value, ']')) {
            $value = ltrim($value, '[ ');
        } elseif (str_ends_with($value, ']') && !str_contains($value, '[')) {
            $value = rtrim($value, '] ');
        }
        // Placeholders such as "[...]" (volume number unknown) carry no information.
        return preg_match('/[\p{L}\p{N}]/u', $value) ? $value : null;
    }

    /** Older DNB records abbreviate publishers: "Bergverl. Rother" -> "Bergverlag Rother". */
    private static function expandPublisher(?string $publisher): ?string
    {
        if ($publisher === null) {
            return null;
        }
        return preg_replace('/([vV])erl\.?(?=[\s,;)]|$)/u', '$1erlag', $publisher) ?? $publisher;
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
        // The isbn: search is a full-text search: catalogues or books that merely mention the ISBN
        // come back as well (often first). Only a volume with this ISBN in its identifiers counts.
        $info = null;
        foreach ($json['items'] ?? [] as $item) {
            $identifiers = array_map(
                fn ($id) => Isbn::normalize((string) ($id['identifier'] ?? ''))[0],
                $item['volumeInfo']['industryIdentifiers'] ?? []
            );
            if (in_array($isbn, $identifiers, true)) {
                $info = $item['volumeInfo'];
                break;
            }
        }
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
            $changes = ['metadata_attempts' => $attempts, 'metadata_attempted_at' => $now];
            $schedule = self::schedule($book, $meta, $attempts);
            if ($schedule === null) {
                $changes['metadata_checked_at'] = $now;
                $changes['metadata_next_attempt_at'] = null;
            } else {
                $changes['metadata_next_attempt_at'] = $schedule;
                if (!empty($meta['partial'])) {
                    $partials++;
                }
            }
            if ($meta !== null) {
                $fill = self::fillChanges($book, $meta);
                if ($fill !== []) {
                    $updated++;
                    $changes += $fill + ['updated_at' => $now];
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

    /**
     * Catalogue changes that fill empty fields of $book from looked-up metadata. With
     * $withTitle the title is replaced too when it is still the placeholder (bulk scan).
     *
     * @param array<string, mixed> $book books row
     * @param array<string, mixed> $meta result of lookup()
     * @return array<string, mixed>
     */
    public static function fillChanges(array $book, array $meta, bool $withTitle = false): array
    {
        $changes = [];
        $limits = ['publisher' => 150, 'published_year' => null, 'genre' => 100, 'language' => 10, 'description' => 20000, 'cover_url' => 500];
        foreach ($limits as $field => $max) {
            if (empty($book[$field]) && !empty($meta[$field])) {
                $changes[$field] = is_string($meta[$field]) ? mb_substr($meta[$field], 0, $max ?? 100) : $meta[$field];
            }
        }
        if ((int) ($book['pages'] ?? 0) === 0 && !empty($meta['pages'])) {
            $changes['pages'] = (int) $meta['pages'];
        }
        if (trim((string) ($book['authors'] ?? '')) === '' && !empty($meta['authors'])) {
            $changes['authors'] = mb_substr((string) $meta['authors'], 0, 255);
        }
        $placeholder = trim((string) ($book['title'] ?? ''));
        if ($withTitle && !empty($meta['title']) && ($placeholder === '' || $placeholder === (string) ($book['isbn13'] ?? ''))) {
            $changes['title'] = mb_substr((string) $meta['title'], 0, 500);
        }
        if (empty($book['isbn10']) && !empty($meta['isbn10'])) {
            $changes['isbn10'] = $meta['isbn10'];
        }
        if (isset($changes['cover_url'])) {
            // New cover source: let CoverService download it (again).
            $changes['cover_status'] = null;
            $changes['cover_checked_at'] = null;
        }
        return $changes;
    }

    /**
     * Next lookup of a book after this attempt, or null when its data counts as final:
     * - nothing found by any source -> final
     * - a source failed (partial) -> again after 6 h, 12 h, 1 d … until MAX_ATTEMPTS
     * - complete but still without cover -> again in a month (sources add covers over time)
     *
     * @param array<string, mixed>      $book
     * @param array<string, mixed>|null $meta
     */
    public static function schedule(array $book, ?array $meta, int $attempts): ?string
    {
        if ($meta === null) {
            return null;
        }
        $hasCover = !empty($book['cover_url']) || !empty($book['cover_file']) || !empty($meta['cover_url']);
        if (!empty($meta['partial']) && $attempts < self::MAX_ATTEMPTS) {
            $hours = self::RETRY_HOURS[min($attempts - 1, count(self::RETRY_HOURS) - 1)];
            return App::now()->modify("+$hours hours")->format('Y-m-d H:i:s');
        }
        if (!$hasCover) {
            return App::now()->modify('+' . self::COVER_RECHECK_DAYS . ' days')->format('Y-m-d H:i:s');
        }
        return null;
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
            // Bulk scan placeholders (metadata_status set) belong to the metadata queue.
            'metadata_checked_at IS NULL AND metadata_status IS NULL AND isbn13 IS NOT NULL AND (metadata_next_attempt_at IS NULL OR metadata_next_attempt_at <= :now)',
            ['now' => App::nowString()],
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
