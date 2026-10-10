<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Rate limiting without a daemon or cache server: the state per key lives in a
 * small file under var/ratelimit/ and is updated under an exclusive flock, so
 * concurrent PHP processes on the same host share it.
 *
 * Both uses are based on GCRA (a token bucket expressed as a "theoretical
 * arrival time"):
 *  - attempt():  API requests – reject when more than $max per $window
 *  - throttle(): calls to external providers – wait for the next free slot
 *                (up to $maxWait) instead of failing; providers that answered
 *                429 are blocked with exponential backoff (penalize()).
 */
final class RateLimiter
{
    /** Default spacing between calls per provider in seconds (configurable in .env as requests per second). */
    private const PROVIDER_INTERVALS = [
        'open_library' => 1.0,          // RATE_LIMIT_OPEN_LIBRARY=1 (3 with CONTACT_EMAIL, see providerInterval())
        'dnb' => 0.5,                   // RATE_LIMIT_DNB=2
        'google_books' => 0.2,          // RATE_LIMIT_GOOGLE_BOOKS=5
        'open_library_covers' => 3.0,   // covers by ISBN: Open Library allows 100 per 5 minutes and IP
        'dnb_covers' => 0.5,            // RATE_LIMIT_DNB_COVERS=2
    ];
    private const MAX_BLOCK_SECONDS = 3600;
    private const BASE_BLOCK_SECONDS = 60;

    /** Storage directory (tests). */
    public static ?string $directory = null;

    /** @var callable(): float */
    private $clock;
    /** @var callable(float): void */
    private $sleep;

    public function __construct(?callable $clock = null, ?callable $sleep = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public static function providerInterval(string $provider): float
    {
        $rate = Config::get('RATE_LIMIT_' . strtoupper($provider));
        if ($rate !== null && is_numeric($rate) && (float) $rate > 0) {
            return 1 / (float) $rate;
        }
        // Open Library: 1 request per second, 3 for clients that identify themselves
        // with a contact address in the User-Agent (https://openlibrary.org/developers/api).
        if ($provider === 'open_library' && App::contactEmail() !== null) {
            return 1 / 3;
        }
        return self::PROVIDER_INTERVALS[$provider] ?? 1.0;
    }

    /**
     * Request limit: at most $max calls per $window seconds (bursts up to $max allowed).
     *
     * @return float 0 when allowed, otherwise seconds until the next call is allowed
     */
    public function attempt(string $key, int $max, float $window): float
    {
        $interval = $window / max(1, $max);
        $tolerance = $window - $interval;
        return $this->update($key, function (array &$state, float $now) use ($interval, $tolerance): float {
            $tat = max($state['tat'], $now);
            if ($tat - $now > $tolerance) {
                return $tat - $now - $tolerance;
            }
            $state['tat'] = $tat + $interval;
            return 0.0;
        }, 0.0);
    }

    /**
     * Provider throttle: reserves the next free slot (one call per $interval seconds)
     * and waits for it. Returns false without waiting when the provider is blocked
     * after a 429 or the wait would exceed $maxWait – the caller then skips the
     * provider (fallback) or retries later.
     */
    public function throttle(string $key, float $interval, float $maxWait): bool
    {
        $wait = $this->update($key, function (array &$state, float $now) use ($interval, $maxWait): ?float {
            if ($state['blocked_until'] > $now) {
                return null;
            }
            $slot = max($state['tat'], $now);
            if ($slot - $now > $maxWait) {
                return null;
            }
            $state['tat'] = $slot + $interval;
            return $slot - $now;
        }, 0.0);
        if ($wait === null) {
            return false;
        }
        if ($wait > 0) {
            ($this->sleep)($wait);
        }
        return true;
    }

    /**
     * The provider answered "too many requests": block it for Retry-After seconds or,
     * without that header, exponentially longer on every repeated 429 (1, 2, 4 … 60 min).
     *
     * @return int block duration in seconds
     */
    public function penalize(string $key, ?int $retryAfter = null): int
    {
        return $this->update($key, function (array &$state, float $now) use ($retryAfter): int {
            $state['strikes']++;
            $seconds = $retryAfter !== null && $retryAfter > 0
                ? min($retryAfter, self::MAX_BLOCK_SECONDS)
                : min(self::BASE_BLOCK_SECONDS * 2 ** min($state['strikes'] - 1, 10), self::MAX_BLOCK_SECONDS);
            $state['blocked_until'] = $now + $seconds;
            return (int) $seconds;
        }, 0);
    }

    /** A successful call resets the backoff. */
    public function recover(string $key): void
    {
        if ($this->read($key)['strikes'] === 0) {
            return; // nothing to reset: avoid a write per call
        }
        $this->update($key, function (array &$state): bool {
            $state['strikes'] = 0;
            return true;
        }, true);
    }

    /** Seconds the key is still blocked (0 = not blocked). */
    public function blockedFor(string $key): float
    {
        return max(0.0, $this->read($key)['blocked_until'] - ($this->clock)());
    }

    /** Removes state files that were not touched for two days (cron). */
    public static function cleanup(int $maxAgeSeconds = 172800): int
    {
        $removed = 0;
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            if (@filemtime($file) < time() - $maxAgeSeconds && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    private static function directory(): string
    {
        $dir = self::$directory ?? App::varDir() . '/ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** @return array{tat: float, blocked_until: float, strikes: int} */
    private function read(string $key): array
    {
        $raw = @file_get_contents(self::file($key));
        return self::decode($raw === false ? '' : $raw);
    }

    /**
     * Read-modify-write under an exclusive lock. Fails open ($fallback) when the
     * directory is not writable – rate limiting must never take the app down.
     *
     * @template T
     * @param callable(array{tat: float, blocked_until: float, strikes: int}, float): T $change
     * @param T $fallback
     * @return T
     */
    private function update(string $key, callable $change, mixed $fallback): mixed
    {
        $handle = @fopen(self::file($key), 'c+');
        if ($handle === false) {
            error_log('[BookSpot] rate limiter: var/ratelimit is not writable');
            return $fallback;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return $fallback;
            }
            $state = self::decode((string) stream_get_contents($handle));
            $before = $state;
            $result = $change($state, ($this->clock)());
            if ($state !== $before) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($state));
                fflush($handle);
            }
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function file(string $key): string
    {
        return self::directory() . '/' . sha1($key) . '.json';
    }

    /** @return array{tat: float, blocked_until: float, strikes: int} */
    private static function decode(string $raw): array
    {
        $data = $raw === '' ? null : json_decode($raw, true);
        return [
            'tat' => (float) ($data['tat'] ?? 0),
            'blocked_until' => (float) ($data['blocked_until'] ?? 0),
            'strikes' => (int) ($data['strikes'] ?? 0),
        ];
    }
}
