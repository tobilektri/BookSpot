<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\Config;
use BookSpot\MetadataService;
use BookSpot\MetadataUnavailable;
use BookSpot\RateLimiter;

final class RateLimiterTest extends TestCase
{
    private float $now = 1000.0;
    /** @var list<float> */
    private array $sleeps = [];

    private function limiter(): RateLimiter
    {
        return new RateLimiter(
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->sleeps[] = round($seconds, 3);
                $this->now += $seconds;
            },
        );
    }

    public function testAttemptAllowsBurstThenRefillsEvenly(): void
    {
        $limiter = $this->limiter();
        // 6 per minute: a burst of 6, then one every 10 seconds.
        for ($i = 0; $i < 6; $i++) {
            self::assertSame(0.0, $limiter->attempt('k', 6, 60));
        }
        self::assertEqualsWithDelta(10.0, $limiter->attempt('k', 6, 60), 0.001);
        $this->now += 10;
        self::assertSame(0.0, $limiter->attempt('k', 6, 60));
        self::assertGreaterThan(0.0, $limiter->attempt('k', 6, 60));
        // keys are independent
        self::assertSame(0.0, $limiter->attempt('other', 6, 60));
    }

    public function testOpenLibraryAllowsMoreRequestsWithContactAddress(): void
    {
        Config::set('RATE_LIMIT_OPEN_LIBRARY', null);
        Config::set('CONTACT_EMAIL', null);
        self::assertSame(1.0, RateLimiter::providerInterval('open_library'));
        self::assertSame('BookSpot/' . App::VERSION . ' (https://github.com/tobilektri/BookSpot)', App::userAgent());

        Config::set('CONTACT_EMAIL', 'books@example.org');
        self::assertEqualsWithDelta(1 / 3, RateLimiter::providerInterval('open_library'), 0.0001);
        Config::set('APP_URL', 'https://books.example.org');
        self::assertSame('BookSpot/' . App::VERSION . ' (https://books.example.org; books@example.org)', App::userAgent());

        Config::set('CONTACT_EMAIL', "evil@example.org\r\nX-Injected: 1");
        self::assertNull(App::contactEmail(), 'invalid addresses never reach the header');
        self::assertSame(1.0, RateLimiter::providerInterval('open_library'));

        Config::set('CONTACT_EMAIL', 'books@example.org');
        Config::set('RATE_LIMIT_OPEN_LIBRARY', '2');
        self::assertSame(0.5, RateLimiter::providerInterval('open_library'), 'an explicit setting wins');

        Config::set('CONTACT_EMAIL', null);
        Config::set('APP_URL', null);
    }

    public function testThrottleSpacesCallsAndRespectsMaxWait(): void
    {
        $limiter = $this->limiter();
        // A source allowing 2 per second -> 0.5 s apart
        self::assertTrue($limiter->throttle('dnb', 0.5, 5));
        self::assertTrue($limiter->throttle('dnb', 0.5, 5));
        self::assertTrue($limiter->throttle('dnb', 0.5, 5));
        self::assertSame([0.5, 0.5], $this->sleeps);

        // Five more callers queue up behind each other; one that may only wait 0.2 s is turned away.
        self::assertFalse($limiter->throttle('dnb', 0.5, 0.2));
        self::assertCount(2, $this->sleeps, 'no waiting when the slot is too far away');
    }

    public function testParallelReservationsGetConsecutiveSlots(): void
    {
        // Two processes reserving at the same moment (no sleeping in between) get different slots.
        $limiter = new RateLimiter(fn (): float => 1000.0, function (float $s): void {
            $this->sleeps[] = round($s, 3);
        });
        foreach (range(1, 4) as $_) {
            $limiter->throttle('google_books', 0.2, 10);
        }
        self::assertSame([0.2, 0.4, 0.6], $this->sleeps);
    }

    public function testPenalizeBlocksWithExponentialBackoffAndRetryAfter(): void
    {
        $limiter = $this->limiter();
        self::assertSame(60, $limiter->penalize('open_library'));
        self::assertFalse($limiter->throttle('open_library', 1, 30), 'blocked providers are skipped');
        self::assertEqualsWithDelta(60.0, $limiter->blockedFor('open_library'), 0.001);

        $this->now += 61;
        self::assertSame(120, $limiter->penalize('open_library'));
        self::assertSame(240, $limiter->penalize('open_library'));
        self::assertSame(17, $limiter->penalize('open_library', retryAfter: 17), 'Retry-After wins');

        $this->now += 18;
        self::assertTrue($limiter->throttle('open_library', 1, 0));
        $limiter->recover('open_library');
        self::assertSame(60, $limiter->penalize('open_library'), 'success resets the backoff');
        self::assertSame(3600, (new RateLimiter(fn () => 0.0))->penalize('x', 99999), 'capped at one hour');
    }

    public function testMetadataLookupFallsBackWhenAProviderAnswers429(): void
    {
        Config::set('METADATA_LOOKUP', 'true');
        Config::set('RATE_LIMIT_OPEN_LIBRARY', '1');
        $calls = [];
        $http = function (string $url) use (&$calls): ?string {
            $calls[] = parse_url($url, PHP_URL_HOST);
            if (str_contains($url, 'openlibrary.org')) {
                throw new MetadataUnavailable('HTTP status 429', 429, 120);
            }
            if (str_contains($url, 'googleapis.com')) {
                return (string) json_encode(['items' => [['volumeInfo' => ['title' => 'Impact', 'authors' => ['Eschbach'], 'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => '9783548283951']]]]]]);
            }
            return null; // DNB: nothing found
        };
        $service = new MetadataService($this->db, $http, $this->limiter());

        $first = $service->lookup('9783548283951');
        self::assertSame('Impact', $first['title']);
        self::assertTrue($first['partial'], 'Open Library failed, so the result may be incomplete');
        self::assertSame(120, $service->providerBlockedFor('open_library'));

        // While blocked, Open Library is not asked at all – no further 429s.
        $calls = [];
        $second = $service->lookup('9783548283951');
        self::assertSame('Impact', $second['title']);
        self::assertNotContains('openlibrary.org', $calls);
        self::assertSame([], array_filter($this->sleeps, fn (float $s) => $s >= 0.01), 'no waiting for a blocked provider');
        Config::set('METADATA_LOOKUP', 'false');
    }
}
