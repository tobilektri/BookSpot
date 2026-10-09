<?php

declare(strict_types=1);

namespace BookSpot\Import;

use BookSpot\ApiException;

/**
 * Robust CSV reader: strips the UTF-8 BOM, converts Windows-1252/ISO-8859-1
 * exports to UTF-8, auto-detects the delimiter (; , tab |) and maps header
 * names to canonical field names via an alias table (Bookstats, Goodreads,
 * generic German/English exports).
 */
final class CsvReader
{
    /** canonical field => normalised header aliases */
    public const ALIASES = [
        'title' => ['titel', 'title', 'buchtitel', 'name'],
        'authors' => ['autoren', 'autoren(en)', 'autor(en)', 'autor', 'autorin', 'author', 'authors', 'verfasser'],
        'isbn' => ['isbn', 'isbn13', 'ean', 'isbn/ean', 'ean13'],
        'isbn10' => ['isbn10'],
        'asin' => ['asin'],
        'publisher' => ['verlag', 'publisher'],
        'published_year' => ['erscheinungsjahr', 'jahr', 'year', 'yearpublished', 'originalpublicationyear', 'publicationyear', 'erscheinungsdatum'],
        'genre' => ['genre', 'genres'],
        'category' => ['kategorie', 'category'],
        'pages' => ['seitenanzahl', 'seiten', 'pages', 'numberofpages', 'pagecount', 'seitenzahl'],
        'format' => ['buchart', 'format', 'binding', 'einband', 'ausgabe'],
        'price' => ['preis', 'price', 'kaufpreis'],
        'acquired_via' => ['erhaltenals', 'herkunft', 'acquiredas', 'source'],
        'acquired_at' => ['erhaltenam', 'gekauftam', 'kaufdatum', 'dateadded', 'hinzugefügtam', 'dateacquired'],
        'reading_status' => ['lesestatus', 'status', 'readingstatus', 'exclusiveshelf', 'gelesen'],
        'started_at' => ['lesebeginn', 'startdatum', 'begonnen', 'datestarted', 'startedat', 'startdate'],
        'finished_at' => ['leseende', 'enddatum', 'beendet', 'dateread', 'finishedat', 'enddate', 'gelesenam'],
        'rating' => ['bewertung', 'rating', 'myrating', 'sterne', 'stars'],
        'notes' => ['notizen', 'notiz', 'notes', 'privatenotes', 'mynotes', 'kommentar'],
        'location' => ['standort', 'location', 'regal', 'fach', 'platz', 'regalplatz', 'shelf'],
        'description' => ['beschreibung', 'description', 'klappentext'],
        'cover_url' => ['cover', 'coverurl', 'bild', 'imageurl', 'image'],
        'language' => ['sprache', 'language'],
    ];

    public const MAX_BYTES = 15 * 1024 * 1024;

    /** @var list<string> */
    public array $headers = [];
    /** @var array<string, int> canonical field => column index */
    public array $mapping = [];
    public string $delimiter = ';';

    /** @return list<array<string, string>> rows keyed by canonical field (+ "_row" line number) */
    public function parseFile(string $path): array
    {
        if (!is_file($path) || filesize($path) === 0) {
            throw new ApiException('csv_empty', 422);
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new ApiException('csv_too_large', 413);
        }
        return $this->parseString((string) file_get_contents($path));
    }

    /** @return list<array<string, string>> */
    public function parseString(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (trim($content) === '') {
            throw new ApiException('csv_empty', 422);
        }

        $firstLine = strtok($content, "\n") ?: '';
        $this->delimiter = self::detectDelimiter($firstLine);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, null, $this->delimiter, '"', '');
        if ($header === false || $header === [null]) {
            throw new ApiException('csv_empty', 422);
        }
        $this->headers = array_map(fn ($h) => trim((string) $h), $header);
        $this->mapping = self::mapHeaders($this->headers);
        if (!isset($this->mapping['title'])) {
            fclose($stream);
            throw new ApiException('csv_missing_title_column', 422, ['headers' => $this->headers]);
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, null, $this->delimiter, '"', '')) !== false) {
            $line++;
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }
            $row = ['_row' => (string) $line];
            foreach ($this->mapping as $field => $index) {
                $row[$field] = self::cleanCell((string) ($cells[$index] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    public static function detectDelimiter(string $line): string
    {
        $best = ';';
        $bestCount = -1;
        foreach ([';', ',', "\t", '|'] as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }
        return $best;
    }

    /**
     * @param list<string> $headers
     * @return array<string, int>
     */
    public static function mapHeaders(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $index => $header) {
            $normalised = self::normaliseHeader($header);
            foreach (self::ALIASES as $field => $aliases) {
                if (!isset($mapping[$field]) && in_array($normalised, array_map(self::normaliseHeader(...), $aliases), true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }
        return $mapping;
    }

    public static function normaliseHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        return preg_replace('/[\s_\-.:]+/u', '', $header) ?? $header;
    }

    private static function cleanCell(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        // Goodreads/Excel style ="9783..." wrappers.
        if (preg_match('/^="(.*)"$/s', $value, $m)) {
            $value = $m[1];
        }
        return trim($value);
    }
}
