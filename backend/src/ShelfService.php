<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Shelves ("Regale") consist of columns ("Spalten"), each with its own number
 * of compartments ("Fächer"). The layout is stored as JSON in
 * shelves.columns_config:
 *
 *   [{"label": "L", "rows": ["L1", "L2", "L3"]}, {"label": "M", "rows": ["M1", "M2"]}]
 *
 * Books reference a compartment via (shelf_id, shelf_col, shelf_row), both 1-based.
 * Compartment names are what the CSV import matches against (e.g. "L2").
 */
final class ShelfService
{
    public const MAX_COLUMNS = 40;
    public const MAX_ROWS = 60;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $shelves = $this->db->all('SELECT * FROM shelves ORDER BY sort_order, name');
        $counts = [];
        foreach ($this->db->all(
            "SELECT shelf_id, shelf_col, shelf_row, COUNT(*) AS n FROM books
             WHERE shelf_id IS NOT NULL AND status = 'active' GROUP BY shelf_id, shelf_col, shelf_row"
        ) as $row) {
            $counts[(int) $row['shelf_id']][(int) $row['shelf_col'] . ':' . (int) $row['shelf_row']] = (int) $row['n'];
        }
        return array_map(fn ($s) => $this->present($s, $counts[(int) $s['id']] ?? []), $shelves);
    }

    /** @return array<string, mixed> */
    public function get(int $id, int $userId): array
    {
        $shelf = $this->db->one('SELECT * FROM shelves WHERE id = ?', [$id]) ?? throw ApiException::notFound('shelf_not_found');
        $books = $this->db->all(
            "SELECT b.id, b.title, b.authors, b.isbn13, b.cover_url, b.pages, b.genre, b.format, b.entry_type,
                    b.lent_to, b.shelf_col, b.shelf_row, COALESCE(ub.reading_status, 'unread') AS reading_status
             FROM books b
             LEFT JOIN user_books ub ON ub.book_id = b.id AND ub.user_id = ?
             WHERE b.shelf_id = ? AND b.status = 'active'
             ORDER BY b.shelf_col, b.shelf_row, b.authors, b.title",
            [$userId, $id]
        );
        $counts = [];
        foreach ($books as &$book) {
            $book['id'] = (int) $book['id'];
            $book['pages'] = (int) $book['pages'];
            $book['shelf_col'] = $book['shelf_col'] === null ? null : (int) $book['shelf_col'];
            $book['shelf_row'] = $book['shelf_row'] === null ? null : (int) $book['shelf_row'];
            $key = $book['shelf_col'] . ':' . $book['shelf_row'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        unset($book);
        return $this->present($shelf, $counts) + ['books' => $books];
    }

    /** @return array<string, mixed> */
    public function create(array $input): array
    {
        $data = $this->validate($input);
        $now = App::nowString();
        $id = $this->db->insert('shelves', $data + [
            'sort_order' => (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shelves'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->present($this->db->one('SELECT * FROM shelves WHERE id = ?', [$id]) ?? [], []);
    }

    /**
     * Updates name/layout. Books in compartments that no longer exist lose their
     * position (they show up as "ohne Standort" again); their number is returned.
     *
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $existing = $this->db->one('SELECT * FROM shelves WHERE id = ?', [$id]) ?? throw ApiException::notFound('shelf_not_found');
        $data = $this->validate($input + [
            'name' => $existing['name'],
            'room' => $existing['room'],
            'description' => $existing['description'],
            'columns' => self::decodeColumns($existing['columns_config']),
        ]);
        if (isset($input['sort_order']) && is_numeric($input['sort_order'])) {
            $data['sort_order'] = (int) $input['sort_order'];
        }
        $data['updated_at'] = App::nowString();

        return $this->db->transaction(function () use ($id, $data) {
            $this->db->update('shelves', $data, ['id' => $id]);
            $unassigned = $this->detachOrphans($id, self::decodeColumns($data['columns_config']));
            $shelf = $this->db->one('SELECT * FROM shelves WHERE id = ?', [$id]) ?? [];
            return $this->present($shelf, []) + ['unassigned_books' => $unassigned];
        });
    }

    public function delete(int $id): int
    {
        return $this->db->transaction(function () use ($id) {
            $this->db->one('SELECT id FROM shelves WHERE id = ?', [$id]) ?? throw ApiException::notFound('shelf_not_found');
            $moved = $this->db->run(
                'UPDATE books SET shelf_id = NULL, shelf_col = NULL, shelf_row = NULL, updated_at = ? WHERE shelf_id = ?',
                [App::nowString(), $id]
            )->rowCount();
            $this->db->run('DELETE FROM shelves WHERE id = ?', [$id]);
            return $moved;
        });
    }

    /**
     * Checks that a (shelf, col, row) triple exists. Returns the normalised triple
     * or nulls when no shelf is given.
     *
     * @return array{shelf_id: ?int, shelf_col: ?int, shelf_row: ?int}
     */
    public function resolvePosition(mixed $shelfId, mixed $col, mixed $row): array
    {
        if ($shelfId === null || $shelfId === '' || $shelfId === 0 || $shelfId === '0') {
            return ['shelf_id' => null, 'shelf_col' => null, 'shelf_row' => null];
        }
        $shelf = $this->db->one('SELECT * FROM shelves WHERE id = ?', [(int) $shelfId]);
        if ($shelf === null) {
            throw ApiException::validation(['shelf_id' => 'shelf_not_found']);
        }
        $columns = self::decodeColumns($shelf['columns_config']);
        $col = is_numeric($col) ? (int) $col : null;
        $row = is_numeric($row) ? (int) $row : null;
        if ($col === null || $row === null) {
            // Shelf without exact compartment is allowed (e.g. "somewhere in the hallway shelf").
            return ['shelf_id' => (int) $shelfId, 'shelf_col' => null, 'shelf_row' => null];
        }
        if (!isset($columns[$col - 1]) || $row < 1 || $row > count($columns[$col - 1]['rows'])) {
            throw ApiException::validation(['shelf_row' => 'compartment_not_found']);
        }
        return ['shelf_id' => (int) $shelfId, 'shelf_col' => $col, 'shelf_row' => $row];
    }

    /**
     * Lookup table of compartment names (case-insensitive) -> position.
     * Also indexes "Shelf name/Compartment" to disambiguate duplicates.
     *
     * @return array<string, array{shelf_id: int, shelf_col: int, shelf_row: int, label: string}>
     */
    public function compartmentIndex(): array
    {
        $index = [];
        foreach ($this->db->all('SELECT id, name, columns_config FROM shelves ORDER BY sort_order, id') as $shelf) {
            foreach (self::decodeColumns($shelf['columns_config']) as $c => $column) {
                foreach ($column['rows'] as $r => $name) {
                    $position = ['shelf_id' => (int) $shelf['id'], 'shelf_col' => $c + 1, 'shelf_row' => $r + 1, 'label' => $shelf['name'] . ' › ' . $name];
                    $index[self::key($shelf['name'] . '/' . $name)] ??= $position;
                    if ($name !== '') {
                        $index[self::key($name)] ??= $position;
                    }
                }
            }
        }
        return $index;
    }

    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $name) ?? $name);
    }

    /**
     * Builds a shelf layout from location codes like "L2", "M4", "R10":
     * every letter prefix becomes a column, numbered compartments become rows.
     *
     * @param list<string> $codes
     * @return list<array{label: string, rows: list<string>}>
     */
    public static function layoutFromCodes(array $codes): array
    {
        $columns = [];
        foreach ($codes as $code) {
            if (preg_match('/^\s*(\p{L}+)\s*[-_ ]?\s*(\d{1,2})\s*$/u', $code, $m)) {
                $label = mb_strtoupper($m[1]);
                $columns[$label] = max($columns[$label] ?? 0, (int) $m[2]);
            }
        }
        ksort($columns, SORT_NATURAL);
        $layout = [];
        foreach ($columns as $label => $max) {
            $max = min($max, self::MAX_ROWS);
            $layout[] = ['label' => (string) $label, 'rows' => array_map(fn ($n) => $label . $n, range(1, $max))];
        }
        return array_slice($layout, 0, self::MAX_COLUMNS);
    }

    /** @return list<array{label: string, rows: list<string>}> */
    public static function decodeColumns(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : $json;
        return is_array($data) ? self::normalizeColumns($data) : [];
    }

    /**
     * Accepts the documented object format as well as the compact form from the
     * spec ([4, 2, 5] = three columns with 4, 2 and 5 compartments).
     *
     * @return list<array{label: string, rows: list<string>}>
     */
    public static function normalizeColumns(array $columns): array
    {
        $result = [];
        foreach (array_values($columns) as $i => $column) {
            $defaultLabel = self::columnLabel($i);
            if (is_int($column) || (is_string($column) && ctype_digit($column))) {
                $column = ['label' => $defaultLabel, 'rows' => (int) $column];
            }
            if (!is_array($column)) {
                continue;
            }
            $label = trim(mb_substr((string) ($column['label'] ?? $defaultLabel), 0, 30));
            $rows = $column['rows'] ?? 1;
            if (is_int($rows) || (is_string($rows) && ctype_digit($rows))) {
                $rows = array_fill(0, max(1, (int) $rows), '');
            }
            if (!is_array($rows)) {
                $rows = [''];
            }
            $names = [];
            foreach (array_values($rows) as $r => $name) {
                $name = is_array($name) ? ($name['name'] ?? '') : $name;
                $name = trim(mb_substr((string) $name, 0, 30));
                $names[] = $name === '' ? ($label === '' ? $defaultLabel : $label) . ($r + 1) : $name;
            }
            $result[] = ['label' => $label === '' ? $defaultLabel : $label, 'rows' => $names === [] ? [$label . '1'] : $names];
        }
        return $result;
    }

    private static function columnLabel(int $index): string
    {
        $label = '';
        $index++;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $label = chr(65 + $mod) . $label;
            $index = intdiv($index - 1, 26);
        }
        return $label;
    }

    /** @return array<string, mixed> */
    private function validate(array $input): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = 'required';
        }
        $columns = self::normalizeColumns(is_array($input['columns'] ?? null) ? $input['columns'] : []);
        if ($columns === []) {
            $errors['columns'] = 'at_least_one_column';
        } elseif (count($columns) > self::MAX_COLUMNS) {
            $errors['columns'] = 'too_many_columns';
        } else {
            $seen = [];
            foreach ($columns as $column) {
                if (count($column['rows']) > self::MAX_ROWS) {
                    $errors['columns'] = 'too_many_rows';
                }
                foreach ($column['rows'] as $rowName) {
                    $key = self::key($rowName);
                    if (isset($seen[$key])) {
                        $errors['columns'] = 'duplicate_compartment_name';
                        $errors['duplicate'] = $rowName;
                    }
                    $seen[$key] = true;
                }
            }
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $room = trim((string) ($input['room'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        return [
            'name' => $name,
            'room' => $room === '' ? null : mb_substr($room, 0, 100),
            'description' => $description === '' ? null : $description,
            'columns_config' => json_encode($columns, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @param list<array{label: string, rows: list<string>}> $columns */
    private function detachOrphans(int $shelfId, array $columns): int
    {
        $books = $this->db->all('SELECT id, shelf_col, shelf_row FROM books WHERE shelf_id = ? AND shelf_col IS NOT NULL', [$shelfId]);
        $orphans = [];
        foreach ($books as $book) {
            $col = (int) $book['shelf_col'];
            $row = (int) $book['shelf_row'];
            if (!isset($columns[$col - 1]) || $row < 1 || $row > count($columns[$col - 1]['rows'])) {
                $orphans[] = (int) $book['id'];
            }
        }
        foreach ($orphans as $bookId) {
            $this->db->run(
                'UPDATE books SET shelf_id = NULL, shelf_col = NULL, shelf_row = NULL, updated_at = ? WHERE id = ?',
                [App::nowString(), $bookId]
            );
        }
        return count($orphans);
    }

    /**
     * @param array<string, int> $counts "col:row" => number of books
     * @return array<string, mixed>
     */
    private function present(array $shelf, array $counts): array
    {
        $columns = self::decodeColumns($shelf['columns_config'] ?? '[]');
        $total = 0;
        foreach ($columns as $c => &$column) {
            $column['counts'] = [];
            foreach ($column['rows'] as $r => $_) {
                $n = $counts[($c + 1) . ':' . ($r + 1)] ?? 0;
                $column['counts'][] = $n;
                $total += $n;
            }
        }
        unset($column);
        $loose = $counts[':'] ?? 0;
        return [
            'id' => (int) $shelf['id'],
            'name' => $shelf['name'],
            'room' => $shelf['room'],
            'description' => $shelf['description'],
            'sort_order' => (int) $shelf['sort_order'],
            'columns' => $columns,
            'book_count' => $total + $loose,
            'unplaced_count' => $loose,
            'compartment_count' => array_sum(array_map(fn ($c) => count($c['rows']), $columns)),
        ];
    }
}
