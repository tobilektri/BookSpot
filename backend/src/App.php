<?php

declare(strict_types=1);

namespace BookSpot;

/**
 * Application root: paths, clock and the lazily created database connection.
 */
final class App
{
    public const VERSION = '1.0.0';

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
     * Runs migrations when needed. A marker file in var/ avoids hitting the
     * migrations table on every request once the schema is current.
     */
    public static function ensureSchema(Database $db): void
    {
        $migrationsDir = self::root() . '/backend/migrations';
        $files = glob($migrationsDir . '/*/*.sql') ?: [];
        $latest = $files === [] ? '' : max(array_map(fn ($f) => basename($f, '.sql'), $files));
        $marker = self::varDir() . '/schema-' . $db->driver() . '-' . substr(sha1((string) Config::get('DB_NAME') . Config::get('DB_SQLITE_PATH')), 0, 8);
        if (is_file($marker) && trim((string) @file_get_contents($marker)) === $latest) {
            return;
        }
        $db->migrate($migrationsDir);
        @file_put_contents($marker, $latest);
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
