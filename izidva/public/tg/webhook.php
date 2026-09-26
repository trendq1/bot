<?php
/** Webhook Telegram-бота. Адрес регистрируется автоматически при сохранении токена в админ-панели. */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use App\Log;
use App\Telegram;

http_response_code(200);
if (!App\Env::configured() || !hash_equals(Telegram::webhookSecret(), $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(403);
    exit;
}
$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) {
    exit;
}
try {
    App\Web\Webhook::handle($update);
} catch (Throwable $e) {
    Log::error('webhook: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
echo 'ok';
