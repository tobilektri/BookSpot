<?php

declare(strict_types=1);

namespace BookSpot\Http;

use BookSpot\ApiException;
use BookSpot\Config;
use BookSpot\Controller\AdminController;
use BookSpot\Controller\AuthController;
use BookSpot\Controller\BatchController;
use BookSpot\Controller\BookController;
use BookSpot\Controller\CoverController;
use BookSpot\Controller\CronController;
use BookSpot\Controller\ExportController;
use BookSpot\Controller\ImportController;
use BookSpot\Controller\LookupController;
use BookSpot\Controller\PushController;
use BookSpot\Controller\ShelfController;
use BookSpot\Controller\StatsController;
use BookSpot\Controller\SyncController;
use BookSpot\Controller\UserController;
use BookSpot\Http\Middleware\Authenticate;
use BookSpot\Http\Middleware\Cors;
use BookSpot\Http\Middleware\Csrf;
use BookSpot\Http\Middleware\ErrorHandler;
use BookSpot\Http\Middleware\RateLimit;
use BookSpot\Http\Middleware\SecurityHeaders;
use BookSpot\Request;

/**
 * Single entry point of the API: every request – /api/v1/… via public/api/index.php
 * and the old endpoint files (books.php …) – is dispatched by this route table.
 */
final class Kernel
{
    public static function serve(?string $route = null): void
    {
        try {
            $request = Request::fromGlobals($route);
        } catch (ApiException $e) { // e.g. invalid JSON body
            Response::json(['error' => $e->getMessage()], $e->getCode() ?: 400)->send();
            return;
        }
        self::handle($request)->send();
    }

    public static function handle(Request $request): Response
    {
        return self::router()->dispatch($request);
    }

