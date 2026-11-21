<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Checks GitHub Releases for a newer BookSpot version.
 *
 * Server-side only (the browser never talks to GitHub), cached in the settings
 * table for 24 hours so neither the app nor GitHub's rate limit is burdened.
 * Can be switched off completely with UPDATE_CHECK=false.
 */
final class UpdateService
{
    public const DEFAULT_REPO = 'tobilektri/BookSpot';
    private const SETTING = 'update_check';
    private const TTL_SECONDS = 86400; // 24 h
    private const ERROR_RETRY_SECONDS = 3600; // 1 h
    private const TIMEOUT = 6;
    private const MAX_BYTES = 262144; // 256 KB

    /** Optional HTTP override for integration tests (Kernel::handle). */
    public static ?\Closure $defaultHttpGet = null;

    public function __construct(
        private readonly Database $db,
        private readonly ?\Closure $httpGet = null,
        private readonly ?string $currentVersion = null,
    ) {
    }

    public static function enabled(): bool
    {
        return Config::bool('UPDATE_CHECK', true);
    }

    /** Validated "owner/repo" string (falls back to the official repository). */
    public static function repository(): string
    {
        $configured = trim((string) Config::get('UPDATE_CHECK_REPO', self::DEFAULT_REPO));
        return preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $configured) === 1
            ? $configured
            : self::DEFAULT_REPO;
    }

    /**
     * Current update status purely from the database cache (never performs HTTP).
     *
     * @return array{
     *   enabled: bool,
     *   current_version: string,
     *   latest_version: ?string,
     *   update_available: bool,
     *   url: ?string,
     *   published_at: ?string,
     *   checked_at: ?string,
     *   stale: bool,
     *   error: ?string
     * }
     */
    public function status(): array
    {
        $current = $this->current();
        if (!self::enabled()) {
            return [
                'enabled' => false,
                'current_version' => $current,
                'latest_version' => null,
                'update_available' => false,
                'url' => null,
                'published_at' => null,
                'checked_at' => null,
                'stale' => false,
                'error' => null,
            ];
        }
        $cached = $this->readCache();
        $latest = is_string($cached['latest_version'] ?? null) ? $cached['latest_version'] : null;
        $checkedAt = is_string($cached['checked_at'] ?? null) ? $cached['checked_at'] : null;
        $error = is_string($cached['error'] ?? null) ? $cached['error'] : null;
        $updateAvailable = $latest !== null && self::isNewer($latest, $current);

        return [
            'enabled' => true,
            'current_version' => $current,
            'latest_version' => $latest,
            'update_available' => $updateAvailable,
            'url' => is_string($cached['url'] ?? null) ? $cached['url'] : null,
            'published_at' => is_string($cached['published_at'] ?? null) ? $cached['published_at'] : null,
            'checked_at' => $checkedAt,
            'stale' => $this->isStale($checkedAt, $error !== null),
            'error' => $error,
        ];
    }

    /**
     * Compact notification payload for /auth/status (admins only, cache only).
     *
     * @return ?array{latest_version: string, url: string, published_at: ?string}
     */
    public function cachedNotification(): ?array
    {
        $status = $this->status();
        if (!$status['enabled'] || !$status['update_available'] || $status['latest_version'] === null) {
            return null;
        }
        return [
            'latest_version' => $status['latest_version'],
            'url' => $status['url'] ?? 'https://github.com/' . self::repository() . '/releases',
            'published_at' => $status['published_at'],
        ];
    }

    /**
     * Checks GitHub Releases if the cache is stale (or when $force is true).
     * Never throws on network or GitHub errors; records the error in the status instead.
     *
     * @return array<string, mixed>
     */
    public function check(bool $force = false): array
    {
        $status = $this->status();
        if (!$status['enabled']) {
            return $status;
        }
        if (!$force && !$status['stale']) {
            return $status;
        }

        $repo = self::repository();
        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $previous = $this->readCache();
        $now = App::nowString();

        $response = $this->fetch($url);
        if ($response['status'] === 404) {
            // Repository has no published release yet.
            $this->writeCache([
                'repo' => $repo,
                'latest_version' => null,
                'url' => null,
                'published_at' => null,
                'checked_at' => $now,
                'error' => null,
            ]);
            return $this->status();
        }
        if ($response['status'] !== 200 || !is_string($response['body'])) {
            $reason = $response['status'] === 403 || $response['status'] === 429
                ? 'rate_limited'
                : 'unreachable';
            $this->writeCache([
                'repo' => $repo,
                'latest_version' => $previous['latest_version'] ?? null,
                'url' => $previous['url'] ?? null,
                'published_at' => $previous['published_at'] ?? null,
                'checked_at' => $now,
                'error' => $reason,
            ]);
            return $this->status();
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            $this->writeCache([
                'repo' => $repo,
                'latest_version' => $previous['latest_version'] ?? null,
                'url' => $previous['url'] ?? null,
                'published_at' => $previous['published_at'] ?? null,
                'checked_at' => $now,
                'error' => 'invalid_response',
            ]);
            return $this->status();
        }

        $version = self::normalizeVersion((string) ($data['tag_name'] ?? ''));
        if ($version === null) {
            $this->writeCache([
                'repo' => $repo,
                'latest_version' => $previous['latest_version'] ?? null,
                'url' => $previous['url'] ?? null,
                'published_at' => $previous['published_at'] ?? null,
                'checked_at' => $now,
                'error' => 'invalid_response',
            ]);
            return $this->status();
        }

        $htmlUrl = is_string($data['html_url'] ?? null) ? $data['html_url'] : '';
        if (!str_starts_with($htmlUrl, 'https://github.com/')) {
            $htmlUrl = 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode((string) $data['tag_name']);
        }
        $publishedAt = is_string($data['published_at'] ?? null) && $data['published_at'] !== ''
            ? substr($data['published_at'], 0, 10)
            : null;

        $this->writeCache([
            'repo' => $repo,
            'latest_version' => $version,
            'url' => $htmlUrl,
            'published_at' => $publishedAt,
            'checked_at' => $now,
            'error' => null,
        ]);
        return $this->status();
    }

    /** Strips a leading "v" and validates a numeric / semver tag. */
    public static function normalizeVersion(string $tag): ?string
    {
        $clean = ltrim(trim($tag), "vV");
        if ($clean === '' || preg_match('/^\d+(?:\.\d+){1,3}(?:-[0-9A-Za-z.-]+)?$/', $clean) !== 1) {
            return null;
        }
        return $clean;
    }

    public static function isNewer(string $latest, string $current): bool
    {
        $a = self::normalizeVersion($latest);
        $b = self::normalizeVersion($current);
        return $a !== null && $b !== null && version_compare($a, $b, '>');
    }

    private function current(): string
    {
        return $this->currentVersion ?? App::VERSION;
    }

    private function isStale(?string $checkedAt, bool $hadError): bool
    {
        if ($checkedAt === null || $checkedAt === '') {
            return true;
        }
        try {
            $then = new \DateTimeImmutable($checkedAt, App::timezone());
        } catch (\Throwable) {
            return true;
        }
        $age = App::now()->getTimestamp() - $then->getTimestamp();
        $ttl = $hadError ? self::ERROR_RETRY_SECONDS : self::TTL_SECONDS;
        return $age < 0 || $age >= $ttl;
    }

    /** @return array<string, mixed> */
    private function readCache(): array
    {
        $raw = $this->db->value('SELECT value FROM settings WHERE name = ?', [self::SETTING]);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        if (($decoded['repo'] ?? null) !== self::repository()) {
            return [];
        }
        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function writeCache(array $payload): void
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return;
        }
        $this->db->transaction(function () use ($json): void {
            $this->db->run('DELETE FROM settings WHERE name = ?', [self::SETTING]);
            $this->db->insert('settings', ['name' => self::SETTING, 'value' => $json]);
        });
    }

    /** @return array{status: int, body: ?string} */
    private function fetch(string $url): array
    {
        $handler = $this->httpGet ?? self::$defaultHttpGet;
        if ($handler !== null) {
            $result = $handler($url);
            return [
                'status' => (int) ($result['status'] ?? 0),
                'body' => is_string($result['body'] ?? null) ? $result['body'] : null,
            ];
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: ' . App::userAgent(),
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $body = '';
            $overflow = false;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$overflow): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        $overflow = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($overflow || $ok === false) {
                return ['status' => 0, 'body' => null];
            }
            return ['status' => $status, 'body' => $body];
        }

        $res = HttpStream::get($url, [
            'timeout' => self::TIMEOUT,
            'header' => implode("\r\n", $headers) . "\r\n",
        ], null, self::MAX_BYTES);
        return ['status' => $res['status'], 'body' => $res['body']];
    }
}
