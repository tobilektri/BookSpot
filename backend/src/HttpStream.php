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
     * @param array{host: string, port: int, ip: string}|null $pin connect to this checked IP (no DNS rebinding)
     * @param int|null $maxBytes read at most this much (larger bodies count as failed)
     * @return array{status: int, body: ?string, headers: list<string>} status 0 = network error;
     *         headers of all responses in order (redirects included), status of the last one
     */
    public static function get(string $url, array $options, ?array $pin = null, ?int $maxBytes = null): array
    {
        $context = ['http' => $options + ['ignore_errors' => true]];
        if ($pin !== null) {
            $parts = parse_url($url);
            $ip = str_contains($pin['ip'], ':') ? '[' . $pin['ip'] . ']' : $pin['ip'];
            $url = $parts['scheme'] . '://' . $ip . ':' . $pin['port'] . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            $context['http']['header'] = ($context['http']['header'] ?? '') . 'Host: ' . $pin['host'] . "\r\n";
            $context['ssl'] = ['peer_name' => $pin['host'], 'SNI_enabled' => true];
        }
        $handle = @fopen($url, 'rb', false, stream_context_create($context));
        if ($handle === false) {
            return ['status' => 0, 'body' => null, 'headers' => []];
        }
        try {
            $meta = stream_get_meta_data($handle);
            $body = $maxBytes === null ? stream_get_contents($handle) : stream_get_contents($handle, $maxBytes + 1);
        } finally {
            fclose($handle);
        }
        if ($maxBytes !== null && is_string($body) && strlen($body) > $maxBytes) {
            $body = false;
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
