<?php
/**
 * Торговый демон — постоянный процесс (systemd). Запуск вручную: php bin/daemon.php
 * Код выхода 3 = запрошен перезапуск (systemd с Restart=always поднимет процесс снова).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Env;
use App\Log;

if (PHP_SAPI !== 'cli') {
    exit("Только для командной строки\n");
}
if (!Env::configured()) {
    Log::warn('Приложение не установлено — откройте migrate.php в браузере. Повтор через 30 секунд.');
    sleep(30);
    exit(1);
}

$lock = fopen(STORAGE_DIR . '/daemon.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    Log::warn('Демон уже запущен (storage/daemon.lock)');
    exit(0);
}

set_time_limit(0);
ini_set('memory_limit', '512M');
exit((new App\Engine\Manager())->run());
