<?php
/** IPN-вебхук NOWPayments: подтверждение оплаты подписки криптовалютой. */
declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use App\DB;
use App\Log;
use App\NowPayments;
use App\Settings;
use App\Telegram;

http_response_code(200);                          // NOWPayments повторяет запрос, пока не получит 200

$raw = (string)file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] ?? null;
if (!NowPayments::verifySignature($raw, $sig)) {
    Log::warn('NOWPayments webhook: неверная или отсутствующая подпись');
    http_response_code(403);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data) || empty($data['order_id'])) {
    exit;
}
$orderId = (string)$data['order_id'];
$status = (string)($data['payment_status'] ?? '');

$inv = DB::row('SELECT * FROM crypto_invoices WHERE order_id = ?', [$orderId]);
if (!$inv) {
    Log::warn("NOWPayments webhook: неизвестный order_id $orderId");
    exit;
}

DB::update('crypto_invoices', [
    'status' => $status ?: $inv['status'],
    'invoice_id' => (string)($data['invoice_id'] ?? $data['payment_id'] ?? $inv['invoice_id']),
    'pay_currency' => (string)($data['pay_currency'] ?? $inv['pay_currency']),
    'actually_paid' => isset($data['actually_paid']) ? (float)$data['actually_paid'] : $inv['actually_paid'],
    'updated_at' => DB::now(),
], 'order_id = :o', [':o' => $orderId]);

if ($status !== 'finished') {
    exit;                                          // waiting/confirming/confirmed/... — ждём финального статуса
}
$plan = Settings::plan((string)$inv['plan']);
if (!$plan) {
    exit;
}

$pdo = DB::pdo();
$pdo->beginTransaction();
try {
    $chargeId = 'np:' . (string)($data['payment_id'] ?? $orderId);
    if (DB::val('SELECT 1 FROM payments WHERE charge_id = ?', [$chargeId])) {
        $pdo->rollBack();
        exit;                                      // этот платёж уже начислен — IPN мог прийти повторно
    }
    DB::insert('payments', ['user_id' => (int)$inv['user_id'], 'plan' => $plan['code'], 'stars' => 0, 'method' => 'crypto',
        'usd' => round((float)$inv['price_amount'], 2), 'charge_id' => $chargeId, 'created_at' => DB::now()]);
    $cur = DB::val('SELECT sub_until FROM users WHERE id = ? FOR UPDATE', [(int)$inv['user_id']]);
    $base = $cur && strtotime($cur . ' UTC') > time() ? strtotime($cur . ' UTC') : time();
    $until = $base + $plan['days'] * 86400;
    DB::update('users', ['sub_until' => gmdate('Y-m-d H:i:s', $until)], 'id = :id', [':id' => (int)$inv['user_id']]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    Log::error('NOWPayments webhook: ' . $e->getMessage());
    exit;
}

Telegram::send((int)$inv['user_id'], '✅ Оплата криптовалютой получена. Подписка активна до ' . gmdate('d.m.Y', $until)
    . '. Подключите Bybit в приложении и включите торговлю на бирже.', Telegram::appButton());
