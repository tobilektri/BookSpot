<?php

declare(strict_types=1);

namespace BookSpot;

final class Cron
{
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
}
