<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\Auth;
use BookSpot\Http\Kernel;
use BookSpot\Http\Middleware\Cors;
use BookSpot\Http\Middleware\ErrorHandler;
use BookSpot\Http\Middleware\RateLimit;
use BookSpot\Http\Response;
use BookSpot\Http\Router;
use BookSpot\RateLimiter;
use BookSpot\Request;

final class RouterTest extends TestCase
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body @param array<string, string> $headers */
    private function call(string $method, string $path, array $query = [], array $body = [], array $headers = []): Response
    {
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $headers += ['x-requested-with' => 'BookSpot'];
        }
        return Kernel::handle(new Request($method, $query, $body, [], array_change_key_case($headers), '10.0.0.1', $path));
    }

    public function testEveryRouteHandlerExists(): void
    {
        foreach (Kernel::router()->routes() as $route) {
            self::assertIsArray($route->handler);
            self::assertTrue(method_exists($route->handler[0], $route->handler[1]), $route->path);
        }
    }

    public function testParamsNotFoundAndMethodNotAllowed(): void
    {
        $router = new Router();
        $router->use(new ErrorHandler());
        $router->get('/v1/things/{id:\d+}', fn (Request $r) => ['id' => $r->paramInt('id')]);
        $router->get('/old.php', fn () => ['old' => true]);

        $ok = $router->dispatch(new Request('GET', path: '/v1/things/42/'));
        self::assertSame(['id' => 42], $ok->data());
        self::assertSame(404, $router->dispatch(new Request('GET', path: '/v1/things/abc'))->status);
        self::assertSame(404, $router->dispatch(new Request('GET', path: '/oldXphp'))->status, 'static parts are literal');
        $notAllowed = $router->dispatch(new Request('DELETE', path: '/v1/things/1'));
        self::assertSame(405, $notAllowed->status);
        self::assertSame('GET', $notAllowed->header('Allow'));
    }

    public function testErrorsSecurityHeadersAndCsrf(): void
    {
        $missing = $this->call('GET', '/v1/nope');
        self::assertSame(404, $missing->status);
        self::assertSame(['error' => 'not_found'], $missing->data());
        self::assertSame('nosniff', $missing->header('X-Content-Type-Options'));
        self::assertStringContainsString("default-src 'none'", (string) $missing->header('Content-Security-Policy'));

        $noHeader = Kernel::handle(new Request('POST', body: ['login' => 'x'], path: '/v1/auth/login'));
        self::assertSame(['error' => 'csrf_header_missing'], $noHeader->data());
        // Cron is protected by its token instead of the CSRF header.
        self::assertSame(['error' => 'forbidden'], Kernel::handle(new Request('POST', query: ['token' => 'wrong'], path: '/v1/cron'))->data());
    }

    public function testAuthenticationAndRoles(): void
    {
        self::assertSame(401, $this->call('GET', '/v1/books')->status);
        $admin = $this->createUser('admin'); // first user is admin
        $user = $this->createUser('ben');

        Auth::setUser($user);
        self::assertSame(200, $this->call('GET', '/v1/books')->status);
        self::assertSame(403, $this->call('GET', '/v1/admin/system')->status);
        self::assertSame(403, $this->call('POST', '/v1/users', body: ['username' => 'x'])->status);
        self::assertSame([['id' => $admin['id'], 'username' => 'admin'], ['id' => $user['id'], 'username' => 'ben']], $this->call('GET', '/v1/users')->data()['users']);

        Auth::setUser($admin);
        self::assertSame(200, $this->call('GET', '/v1/admin/system')->status);
    }

    public function testRestRoutesAndLegacyEndpointsAgree(): void
    {
        Auth::setUser($this->createUser());
        $created = $this->call('POST', '/v1/books', body: ['title' => 'Impact', 'isbn' => '9783548283951']);
        self::assertSame(201, $created->status);
        $id = $created->data()['book']['id'];

        self::assertSame('Impact', $this->call('GET', "/v1/books/$id")->data()['book']['title']);
        self::assertSame($this->call('GET', "/v1/books/$id")->data(), $this->call('GET', '/books.php', ['id' => (string) $id])->data());
        self::assertTrue($this->call('GET', '/v1/books/isbn/978-3-548-28395-1')->data()['found']);
        self::assertTrue($this->call('GET', '/books.php', ['isbn' => '9783548283951'])->data()['found']);

        $this->call('PUT', "/v1/books/$id/personal", body: ['reading_status' => 'reading']);
        self::assertSame('reading', $this->call('GET', '/user_books.php', ['book_id' => (string) $id])->data()['personal']['reading_status']);

        self::assertSame('Impact 2', $this->call('PUT', '/books.php', ['id' => (string) $id], ['title' => 'Impact 2'])->data()['book']['title']);
        self::assertSame(['ok' => true], $this->call('DELETE', "/v1/books/$id")->data());
        self::assertSame(404, $this->call('GET', "/v1/books/$id")->status);

        // auth.php?action=status and the new route return the same payload
        self::assertSame($this->call('GET', '/v1/auth/status')->data(), $this->call('GET', '/auth.php', ['action' => 'status'])->data());
        self::assertSame(405, $this->call('GET', '/auth.php', ['action' => 'logout'])->status);
    }

    public function testRouteRateLimitAnswers429WithRetryAfter(): void
    {
        $now = 1000.0;
        $limiter = new RateLimiter(function () use (&$now): float {
            return $now;
        });
        $router = new Router();
        $router->post('/v1/batch', fn () => ['ok' => true])->middleware(new RateLimit('batch', 3, 60, limiter: $limiter));
        $request = fn () => $router->dispatch(new Request('POST', ip: '10.0.0.2', path: '/v1/batch'));

        self::assertSame([200, 200, 200], [$request()->status, $request()->status, $request()->status]);
        $limited = $request();
        self::assertSame(429, $limited->status);
        self::assertSame('20', $limited->header('Retry-After'));
        self::assertSame(['error' => 'rate_limited', 'retry_after' => 20], $limited->data());
        $now += 20;
        self::assertSame(200, $request()->status);
    }

    public function testCorsOnlyForConfiguredOrigins(): void
    {
        $router = new Router();
        $router->use(new Cors(['https://app.example']));
        $router->get('/v1/ping', fn () => ['pong' => true]);
        $preflight = fn (string $origin) => $router->dispatch(new Request('OPTIONS', headers: [
            'origin' => $origin, 'access-control-request-method' => 'POST', 'host' => 'books.example',
        ], path: '/v1/ping'));

        self::assertSame(403, $preflight('https://evil.example')->status);
        $allowed = $preflight('https://app.example');
        self::assertSame(204, $allowed->status);
        self::assertSame('https://app.example', $allowed->header('Access-Control-Allow-Origin'));

        $sameOrigin = $router->dispatch(new Request('GET', headers: ['origin' => 'http://books.example', 'host' => 'books.example'], path: '/v1/ping'));
        self::assertNull($sameOrigin->header('Access-Control-Allow-Origin'));
    }
}
