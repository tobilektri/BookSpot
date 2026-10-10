<?php

declare(strict_types=1);

namespace BookSpot\Http;

final class Route
{
    public readonly string $regex;

    /** CSRF header check for state-changing requests (off only for token-protected cron calls). */
    public bool $csrf = true;

    /** Counts towards the global per-IP request limit (off for cover images). */
    public bool $throttle = true;

    /**
     * @param list<string>                         $methods
     * @param callable|array{0: class-string, 1: string} $handler
     * @param list<Middleware>                     $middleware
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly mixed $handler,
        public array $middleware = [],
    ) {
        // "/v1/books/{id:\d+}" -> named groups; parameters without a pattern match one segment.
        $pattern = '';
        foreach (preg_split('#(\{[a-z_]+(?::[^}]+)?\})#i', $path, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (preg_match('#^\{([a-z_]+)(?::([^}]+))?\}$#i', $part, $m)) {
                $pattern .= '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $pattern .= preg_quote($part, '#');
            }
        }
        $this->regex = '#^' . $pattern . '/?$#';
    }

    public function middleware(Middleware ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    public function withoutCsrf(): self
    {
        $this->csrf = false;
        return $this;
    }

    public function withoutThrottle(): self
    {
        $this->throttle = false;
        return $this;
    }

    /** @return array<string, string>|null path parameters when the path matches */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $m)) {
            return null;
        }
        return array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
