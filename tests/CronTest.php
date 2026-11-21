<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\Config;
use BookSpot\Cron;

final class CronTest extends TestCase
{
    public function testASecondRunWhileOneIsActiveIsSkipped(): void
    {
        $lock = fopen(App::varDir() . '/cron.lock', 'c');
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            self::assertSame('already_running', Cron::run()['skipped'] ?? null);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertArrayNotHasKey('skipped', Cron::run(), 'runs again once the lock is free');
    }

    public function testHousekeepingRunsEvenWithoutTimeForTheLookups(): void
    {
        $this->db->insert('login_attempts', ['ip' => '1.2.3.4', 'username' => 'x', 'attempted_at' => '2026-10-01 10:00:00']);
        Config::set('CRON_MAX_SECONDS', '10');
        $now = 1_000_000.0;
        // Every look at the clock: 30 s later – the deadline is gone before the first lookup phase.
        $clock = function () use (&$now): float {
            $now += 30;
            return $now;
        };
        try {
            $result = Cron::run($clock);
        } finally {
            Config::set('CRON_MAX_SECONDS', null);
        }
        self::assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM login_attempts'), 'clean-up done first');
        self::assertArrayHasKey('reminders', $result);
        foreach (['queue', 'dnb_titles', 'covers'] as $phase) {
            self::assertSame(['skipped' => 'no_time_left'], $result[$phase], $phase);
        }
    }

    public function testThePhaseThatGoesFirstRotates(): void
    {
        $first = [];
        foreach ([0, 600, 1200] as $start) {
            $now = 2_000_400.0 + $start; // 2_000_400 / 600 is a whole number
            $clock = function () use (&$now): float {
                return $now;
            };
            $result = Cron::run($clock);
            $heavy = array_values(array_intersect(array_keys($result), ['queue', 'metadata', 'dnb_titles', 'covers']));
            $first[] = $heavy[0];
        }
        self::assertCount(3, array_unique($first), 'a different phase starts every 10 minutes: ' . implode(', ', $first));
    }
}
