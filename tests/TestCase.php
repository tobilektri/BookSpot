<?php

declare(strict_types=1);

namespace BookSpot\Tests;

use BookSpot\App;
use BookSpot\Auth;
use BookSpot\Database;
use PDO;

/**
 * Runs every test against a fresh schema. SQLite in-memory by default; set
 * TEST_DB_DSN (+ TEST_DB_USER / TEST_DB_PASS) to run against MariaDB/MySQL,
 * e.g. TEST_DB_DSN="mysql:host=127.0.0.1;dbname=bookspot_test;charset=utf8mb4".
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        App::setNow('2026-10-09 12:00:00');
        $dsn = getenv('TEST_DB_DSN') ?: 'sqlite::memory:';
        $pdo = new PDO($dsn, getenv('TEST_DB_USER') ?: null, getenv('TEST_DB_PASS') ?: null);
        if (str_starts_with($dsn, 'mysql:')) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $pdo->exec("DROP TABLE `$table`");
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        $this->db = new Database($pdo);
        $this->db->migrate(App::root() . '/backend/migrations');
        App::setDb($this->db);
    }

    protected function tearDown(): void
    {
        App::setNow(null);
        App::setDb(null);
        Auth::setUser(null);
    }

    /** @return array<string, mixed> */
    protected function createUser(string $name = 'anna'): array
    {
        return (new Auth($this->db))->register($name, $name . '@example.org', 'secret-password', 'de', force: true);
    }
}
