<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Exports the catalogue plus the user's personal data as a Bookstats-compatible
 * CSV (semicolon separated, UTF-8 with BOM), which can be re-imported.
 */
final class ExportService
{
    private const HEADER = [
        'Titel', 'Autor(en)', 'ISBN', 'ASIN', 'Erscheinungsjahr', 'Genre', 'Seitenanzahl', 'Buchart', 'Preis',
        'Erhalten als', 'Lesestatus', 'Lesebeginn', 'Leseende', 'Bewertung', 'Notizen', 'Erhalten am',
        'Standort', 'Verlag', 'Sprache', 'Beschreibung', 'Cover',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param resource $out */
    public function writeCsv(int $userId, $out): int
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, ';', '"', '');
        $rows = $this->db->all(
            "SELECT b.*, ub.reading_status, ub.rating, ub.started_at, ub.finished_at, ub.notes
             FROM books b LEFT JOIN user_books ub ON ub.book_id = b.id AND ub.user_id = ?
             WHERE b.status <> 'discarded' ORDER BY b.title",
            [$userId]
        );
        $index = [];
        foreach ($this->db->all('SELECT id, columns_config FROM shelves') as $shelf) {
            $index[(int) $shelf['id']] = ShelfService::decodeColumns($shelf['columns_config']);
        }
        $status = ['read' => 'Gelesen', 'reading' => 'Lese gerade', 'abandoned' => 'Abgebrochen', 'unread' => 'Ungelesen'];
        $via = ['owned' => 'Kauf', 'library_loan' => 'Bibliothek', 'personal_loan' => 'Geliehen'];
        $date = fn (?string $d) => $d ? (new \DateTimeImmutable($d))->format('d.m.Y') : '';

        foreach ($rows as $row) {
            $location = '';
            if ($row['shelf_id'] !== null && $row['shelf_col'] !== null) {
                $location = $index[(int) $row['shelf_id']][(int) $row['shelf_col'] - 1]['rows'][(int) $row['shelf_row'] - 1] ?? '';
            }
            fputcsv($out, array_map(self::safeCell(...), [
                $row['title'],
                $row['authors'],
                $row['isbn13'] ?? '',
                $row['asin'] ?? $row['isbn10'] ?? '',
                $row['published_year'] ?? '',
                $row['genre'] ?? '',
                (string) $row['pages'],
                $row['format'] ?? '',
                $row['price'] === null ? '' : number_format((float) $row['price'], 2, ',', ''),
                $via[$row['entry_type']] ?? 'Kauf',
                $status[$row['reading_status'] ?? 'unread'] ?? 'Ungelesen',
                $date($row['started_at']),
                $date($row['finished_at']),
                (string) ($row['rating'] ?? 0),
                $row['notes'] ?? '',
                $date($row['acquired_at']),
                $location,
                $row['publisher'] ?? '',
                $row['language'] ?? '',
                $row['description'] ?? '',
                $row['cover_url'] ?? '',
            ]), ';', '"', '');
        }
        return count($rows);
    }

    /** Prevents CSV/formula injection when the export is opened in a spreadsheet. */
    private static function safeCell(mixed $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}
