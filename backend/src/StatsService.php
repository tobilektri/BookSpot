<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Personal reading statistics for one user (Bookstats-style dashboard).
 */
final class StatsService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed> */
    public function forUser(int $userId, ?int $year = null): array
    {
        $year ??= (int) App::now()->format('Y');
        // A book counts for the user when it is in the active catalogue, or when
        // the user has history with it (e.g. a returned library loan they read).
        $scope = "FROM books b LEFT JOIN user_books ub ON ub.book_id = b.id AND ub.user_id = :uid
                  WHERE (b.status = 'active' OR (ub.reading_status IS NOT NULL AND ub.reading_status <> 'unread'))";
        $status = "COALESCE(ub.reading_status, 'unread')";
        $pagesRead = "CASE WHEN ub.reading_status = 'read' AND ub.pages_read < b.pages THEN b.pages ELSE COALESCE(ub.pages_read, 0) END";

        $counts = ['unread' => 0, 'reading' => 0, 'read' => 0, 'abandoned' => 0];
        foreach ($this->db->all("SELECT $status AS s, COUNT(*) AS n $scope GROUP BY $status", ['uid' => $userId]) as $row) {
            $counts[$row['s']] = (int) $row['n'];
        }

        $totals = $this->db->one(
            "SELECT COUNT(*) AS books, COALESCE(SUM(b.pages), 0) AS pages, COALESCE(SUM($pagesRead), 0) AS pages_read,
                    AVG(ub.rating) AS avg_rating, COUNT(ub.rating) AS rated,
                    SUM(CASE WHEN ub.reading_status = 'read' AND ub.finished_at IS NULL THEN 1 ELSE 0 END) AS read_without_date,
                    SUM(CASE WHEN b.status = 'active' AND b.entry_type = 'owned' AND b.shelf_id IS NULL THEN 1 ELSE 0 END) AS unplaced,
                    COALESCE(SUM(CASE WHEN b.status = 'active' AND b.entry_type = 'owned' THEN b.price ELSE 0 END), 0) AS value
             $scope",
            ['uid' => $userId]
        ) ?? [];

        $genreRows = $this->db->all(
            "SELECT COALESCE(NULLIF(b.genre, ''), '') AS genre, COUNT(*) AS total,
                    SUM(CASE WHEN ub.reading_status = 'read' THEN 1 ELSE 0 END) AS read_count,
                    SUM(CASE WHEN ub.reading_status = 'reading' THEN 1 ELSE 0 END) AS reading_count,
                    COALESCE(SUM($pagesRead), 0) AS pages_read
             $scope GROUP BY COALESCE(NULLIF(b.genre, ''), '') ORDER BY total DESC",
            ['uid' => $userId]
        );
        $genres = array_map(fn ($r) => [
            'genre' => $r['genre'] === '' ? null : $r['genre'],
            'total' => (int) $r['total'],
            'read' => (int) $r['read_count'],
            'reading' => (int) $r['reading_count'],
            'pages_read' => (int) $r['pages_read'],
        ], $genreRows);

        // Finished books per month of the selected year and per year overall.
        $finished = $this->db->all(
            "SELECT SUBSTR(ub.finished_at, 1, 7) AS ym, COUNT(*) AS books, COALESCE(SUM(CASE WHEN ub.pages_read > b.pages THEN ub.pages_read ELSE b.pages END), 0) AS pages
             FROM user_books ub JOIN books b ON b.id = ub.book_id
             WHERE ub.user_id = :uid AND ub.reading_status = 'read' AND ub.finished_at IS NOT NULL
             GROUP BY SUBSTR(ub.finished_at, 1, 7) ORDER BY ym",
            ['uid' => $userId]
        );
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[sprintf('%04d-%02d', $year, $m)] = ['month' => sprintf('%04d-%02d', $year, $m), 'books' => 0, 'pages' => 0];
        }
        $years = [];
        foreach ($finished as $row) {
            $ym = (string) $row['ym'];
            $y = (int) substr($ym, 0, 4);
            if (isset($months[$ym])) {
                $months[$ym]['books'] = (int) $row['books'];
                $months[$ym]['pages'] = (int) $row['pages'];
            }
            $years[$y] ??= ['year' => $y, 'books' => 0, 'pages' => 0];
            $years[$y]['books'] += (int) $row['books'];
            $years[$y]['pages'] += (int) $row['pages'];
        }
        ksort($years);

        // Reading pace from books with start and end date.
        $pace = $this->db->all(
            "SELECT ub.started_at, ub.finished_at, b.pages FROM user_books ub JOIN books b ON b.id = ub.book_id
             WHERE ub.user_id = :uid AND ub.reading_status = 'read' AND ub.started_at IS NOT NULL AND ub.finished_at IS NOT NULL",
            ['uid' => $userId]
        );
        $days = 0;
        $pacePages = 0;
        foreach ($pace as $row) {
            $d = (int) (new \DateTimeImmutable((string) $row['started_at']))->diff(new \DateTimeImmutable((string) $row['finished_at']))->format('%a') + 1;
            $days += $d;
            $pacePages += (int) $row['pages'];
        }

        $authors = array_map(fn ($r) => ['authors' => $r['authors'], 'total' => (int) $r['total'], 'read' => (int) $r['read_count']], $this->db->all(
            "SELECT b.authors, COUNT(*) AS total, SUM(CASE WHEN ub.reading_status = 'read' THEN 1 ELSE 0 END) AS read_count
             $scope AND b.authors <> '' GROUP BY b.authors ORDER BY total DESC, b.authors LIMIT 10",
            ['uid' => $userId]
        ));

        $yearRow = $years[$year] ?? ['books' => 0, 'pages' => 0];
        $availableYears = array_values(array_unique(array_merge(array_keys($years), [(int) App::now()->format('Y'), $year])));
        rsort($availableYears);
        $dayOfYear = $year === (int) App::now()->format('Y') ? (int) App::now()->format('z') + 1 : 365;

        return [
            'year' => $year,
            'status' => $counts,
            'totals' => [
                'books' => (int) ($totals['books'] ?? 0),
                'pages' => (int) ($totals['pages'] ?? 0),
                'pages_read' => (int) ($totals['pages_read'] ?? 0),
                'avg_rating' => $totals['avg_rating'] === null ? null : round((float) $totals['avg_rating'], 2),
                'rated' => (int) ($totals['rated'] ?? 0),
                'read_without_date' => (int) ($totals['read_without_date'] ?? 0),
                'unplaced' => (int) ($totals['unplaced'] ?? 0),
                'value' => round((float) ($totals['value'] ?? 0), 2),
            ],
            'genres' => $genres,
            'months' => array_values($months),
            'years' => array_values($years),
            'available_years' => $availableYears,
            'pace' => [
                'books_with_dates' => count($pace),
                'avg_days_per_book' => count($pace) > 0 ? round($days / count($pace), 1) : null,
                'pages_per_day' => $days > 0 ? round($pacePages / $days, 1) : null,
                'books_this_year' => $yearRow['books'],
                'pages_this_year' => $yearRow['pages'],
                'pages_per_day_this_year' => round($yearRow['pages'] / max(1, $dayOfYear), 1),
            ],
            'top_authors' => $authors,
            'loans' => [
                'library_active' => (int) $this->db->value("SELECT COUNT(*) FROM books WHERE status = 'active' AND entry_type = 'library_loan'"),
                'personal_active' => (int) $this->db->value("SELECT COUNT(*) FROM books WHERE status = 'active' AND entry_type = 'personal_loan'"),
                'lent_out' => (int) $this->db->value("SELECT COUNT(*) FROM books WHERE status = 'active' AND lent_to IS NOT NULL AND lent_to <> ''"),
            ],
        ];
    }
}
