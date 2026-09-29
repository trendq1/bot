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
// Новые миграции применяются сами при старте: код, который ждёт новых колонок, не должен падать в цикл
// перезапусков из-за того, что после деплоя забыли открыть migrate.php (так было с миграцией 008).
try {
    foreach (App\Migrator::run(App\DB::pdo()) as $m) {
        Log::info("Миграция применена: $m");
    }
} catch (\Throwable $e) {
    Log::error('Миграции не применились: ' . $e->getMessage() . ' — повтор через 30 секунд');
    sleep(30);
    exit(1);
}
exit((new App\Engine\Manager())->run());
