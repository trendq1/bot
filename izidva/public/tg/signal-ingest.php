<?php
/**
 * Внешний приём постов канала (userbot / скрипт-ридер). POST JSON {"channel_id": "-1001234567890", "channel_name": "…", "text": "…"}
 * Заголовок X-Signal-Token — токен из меню «Сигналы» в админке. Неизвестный канал создаётся выключенным.
 */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use App\Log;
use App\Settings;
use App\Signals;

header('Content-Type: application/json; charset=utf-8');
if (!App\Env::configured() || !Settings::get('signal_enabled') || !hash_equals(Signals::ingestToken(), $_SERVER['HTTP_X_SIGNAL_TOKEN'] ?? '')) {
    http_response_code(403);
    echo '{"ok":false}';
    exit;
}
$in = json_decode((string)file_get_contents('php://input'), true);
$text = is_array($in) ? trim((string)($in['text'] ?? '')) : '';
$cid = is_array($in) ? trim((string)($in['channel_id'] ?? '')) : '';
if ($text === '' || $cid === '' || mb_strlen($cid) > 50) {
    http_response_code(400);
    echo '{"ok":false,"error":"нужны channel_id и text"}';
    exit;
}
try {
    $r = Signals::ingest($cid, mb_substr((string)($in['channel_name'] ?? ''), 0, 120), $text);
    echo json_encode(['ok' => true] + $r, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    Log::error('signal-ingest: ' . $e->getMessage());
    http_response_code(500);
    echo '{"ok":false}';
}
