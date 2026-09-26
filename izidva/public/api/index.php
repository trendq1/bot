<?php
/** API Telegram Mini App: /api/index.php/<путь> */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use App\Web\Api;

if (!App\Env::configured()) {
    Api::send(['detail' => 'Приложение не установлено — откройте migrate.php'], 503);
    exit;
}
Api::run(fn() => App\Web\MiniApi::handle());
