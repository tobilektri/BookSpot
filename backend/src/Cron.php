<?php

declare(strict_types=1);

namespace BookSpot;

final class Cron
{
    private const SETTING = 'cron_token';

    /** Below this many seconds a phase is not started any more. */
    private const MIN_PHASE_SECONDS = 5.0;

    /**
     * One cron run. Cheap housekeeping comes first, so a run killed by a hoster's
     * time limit never loses it; the lookups that take time share one deadline
     * (CRON_MAX_SECONDS, default 100 – the web cron allows 120) and start in a
     * rotating order, so covers are not always the ones left without time.
     * Overlapping runs (slow run + next call) are skipped via a file lock.
     *
     * @param (callable(): float)|null $clock seconds (tests)
     * @return array<string, mixed>
     */
    public static function run(?callable $clock = null): array
    {
        $clock ??= static fn (): float => microtime(true);
        $lock = @fopen(App::varDir() . '/cron.lock', 'c');
        if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['started_at' => App::nowString(), 'skipped' => 'already_running'];
        }
        try {
            return self::phases(App::db(), $clock);
        } finally {
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function phases(Database $db, callable $clock): array
    {
        $deadline = $clock() + max(10, Config::int('CRON_MAX_SECONDS', 100));
        $result = [
            'started_at' => App::nowString(),
            'reminders' => (new ReminderService($db, new PushService($db)))->run(),
        ];
        $batch = new BatchImporter($db);
        $batch->cleanup();
        $db->run('DELETE FROM login_attempts WHERE attempted_at < ?', [App::now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        $db->run('DELETE FROM notification_log WHERE due_date < ?', [App::now()->modify('-90 days')->format('Y-m-d')]);
        RateLimiter::cleanup();
        if (UpdateService::enabled()) {
            $result['update'] = (new UpdateService($db))->check(); // cached for 24 h
        }

        $heavy = [
            // Bulk scan placeholders: someone is waiting for those titles.
            'queue' => fn (float $seconds) => $batch->process(Config::int('CRON_QUEUE_BATCH', 30), min(50.0, $seconds), 5.0),
            'metadata' => Config::bool('METADATA_LOOKUP', true) && Config::bool('CRON_ENRICH_METADATA', true)
                ? fn (float $seconds) => (new MetadataService($db))->enrichBatch(Config::int('CRON_ENRICH_BATCH', 20), min(60.0, $seconds))
                : null,
            // One-time after updating to 1.7.3: titles read from the DNB by older versions.
            'dnb_titles' => fn (float $seconds) => (new DnbTitleRepair($db, new MetadataService($db)))->run(30, min(30.0, $seconds)),
            // Download covers ahead of time (Open Library allows ~100 ISBN lookups per 5 minutes).
            'covers' => function (float $seconds) use ($db) {
                $covers = new CoverService($db);
                $covers->maxWait = 5.0;
                $covers->retryWhenSourcesChanged();
                return $covers->prefetchBatch(Config::int('CRON_COVER_BATCH', 40), min(60.0, $seconds));
            },
        ];
        $heavy = array_filter($heavy);
        $names = array_keys($heavy);
        $offset = intdiv((int) $clock(), 600) % count($names); // a different phase goes first every 10 minutes
        $order = array_merge(array_slice($names, $offset), array_slice($names, 0, $offset));
        foreach ($order as $name) {
            $left = $deadline - $clock();
            if ($left < self::MIN_PHASE_SECONDS) {
                $result[$name] = ['skipped' => 'no_time_left'];
                continue;
            }
            $result[$name] = $heavy[$name]($left);
        }
        return $result;
    }

    /**
     * Token for the web cron URL. CRON_TOKEN from .env wins; without it a random
     * token is generated once and stored in the settings table (shown to admins
     * in the web interface).
     */
    public static function token(Database $db): string
    {
        $configured = (string) Config::get('CRON_TOKEN', '');
        if ($configured !== '') {
            if (strlen($configured) < 16) {
                throw new ApiException('cron_token_too_short', 503);
            }
            return $configured;
        }
        $stored = $db->value('SELECT value FROM settings WHERE name = ?', [self::SETTING]);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        return self::regenerateToken($db);
    }

    public static function tokenFromConfig(): bool
    {
        return (string) Config::get('CRON_TOKEN', '') !== '';
    }

    /** Creates a new random token (invalidates the old cron URL). */
    public static function regenerateToken(Database $db): string
    {
        if (self::tokenFromConfig()) {
            throw new ApiException('cron_token_from_config', 409);
        }
        $token = bin2hex(random_bytes(24));
        $db->transaction(function () use ($db, $token) {
            $db->run('DELETE FROM settings WHERE name = ?', [self::SETTING]);
            $db->insert('settings', ['name' => self::SETTING, 'value' => $token]);
        });
        return $token;
    }
}
