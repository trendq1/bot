<?php
declare(strict_types=1);

namespace App;

/**
 * Базовые настройки из storage/.env: подключение к БД и ключи шифрования.
 * Файл создаёт migrate.php. Всё остальное хранится в БД и редактируется в админ-панели.
 */
final class Env
{
    private static array $values = [];

    public static function load(): void
    {
        self::$values = [];
        if (!is_file(ENV_FILE)) {
            return;
        }
        foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            self::$values[trim($k)] = trim($v, " \t\"'");
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? (getenv($key) !== false ? (string)getenv($key) : $default);
    }

    public static function configured(): bool
    {
        return self::get('DB_NAME') !== '' && self::get('APP_KEY') !== '' && self::get('SECRET_KEY') !== '';
    }

    /** @param array<string,string> $values */
    public static function write(array $values): void
    {
        $current = self::$values;
        foreach ($values as $k => $v) {
            $current[$k] = $v;
        }
        $out = "# Создано migrate.php. Не удаляйте: без APP_KEY не расшифровать сохранённые ключи.\n";
        foreach ($current as $k => $v) {
            if (preg_match('/[\s#"\'\\\\]/', $v)) {
                $v = '"' . str_replace('"', '', $v) . '"';
            }
            $out .= "$k=$v\n";
        }
        if (!is_dir(STORAGE_DIR)) {
            mkdir(STORAGE_DIR, 0750, true);
        }
        file_put_contents(ENV_FILE, $out, LOCK_EX);
        @chmod(ENV_FILE, 0600);
        self::load();
    }
}