    public static function router(): Router
    {
        $router = new Router();
        // Order matters: headers and CORS also apply to error responses.
        $router->use(new SecurityHeaders());
        $router->use(new Cors());
        $router->use(new ErrorHandler());
        $router->use(new Csrf());
        $router->use(RateLimit::global(Config::int('RATE_LIMIT_REQUESTS_PER_MINUTE', 600)));

        $user = new Authenticate();
        $admin = Authenticate::admin();

        $router->group('/v1', [], function (Router $r) use ($user, $admin): void {
            // Public
            $r->get('/auth/status', [AuthController::class, 'status']);
            $r->post('/auth/login', [AuthController::class, 'login'])->middleware(new RateLimit('login', 10, 60, perIp: true));
            $r->post('/auth/register', [AuthController::class, 'register'])->middleware(new RateLimit('register', 5, 60, perIp: true));
            $r->post('/auth/logout', [AuthController::class, 'logout']);
            $r->get('/auth/invites/{token}', [AuthController::class, 'inviteInfo'])->middleware(new RateLimit('invite', 20, 60, perIp: true));
            $r->post('/auth/invites/accept', [AuthController::class, 'acceptInvite'])->middleware(new RateLimit('invite', 20, 60, perIp: true));
            $r->any('/cron', [CronController::class, 'run'])->withoutCsrf()->middleware(new RateLimit('cron', 30, 60, perIp: true));

            // Cover images: many parallel requests, no session lock, not counted towards the request limit.
            $r->get('/books/{id:\d+}/cover', [CoverController::class, 'show'])->middleware(new Authenticate(readOnlySession: true))->withoutThrottle();

            $r->group('', [$user], function (Router $r): void {
                $r->post('/auth/onboarded', [AuthController::class, 'onboarded']);
                $r->post('/auth/impersonation/stop', [AuthController::class, 'stopImpersonation']);
                $r->get('/auth/profile', [AuthController::class, 'profile']);
                $r->put('/auth/profile', [AuthController::class, 'updateProfile']);

                $r->get('/books', [BookController::class, 'index']);
                $r->post('/books', [BookController::class, 'store']);
                $r->get('/books/due', [BookController::class, 'due']);
                $r->get('/books/genres', [BookController::class, 'genres']);
                $r->get('/books/enrich-status', [BookController::class, 'enrichStatus']);
                $r->post('/books/enrich', [BookController::class, 'enrich'])->middleware(new RateLimit('enrich', 20, 60));
                $r->post('/books/move', [BookController::class, 'move']);
                // Bulk scan: accepted at once, metadata looked up by a rate-limited queue.
                $r->post('/books/batch', [BatchController::class, 'store'])->middleware(new RateLimit('batch', 30, 60));
                $r->post('/books/batch/process', [BatchController::class, 'process'])->middleware(new RateLimit('batch_process', 40, 60));
                $r->get('/books/isbn/{isbn}', [BookController::class, 'byIsbn']);
                $r->get('/books/{id:\d+}', [BookController::class, 'show']);
                $r->put('/books/{id:\d+}', [BookController::class, 'update']);
                $r->delete('/books/{id:\d+}', [BookController::class, 'destroy']);
                $r->get('/books/{id:\d+}/personal', [BookController::class, 'personal']);
                $r->put('/books/{id:\d+}/personal', [BookController::class, 'updatePersonal']);
                $r->get('/covers/preview', [CoverController::class, 'preview'])->middleware(new RateLimit('cover_preview', 60, 60));

                $r->get('/shelves', [ShelfController::class, 'index']);
                $r->post('/shelves', [ShelfController::class, 'store']);
                $r->get('/shelves/{id:\d+}', [ShelfController::class, 'show']);
                $r->put('/shelves/{id:\d+}', [ShelfController::class, 'update']);
                $r->delete('/shelves/{id:\d+}', [ShelfController::class, 'destroy']);

                $r->get('/users', [UserController::class, 'index']);
                $r->get('/lookup/{isbn}', [LookupController::class, 'lookup'])->middleware(new RateLimit('lookup', 60, 60));
                $r->get('/stats', [StatsController::class, 'stats']);
                $r->post('/import', [ImportController::class, 'import'])->middleware(new RateLimit('import', 10, 60));
                $r->get('/export', [ExportController::class, 'export']);
                $r->post('/sync', [SyncController::class, 'sync']);

                $r->get('/push', [PushController::class, 'info']);
                $r->post('/push/subscriptions', [PushController::class, 'subscribe']);
                $r->delete('/push/subscriptions', [PushController::class, 'unsubscribe']);
                $r->post('/push/test', [PushController::class, 'test'])->middleware(new RateLimit('push_test', 5, 60));
            });

            $r->group('', [$admin], function (Router $r): void {
                $r->post('/users', [UserController::class, 'store']);
                $r->put('/users/{id:\d+}', [UserController::class, 'update']);
                $r->delete('/users/{id:\d+}', [UserController::class, 'destroy']);
                $r->post('/users/{id:\d+}/invite', [UserController::class, 'invite'])->middleware(new RateLimit('invite_mail', 10, 60));
                $r->post('/users/{id:\d+}/impersonate', [UserController::class, 'impersonate']);
                $r->get('/admin/system', [AdminController::class, 'system']);
                $r->post('/admin/cron-token', [AdminController::class, 'regenerateCronToken']);
            });
        });

        // Old endpoint files (api/books.php …) stay valid: installed app versions, offline
        // queues and cron URLs configured at the hoster keep working.
        $router->any('/auth.php', [AuthController::class, 'legacy']);
        $router->any('/books.php', [BookController::class, 'legacy']);
        $router->any('/user_books.php', [BookController::class, 'legacyPersonal']);
        $router->any('/shelves.php', [ShelfController::class, 'legacy']);
        $router->any('/users.php', [UserController::class, 'legacy']);
        $router->any('/admin.php', [AdminController::class, 'legacy']);
        $router->any('/import.php', [ImportController::class, 'import'])->middleware(new RateLimit('import', 10, 60, perIp: true));
        $router->any('/export.php', [ExportController::class, 'export']);
        $router->any('/lookup.php', [LookupController::class, 'lookup'])->middleware(new RateLimit('lookup', 60, 60, perIp: true));
        $router->any('/stats.php', [StatsController::class, 'stats']);
        $router->any('/push.php', [PushController::class, 'legacy']);
        $router->any('/sync.php', [SyncController::class, 'sync']);
        $router->any('/cron.php', [CronController::class, 'run'])->withoutCsrf()->middleware(new RateLimit('cron', 30, 60, perIp: true));
        $router->get('/cover.php', [CoverController::class, 'legacy'])->middleware(new Authenticate(readOnlySession: true))->withoutThrottle();

        return $router;
    }
}
