<?php

declare(strict_types=1);

require __DIR__ . '/../../backend/bootstrap.php';

use BookSpot\Api;
use BookSpot\App;
use BookSpot\ApiException;
use BookSpot\Auth;
use BookSpot\Import\BookImporter;
use BookSpot\Import\CsvReader;
use BookSpot\Request;

/*
 * POST import.php (multipart/form-data)
 *   file                CSV export (Bookstats, Goodreads or generic)
 *   dry_run             1 = only analyse, nothing is saved
 *   create_shelves      1 = create a shelf for unknown location codes (L2, M4, …)
 *   shelf_name          name of that shelf
 *   overwrite_personal  0 = keep existing personal data (default 1)
 *   overwrite_location  1 = move existing books to the CSV location
 */
Api::run(function (Request $req) {
    $user = Auth::requireUser();
    if ($req->method !== 'POST') {
        Api::methodNotAllowed();
    }
    $file = $req->files['file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        $code = is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
        throw new ApiException($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'csv_too_large' : 'upload_failed', 422);
    }
    @set_time_limit(300);

    $flag = fn (string $key, bool $default) => in_array((string) $req->input($key, $default ? '1' : '0'), ['1', 'true', 'on'], true);

    $reader = new CsvReader();
    $rows = $reader->parseFile((string) $file['tmp_name']);
    $importer = new BookImporter(App::db(), $user['id'], [
        'dry_run' => $flag('dry_run', false),
        'create_shelves' => $flag('create_shelves', false),
        'shelf_name' => (string) $req->input('shelf_name', ''),
        'overwrite_personal' => $flag('overwrite_personal', true),
        'overwrite_location' => $flag('overwrite_location', false),
    ]);
    $summary = $importer->import($rows);
    $summary['detected_columns'] = array_keys($reader->mapping);
    $summary['delimiter'] = $reader->delimiter === "\t" ? 'tab' : $reader->delimiter;
    return $summary;
});
