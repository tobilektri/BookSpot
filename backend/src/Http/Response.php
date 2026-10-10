<?php

declare(strict_types=1);

namespace BookSpot\Http;

/**
 * HTTP response returned by controllers and middleware. The body is a string or a
 * closure that writes the output itself (downloads, files).
 */
final class Response
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public array $headers = [],
        public readonly string|\Closure|null $body = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self($status, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, max-age=0',
        ], (string) json_encode($data, self::JSON_FLAGS));
    }

    /** @param array<string, string> $headers */
    public static function empty(int $status, array $headers = []): self
    {
        return new self($status, $headers);
    }

    public function isJson(): bool
    {
        return str_starts_with((string) $this->header('Content-Type'), 'application/json');
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        foreach (array_keys($this->headers) as $key) {
            if (strcasecmp($key, $name) === 0) {
                unset($this->headers[$key]);
            }
        }
        $this->headers[$name] = $value;
        return $this;
    }

    /** Sets a header unless the response already has one with that name. */
    public function withDefaultHeader(string $name, string $value): self
    {
        if ($this->header($name) === null) {
            $this->headers[$name] = $value;
        }
        return $this;
    }

    /** Decoded JSON body (tests). */
    public function data(): mixed
    {
        return is_string($this->body) ? json_decode($this->body, true) : null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }
        if ($this->body instanceof \Closure) {
            ($this->body)();
        } elseif ($this->body !== null) {
            echo $this->body;
        }
    }
}
