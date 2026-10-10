<?php

declare(strict_types=1);

namespace BookSpot;

final class Request
{
    /** @var array<string, string> path parameters of the matched route, e.g. ['id' => '12'] */
    public array $params = [];

    /** @var array<string, mixed> values set by middleware, e.g. 'user' and 'route' */
    public array $attributes = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $files
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly string $method,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly array $headers = [],
        public readonly string $ip = '0.0.0.0',
        public readonly string $path = '/',
    ) {
    }

    /**
     * @param string|null $route fixed route path (legacy endpoint files); otherwise taken from
     *                           ?_route= (set by the rewrite rules), PATH_INFO or the request URI
     */
    public static function fromGlobals(?string $route = null): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        // Some shared hosters filter PUT/PATCH/DELETE; the frontend therefore tunnels them through POST.
        $override = strtoupper($headers['x-http-method-override'] ?? '');
        if ($method === 'POST' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = $override;
        }

        $body = [];
        $contentType = $headers['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    throw new ApiException('invalid_json', 400);
                }
                $body = $decoded;
            }
        } else {
            $body = $_POST;
        }

        $query = $_GET;
        $path = $route ?? self::pathFromGlobals($query);
        unset($query['_route']);

        return new self($method, $query, $body, $_FILES, $headers, (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), $path);
    }

    /**
     * Route path relative to the API directory, e.g. "/v1/books/12".
     * Works with and without URL rewriting and in sub-directories.
     *
     * @param array<string, mixed> $query
     */
    private static function pathFromGlobals(array $query): string
    {
        if (is_string($query['_route'] ?? null)) {
            return self::cleanPath($query['_route']);
        }
        if (!empty($_SERVER['PATH_INFO'])) {
            return self::cleanPath((string) $_SERVER['PATH_INFO']); // api/index.php/v1/books
        }
        $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        if ($base !== '' && str_starts_with($uri, $base . '/')) {
            $uri = substr($uri, strlen($base));
        }
        return self::cleanPath(preg_replace('#^/index\.php#', '', $uri) ?? $uri);
    }

    private static function cleanPath(string $path): string
    {
        $path = '/' . trim(rawurldecode($path), '/');
        return preg_replace('#/+#', '/', $path) ?? $path;
    }

    /** Path parameter of the matched route as a positive integer. */
    public function paramInt(string $key): ?int
    {
        $value = $this->params[$key] ?? null;
        return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $value;
    }

    public function queryInt(string $key): ?int
    {
        $value = $this->query[$key] ?? null;
        return is_numeric($value) ? (int) $value : null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function action(): string
    {
        return (string) ($this->query['action'] ?? '');
    }

    public function isSafe(): bool
    {
        return in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
