<?php

declare(strict_types=1);

namespace BookSpot;

final class Cron
{
    private const SETTING = 'cron_token';

    /** @return array<string, mixed> */
    public static function run(): array
    {
        $db = App::db();
        $result = [
            'started_at' => App::nowString(),
            'reminders' => (new ReminderService($db, new PushService($db)))->run(),
        ];
        if (Config::bool('METADATA_LOOKUP', true) && Config::bool('CRON_ENRICH_METADATA', true)) {
            $result['metadata'] = (new MetadataService($db))->enrichBatch(Config::int('CRON_ENRICH_BATCH', 20), 60.0);
        }
        $db->run('DELETE FROM login_attempts WHERE attempted_at < ?', [App::now()->modify('-1 day')->format('Y-m-d H:i:s')]);
        $db->run('DELETE FROM notification_log WHERE due_date < ?', [App::now()->modify('-90 days')->format('Y-m-d')]);
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
