<?php

declare(strict_types=1);

namespace BookSpot;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Every query goes through prepared statements.
 * Works with MySQL/MariaDB and SQLite (fallback for tiny installs and tests).
 */
final class Database
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($this->driver() === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    public static function fromConfig(string $rootDir): self
    {
        $driver = Config::get('DB_DRIVER', 'mysql');
        if ($driver === 'sqlite') {
            $path = Config::get('DB_SQLITE_PATH', 'var/bookspot.sqlite');
            if ($path !== ':memory:' && !str_starts_with($path, '/')) {
                $path = $rootDir . '/' . $path;
            }
            return new self(new PDO('sqlite:' . $path));
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', 'localhost'),
            Config::int('DB_PORT', 3306),
            Config::get('DB_NAME', 'bookspot'),
        );
        // Emulated prepares (PDO default) allow re-using a named placeholder several
        // times in one statement; values are still escaped by the driver using utf8mb4.
        $pdo = new PDO($dsn, Config::get('DB_USER', 'root'), Config::get('DB_PASS', ''));
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @param array<int|string, mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::ident($table),
            implode(', ', array_map(self::ident(...), $columns)),
            implode(', ', array_map(fn ($c) => ':' . $c, $columns)),
        );
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where column => value (AND-combined equality)
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            return 0;
        }
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = self::ident($column) . ' = :s_' . $column;
            $params['s_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[] = self::ident($column) . ' = :w_' . $column;
            $params['w_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', self::ident($table), implode(', ', $set), implode(' AND ', $conditions));
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Column/table names are never user-controlled, but we still whitelist characters. */
    public static function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Invalid identifier: $name");
        }
        return $name;
    }

    /** Builds a LIKE pattern; use together with `LIKE :param ESCAPE '!'` (portable across MySQL/SQLite). */
    public static function likePattern(string $term): string
    {
        return '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }

    /** Applies pending migrations from backend/migrations/<driver>/. Returns applied names. */
    public function migrate(string $migrationsDir): array
    {
        $driver = $this->driver() === 'sqlite' ? 'sqlite' : 'mysql';
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(30) NOT NULL)'
        );
        $done = array_column($this->all('SELECT version FROM schema_migrations'), 'version');
        $files = glob($migrationsDir . '/' . $driver . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            // MySQL DDL auto-commits, so we run statement by statement without a transaction.
            foreach (self::splitSql($sql) as $statement) {
                $this->pdo->exec($statement);
            }
            $this->insert('schema_migrations', ['version' => $version, 'applied_at' => gmdate('Y-m-d H:i:s')]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return list<string> */
    public static function splitSql(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql . "\n")));
        return array_values(array_filter($parts, fn ($p) => $p !== '' && $p !== ';'));
    }
}
