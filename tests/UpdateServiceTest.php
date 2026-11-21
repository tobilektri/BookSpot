<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\Auth;
use BookSpot\Config;
use BookSpot\Http\Kernel;
use BookSpot\Request;
use BookSpot\UpdateService;

final class UpdateServiceTest extends TestCase
{
    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body = []): \BookSpot\Http\Response
    {
        return Kernel::handle(new Request(
            $method,
            [],
            $body,
            [],
            ['x-requested-with' => 'BookSpot'],
            '127.0.0.1',
            $path,
        ));
    }

    public function testDetectsNewerReleaseAndCachesFor24Hours(): void
    {
        Config::set('UPDATE_CHECK', 'true');
        $calls = 0;
        $service = new UpdateService($this->db, function (string $url) use (&$calls): array {
            $calls++;
            self::assertSame('https://api.github.com/repos/tobilektri/BookSpot/releases/latest', $url);
            return [
                'status' => 200,
                'body' => json_encode([
                    'tag_name' => 'v1.11.0',
                    'html_url' => 'https://github.com/tobilektri/BookSpot/releases/tag/v1.11.0',
                    'published_at' => '2026-10-08T18:30:00Z',
                ]),
            ];
        }, '1.10.0');

        $first = $service->check();
        self::assertSame(1, $calls);
        self::assertTrue($first['enabled']);
        self::assertTrue($first['update_available']);
        self::assertSame('1.11.0', $first['latest_version']);
        self::assertSame('2026-10-08', $first['published_at']);
        self::assertSame('https://github.com/tobilektri/BookSpot/releases/tag/v1.11.0', $first['url']);
        self::assertFalse($first['stale']);
        self::assertNull($first['error']);

        // Within 24h: uses cache, no second HTTP request.
        App::setNow('2026-10-10 11:00:00');
        $second = $service->check();
        self::assertSame(1, $calls);
        self::assertTrue($second['update_available']);

        // Force bypasses cache.
        $forced = $service->check(force: true);
        self::assertSame(2, $calls);
        self::assertFalse($forced['stale']);

        // After 24h: stale again and re-checks automatically.
        App::setNow('2026-10-11 12:00:00');
        self::assertTrue($service->status()['stale']);
        $service->check();
        self::assertSame(3, $calls);
    }

    public function testUpToDateAndTagWithoutPrefix(): void
    {
        Config::set('UPDATE_CHECK', 'true');
        $service = new UpdateService($this->db, static fn (): array => [
            'status' => 200,
            'body' => json_encode([
                'tag_name' => '1.10.0',
                'html_url' => 'https://github.com/tobilektri/BookSpot/releases/tag/1.10.0',
            ]),
        ], '1.10.0');

        $res = $service->check();
        self::assertFalse($res['update_available']);
        self::assertSame('1.10.0', $res['latest_version']);
        self::assertNull($service->cachedNotification());
    }

    public function testDisabledNeverPerformsHttp(): void
    {
        Config::set('UPDATE_CHECK', 'false');
        $called = false;
        $service = new UpdateService($this->db, function () use (&$called): array {
            $called = true;
            return ['status' => 200, 'body' => '{}'];
        }, '1.10.0');

        $res = $service->check(force: true);
        self::assertFalse($called);
        self::assertFalse($res['enabled']);
        self::assertFalse($res['update_available']);
        self::assertNull($service->cachedNotification());
    }

    public function testNetworkErrorPreservesPreviousReleaseAndRetriesAfterOneHour(): void
    {
        Config::set('UPDATE_CHECK', 'true');
        $fail = false;
        $service = new UpdateService($this->db, function () use (&$fail): array {
            if ($fail) {
                return ['status' => 0, 'body' => null];
            }
            return [
                'status' => 200,
                'body' => json_encode([
                    'tag_name' => 'v1.12.0',
                    'html_url' => 'https://github.com/tobilektri/BookSpot/releases/tag/v1.12.0',
                ]),
            ];
        }, '1.10.0');

        $service->check();
        $fail = true;
        $errored = $service->check(force: true);
        self::assertSame('unreachable', $errored['error']);
        self::assertSame('1.12.0', $errored['latest_version']);
        self::assertTrue($errored['update_available']);
        self::assertFalse($errored['stale']);

        App::setNow('2026-10-09 13:05:00');
        self::assertTrue($service->status()['stale']);
    }

    public function testSanitizesNonGithubUrlAndRejectsInvalidVersion(): void
    {
        Config::set('UPDATE_CHECK', 'true');
        $service = new UpdateService($this->db, static fn (): array => [
            'status' => 200,
            'body' => json_encode([
                'tag_name' => 'v2.0.0',
                'html_url' => 'https://evil.example/phish',
            ]),
        ], '1.10.0');

        $res = $service->check();
        self::assertSame('https://github.com/tobilektri/BookSpot/releases/tag/v2.0.0', $res['url']);

        $bad = new UpdateService($this->db, static fn (): array => [
            'status' => 200,
            'body' => json_encode(['tag_name' => 'nightly-build']),
        ], '1.10.0');
        $badRes = $bad->check(force: true);
        self::assertSame('invalid_response', $badRes['error']);
    }

    public function testEndpointsRestrictUpdateCheckAndNotificationToAdmins(): void
    {
        Config::set('UPDATE_CHECK', 'true');
        UpdateService::$defaultHttpGet = static fn (): array => [
            'status' => 200,
            'body' => json_encode([
                'tag_name' => 'v99.0.0',
                'html_url' => 'https://github.com/tobilektri/BookSpot/releases/tag/v99.0.0',
                'published_at' => '2026-10-09T10:00:00Z',
            ]),
        ];

        $admin = $this->createUser('admin');
        $user = $this->createUser('bob');

        // Normal user cannot trigger update-check.
        Auth::setUser($user);
        self::assertSame(403, $this->call('POST', '/v1/admin/update-check')->status);

        // Admin can trigger update-check and receives the update info.
        Auth::setUser($admin);
        $check = $this->call('POST', '/v1/admin/update-check', ['force' => true]);
        self::assertSame(200, $check->status);
        $payload = $check->data();
        self::assertTrue($payload['update']['update_available']);
        self::assertSame('99.0.0', $payload['update']['latest_version']);

        // /auth/status surfaces cached app_update to admins…
        $adminStatus = $this->call('GET', '/v1/auth/status')->data();
        self::assertSame('99.0.0', $adminStatus['app_update']['latest_version']);

        // …but NOT to regular users or guests.
        Auth::setUser($user);
        $userStatus = $this->call('GET', '/v1/auth/status')->data();
        self::assertNull($userStatus['app_update']);

        Auth::setUser(null);
        $guestStatus = $this->call('GET', '/v1/auth/status')->data();
        self::assertNull($guestStatus['app_update']);
    }
}
