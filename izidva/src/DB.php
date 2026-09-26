<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;
use PDOStatement;

/** Тонкая обёртка над PDO (MySQL / MariaDB). */
final class DB
{
    private static ?PDO $pdo = null;

    public static function connect(string $host, int $port, string $name, string $user, string $pass): PDO
    {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'",
        ]);
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect(Env::get('DB_HOST', 'localhost'), (int)Env::get('DB_PORT', '3306'),
                Env::get('DB_NAME'), Env::get('DB_USER'), Env::get('DB_PASS'));
        }
        return self::$pdo;
    }

    /** Для долгоживущего демона: MySQL закрывает простаивающие соединения. */
    public static function ping(): void
    {
        try {
            self::pdo()->query('SELECT 1');
        } catch (PDOException) {
            self::$pdo = null;
            self::pdo();
        }
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_map(fn($c) => ':' . $c, $cols)));
        self::q($sql, self::bind($data));
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(',', array_map(fn($c) => "`$c` = :$c", array_keys($data)));
        return self::q("UPDATE $table SET $set WHERE $where", self::bind($data) + $params)->rowCount();
    }

    private static function bind(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $out[':' . $k] = is_bool($v) ? (int)$v : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v);
        }
        return $out;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
