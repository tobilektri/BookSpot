<?php

declare(strict_types=1);

namespace BookSpot;

final class Dates
{
    /**
     * Parses user/CSV supplied dates into Y-m-d.
     * Accepts 2026-10-09, 09.10.2026, 9.10.26, 2026/10/09 and ISO timestamps.
     *
     * @return string|null|false null for empty input, false for unparseable input
     */
    public static function parse(mixed $value): string|null|false
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '' || $value === '0' || $value === '00.00.0000' || $value === '0000-00-00') {
            return null;
        }
        $patterns = [
            '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/' => [1, 2, 3],
            '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/' => [1, 2, 3],
            '/^(\d{1,2})\.(\d{1,2})\.(\d{4})(?: .*)?$/' => [3, 2, 1],
            '/^(\d{1,2})\.(\d{1,2})\.(\d{2})$/' => [3, 2, 1],
        ];
        foreach ($patterns as $regex => [$y, $m, $d]) {
            if (preg_match($regex, $value, $match)) {
                $year = (int) $match[$y];
                if ($year < 100) {
                    $year += $year > 70 ? 1900 : 2000;
                }
                $month = (int) $match[$m];
                $day = (int) $match[$d];
                if (!checkdate($month, $day, $year)) {
                    return false;
                }
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return false;
    }
}
