<?php

declare(strict_types=1);

namespace Aicountly\Api;

use PDO;
use PDOException;
use PDOStatement;

/**
 * MySQL connection for Messaging's own database.
 *
 * This points at cPanel's shared MySQL — the same account's "MySQL Databases"
 * tool, not a dedicated server. cPanel prefixes both the database and the user
 * with the account name (an entry of `app` becomes `<cpaneluser>_app`); use the
 * full prefixed names. See docs/DEPLOYMENT.md.
 *
 * Nothing in this product reads from it yet — every existing route
 * (/api/health, /api/global/*, /api/session) is portal-relay only. This class
 * exists so the first feature that needs to persist something has a
 * connection ready, instead of adding this plumbing under deadline.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME');
        $user = Env::get('DB_USER');
        $pass = Env::get('DB_PASS');

        if ($name === '' || $user === '') {
            throw new PDOException('Database is not configured (DB_NAME / DB_USER missing from api/.env).');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);

        return self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepares, not client-side emulation — see the sibling note
            // in Pay's Db.php for why that matters for numeric columns.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /** @param array<string|int, mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string|int, mixed> $params */
    public static function scalar(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * MySQL has no RETURNING clause, so the generated key comes from
     * lastInsertId() on the same connection instead.
     *
     * @param array<string, mixed> $values
     */
    public static function insert(string $table, array $values): string|false
    {
        $columns = array_keys($values);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            self::quoteIdentifier($table),
            implode(', ', array_map([self::class, 'quoteIdentifier'], $columns)),
            implode(', ', array_map(static fn (string $c) => ':' . $c, $columns)),
        );

        $pdo = self::connect();
        self::run($sql, self::bindable($values));

        return $pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     */
    public static function update(string $table, array $values, array $where): int
    {
        if ($values === []) {
            return 0;
        }
        $set = implode(', ', array_map(
            static fn (string $c) => self::quoteIdentifier($c) . ' = :set_' . $c,
            array_keys($values),
        ));
        $conds = implode(' AND ', array_map(
            static fn (string $c) => self::quoteIdentifier($c) . ' = :where_' . $c,
            array_keys($where),
        ));

        $params = [];
        foreach (self::bindable($values) as $k => $v) {
            $params['set_' . $k] = $v;
        }
        foreach (self::bindable($where) as $k => $v) {
            $params['where_' . $k] = $v;
        }

        return self::run(
            sprintf('UPDATE %s SET %s WHERE %s', self::quoteIdentifier($table), $set, $conds),
            $params,
        )->rowCount();
    }

    /**
     * Run $work inside one transaction, rolling back on any throwable.
     *
     * Nested calls join the outer transaction rather than opening a second
     * one — MySQL has no nested BEGIN. Note that DDL (CREATE TABLE and
     * friends) causes an implicit commit in MySQL/InnoDB, so a transaction
     * wrapped around DDL cannot actually be rolled back; this only gives full
     * atomicity for DML.
     *
     * @template T
     * @param callable(PDO): T $work
     * @return T
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::connect();
        if ($pdo->inTransaction()) {
            return $work($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Arrays are stored as JSON; booleans as 0/1 — MySQL has no boolean type.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function bindable(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $out[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_bool($value)) {
                $out[$key] = (int) $value;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Identifiers come from this codebase, never from a request; the check is a guard against a future typo. */
    public static function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new PDOException('Refusing to use "' . $name . '" as an SQL identifier.');
        }

        return '`' . $name . '`';
    }

    /** Decode a JSON column that PDO hands back as a string. */
    public static function jsonColumn(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
