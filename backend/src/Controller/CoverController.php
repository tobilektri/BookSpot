<?php

declare(strict_types=1);

namespace BookSpot\Controller;

use BookSpot\ApiException;
use BookSpot\BookService;
use BookSpot\CoverService;
use BookSpot\Http\Response;
use BookSpot\Request;
use BookSpot\ShelfService;

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

    /** multipart/form-data "cover": own cover photo/image for the book */
    public function upload(Request $request): array
    {
        $user = $this->user($request);
        $id = $request->paramInt('id') ?? throw ApiException::validation(['id' => 'required']);
        $file = $request->files['cover'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $code = is_array($file) ? (int) ($file['error'] ?? 0) : UPLOAD_ERR_NO_FILE;
            throw new ApiException($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'image_too_large' : 'upload_failed', 422);
        }
        (new CoverService($this->db()))->upload($id, (string) $file['tmp_name']);
        return ['book' => (new BookService($this->db(), new ShelfService($this->db())))->get($id, $user['id'])];
    }

    /** Removes the own cover; the cover sources are asked again. */
    public function removeUpload(Request $request): array
    {
        $user = $this->user($request);
        $id = $request->paramInt('id') ?? throw ApiException::validation(['id' => 'required']);
        (new CoverService($this->db()))->removeUpload($id);
        return ['book' => (new BookService($this->db(), new ShelfService($this->db())))->get($id, $user['id'])];
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
