<?php
declare(strict_types=1);

namespace App;

final class Log
{
    private const MAX = 5_000_000;

    public static function file(): string
    {
        return STORAGE_DIR . '/logs/app.log';
    }

    public static function write(string $level, string $msg): void
    {
        $f = self::file();
        if (!is_dir(dirname($f))) {
            @mkdir(dirname($f), 0750, true);
        }
        if (is_file($f) && filesize($f) > self::MAX) {
            @rename($f, $f . '.1');
        }
        $line = gmdate('Y-m-d H:i:s') . " $level $msg\n";
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
        if (PHP_SAPI === 'cli' && !defined('APP_TESTING')) {
            fwrite(STDOUT, $line);
        }
    }

    public static function info(string $m): void { self::write('INFO', $m); }
    public static function warn(string $m): void { self::write('WARN', $m); }
    public static function error(string $m): void { self::write('ERROR', $m); }
}
