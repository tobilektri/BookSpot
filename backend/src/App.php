<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Application root: paths, clock and the lazily created database connection.
 */
final class App
{
    public const VERSION = '1.5.0';

    private static ?Database $db = null;
    private static ?string $fixedNow = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function varDir(): string
    {
        $dir = self::root() . '/var';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function db(): Database
    {
        if (self::$db === null) {
            self::$db = Database::fromConfig(self::root());
            self::ensureSchema(self::$db);
        }
        return self::$db;
    }

    public static function setDb(?Database $db): void
    {
        self::$db = $db;
    }

    /**
     * Runs migrations when needed. Checked against the database itself on every
     * request (one primary-key lookup), so an emptied or dropped database is
     * recreated automatically and the app starts with the first-run setup again.
     */
    public static function ensureSchema(Database $db): void
    {
        $migrationsDir = self::root() . '/backend/migrations';
        $files = glob($migrationsDir . '/*/*.sql') ?: [];
        if ($files === []) {
            return;
        }
        $latest = max(array_map(fn ($f) => basename($f, '.sql'), $files));
        try {
            $current = $db->value('SELECT version FROM schema_migrations WHERE version = ?', [$latest]) !== null;
        } catch (\PDOException) {
            $current = false; // schema_migrations missing: fresh or dropped database
        }
        if ($current) {
            try {
                $db->run('SELECT 1 FROM users LIMIT 1');
                return;
            } catch (\PDOException) {
                // Data tables were dropped but the migration log survived: replay everything.
                // Migrations tolerate objects that already exist (Database::migrate()).
                $db->run('DELETE FROM schema_migrations');
            }
        }
        $db->migrate($migrationsDir);
    }

    public static function timezone(): \DateTimeZone
    {
        return new \DateTimeZone(Config::get('APP_TIMEZONE', 'Europe/Berlin'));
    }

    /** Freeze the clock (tests). Format: Y-m-d H:i:s */
    public static function setNow(?string $now): void
    {
        self::$fixedNow = $now;
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::$fixedNow ?? 'now', self::timezone());
    }

    public static function nowString(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }
}
