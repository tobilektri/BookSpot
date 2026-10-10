<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\App;
use BookSpot\ExportService;
use BookSpot\Http\Response;
use BookSpot\Request;

final class ExportController extends Controller
{
    /** Bookstats-compatible CSV download (catalogue + own reading data) */
    public function export(Request $request): Response
    {
        $user = $this->user($request);
        $db = $this->db();
        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="bookspot-' . App::today() . '.csv"',
            'Cache-Control' => 'no-store',
        ], static function () use ($db, $user): void {
            try {
                (new ExportService($db))->writeCsv($user['id'], fopen('php://output', 'w'));
            } catch (\Throwable $e) {
                error_log('[BookSpot] export failed: ' . $e->getMessage());
                echo "\nExport failed";
            }
        });
    }
}
