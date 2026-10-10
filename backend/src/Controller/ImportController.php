<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\Import\BookImporter;
use BookSpot\Import\CsvReader;
use BookSpot\Request;

final class ImportController extends Controller
{
    /**
     * multipart/form-data:
     *   file                CSV export (Bookstats, Goodreads or generic)
     *   dry_run             1 = only analyse, nothing is saved
     *   create_shelves      1 = create a shelf for unknown location codes (L2, M4, …)
     *   shelf_name          name of that shelf
     *   overwrite_personal  0 = keep existing personal data (default 1)
     *   overwrite_location  1 = move existing books to the CSV location
     */
    public function import(Request $request): array
    {
        $user = $this->user($request);
        if ($request->method !== 'POST') {
            throw new ApiException('method_not_allowed', 405);
        }
        $file = $request->files['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $code = is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
            throw new ApiException($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'csv_too_large' : 'upload_failed', 422);
        }
        @set_time_limit(300);

        $flag = fn (string $key, bool $default) => in_array((string) $request->input($key, $default ? '1' : '0'), ['1', 'true', 'on'], true);

        $reader = new CsvReader();
        $rows = $reader->parseFile((string) $file['tmp_name']);
        $importer = new BookImporter($this->db(), $user['id'], [
            'dry_run' => $flag('dry_run', false),
            'create_shelves' => $flag('create_shelves', false),
            'shelf_name' => (string) $request->input('shelf_name', ''),
            'overwrite_personal' => $flag('overwrite_personal', true),
            'overwrite_location' => $flag('overwrite_location', false),
        ]);
        $summary = $importer->import($rows);
        $summary['detected_columns'] = array_keys($reader->mapping);
        $summary['delimiter'] = $reader->delimiter === "\t" ? 'tab' : $reader->delimiter;
        return $summary;
    }
}
