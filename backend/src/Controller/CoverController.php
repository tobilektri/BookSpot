<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\CoverService;
use BookSpot\Http\Response;
use BookSpot\Request;

/**
 * Cover images. The browser never loads covers from other hosts (CSP img-src 'self');
 * 404 when there is no cover – the app then shows its generated placeholder.
 */
final class CoverController extends Controller
{
    /** Locally stored cover (downloaded on first request, see CoverService); ?v= busts caches */
    public function show(Request $request): Response
    {
        $this->user($request);
        $id = $request->paramInt('id') ?? $request->queryInt('id');
        $cover = $id === null ? null : (new CoverService($this->db()))->ensure($id);
        if ($cover === null) {
            return Response::empty(404, ['Cache-Control' => 'private, max-age=3600']);
        }
        $etag = '"' . md5($cover['path'] . filemtime($cover['path']) . filesize($cover['path'])) . '"';
        $headers = [
            'Content-Type' => $cover['mime'],
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => $etag,
        ];
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return Response::empty(304, $headers);
        }
        $path = $cover['path'];
        return new Response(200, $headers + ['Content-Length' => (string) filesize($path)], static function () use ($path): void {
            readfile($path);
        });
    }

    /** ?url=<cover url> | ?isbn=<isbn13>: preview for the book form – fetched by the server, not stored */
    public function preview(Request $request): Response
    {
        $this->user($request);
        $preview = (new CoverService($this->db()))->preview(
            is_string($request->query('url')) ? $request->query('url') : null,
            is_string($request->query('isbn')) ? $request->query('isbn') : null,
        );
        if ($preview === null) {
            return Response::empty(404);
        }
        return new Response(200, [
            'Content-Type' => $preview['mime'],
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'",
        ], $preview['body']);
    }

    /** cover.php?id= | cover.php?preview=1&url=|isbn= */
    public function legacy(Request $request): Response
    {
        return $request->query('preview') !== null ? $this->preview($request) : $this->show($request);
    }
}
