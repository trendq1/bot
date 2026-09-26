<?php
/**
 * Общая загрузка для веб-части и демона.
 * Требования: PHP 8.1+, расширения pdo_mysql, curl, openssl, mbstring, json.
 */
declare(strict_types=1);

const BASE_DIR = __DIR__ . '/..';
const STORAGE_DIR = BASE_DIR . '/storage';
const ENV_FILE = STORAGE_DIR . '/.env';
const RESTART_CODE = 3;

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

require BASE_DIR . '/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

App\Env::load();
