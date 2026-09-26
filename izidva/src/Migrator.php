<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/** Миграции БД: файлы migrations/NNN_name.sql применяются по порядку один раз. */
final class Migrator
{
    public static function files(): array
    {
        $files = glob(BASE_DIR . '/migrations/*.sql') ?: [];
        sort($files);
        return $files;
    }

    public static function pending(PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(128) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $done = array_flip($pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter(self::files(), fn($f) => !isset($done[basename($f)])));
    }

    /** @return string[] применённые миграции */
    public static function run(PDO $pdo): array
    {
        $applied = [];
        foreach (self::pending($pdo) as $file) {
            $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($file));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // повторный запуск после частичного сбоя: таблица/индекс уже есть — не ошибка
                    if (!in_array((int)($e->errorInfo[1] ?? 0), [1050, 1061], true)) {
                        throw new \RuntimeException(basename($file) . ': ' . $e->getMessage(), 0, $e);
                    }
                }
            }
            $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)')->execute([basename($file), gmdate('Y-m-d H:i:s')]);
            $applied[] = basename($file);
        }
        return $applied;
    }
}
