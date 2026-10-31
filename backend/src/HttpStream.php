<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * HTTP GET via PHP streams, the fallback when the curl extension is missing.
 *
 * Reads the response headers from the stream's metadata instead of the magic
 * $http_response_header variable, which PHP 8.5 deprecates.
 */
final class HttpStream
{
    /**
     * @param array<string, mixed> $options "http" stream context options
     * @return array{status: int, body: ?string, headers: list<string>} status 0 = network error;
     *         headers of all responses in order (redirects included), status of the last one
     */
    public static function get(string $url, array $options): array
    {
        $context = stream_context_create(['http' => $options + ['ignore_errors' => true]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return ['status' => 0, 'body' => null, 'headers' => []];
        }
        try {
            $meta = stream_get_meta_data($handle);
            $body = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        $headers = array_values(array_filter((array) ($meta['wrapper_data'] ?? []), 'is_string'));
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $body === false ? 0 : $status, 'body' => $body === false ? null : $body, 'headers' => $headers];
    }
}
