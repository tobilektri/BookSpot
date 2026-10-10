<?php

declare(strict_types=1);

namespace BookSpot\Http;

use BookSpot\ApiException;
use BookSpot\Request;

/**
 * Minimal router: method + path pattern -> controller method, with a global
 * middleware pipeline and per-route middleware.
 *
 * Global middleware runs for every request, including 404/405, and sees the
 * matched route (if any) in $request->attributes['route'].
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<Middleware> */
    private array $middleware = [];

    /** @var list<array{0: string, 1: list<Middleware>}> */
    private array $groups = [];

    public function use(Middleware $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function get(string $path, callable|array $handler): Route
    {
        return $this->add(['GET'], $path, $handler);
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function post(string $path, callable|array $handler): Route
    {
        return $this->add(['POST'], $path, $handler);
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function put(string $path, callable|array $handler): Route
    {
        return $this->add(['PUT', 'PATCH'], $path, $handler);
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function delete(string $path, callable|array $handler): Route
    {
        return $this->add(['DELETE'], $path, $handler);
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function any(string $path, callable|array $handler): Route
    {
        return $this->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler);
    }

    /**
     * @param list<string>                           $methods
     * @param callable|array{0: class-string, 1: string} $handler
     */
    public function add(array $methods, string $path, callable|array $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groups as [$groupPrefix, $groupMiddleware]) {
            $prefix .= $groupPrefix;
            array_push($middleware, ...$groupMiddleware);
        }
        $route = new Route($methods, $prefix . $path, $handler, $middleware);
        $this->routes[] = $route;
        return $route;
    }

    /**
     * Routes sharing a path prefix and middleware.
     *
     * @param list<Middleware>       $middleware
     * @param callable(self): void   $define
     */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        $this->groups[] = [$prefix, $middleware];
        try {
            $define($this);
        } finally {
            array_pop($this->groups);
        }
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = [];
        foreach ($this->routes as $route) {
            $params = $route->match($request->path);
            if ($params === null) {
                continue;
            }
            if (!in_array($method, $route->methods, true)) {
                array_push($allowed, ...$route->methods);
                continue;
            }
            $request->params = $params;
            $request->attributes['route'] = $route;
            return $this->pipeline([...$this->middleware, ...$route->middleware], fn (Request $r) => self::call($route->handler, $r))($request);
        }

        // No route: still run the global middleware (error format, headers, CORS).
        $final = $allowed !== []
            ? static fn (): Response => Response::json(['error' => 'method_not_allowed'], 405)
                ->withHeader('Allow', implode(', ', array_unique($allowed)))
            : static fn (): Response => throw ApiException::notFound();
        return $this->pipeline($this->middleware, $final)($request);
    }

    /**
     * @param list<Middleware>          $middleware
     * @param callable(Request): Response $final
     * @return callable(Request): Response
     */
    private function pipeline(array $middleware, callable $final): callable
    {
        $next = $final;
        foreach (array_reverse($middleware) as $layer) {
            $next = static fn (Request $request): Response => $layer->process($request, $next);
        }
        return $next;
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    private static function call(callable|array $handler, Request $request): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $result = $handler($request);
        return $result instanceof Response ? $result : Response::json($result ?? ['ok' => true]);
    }
}
