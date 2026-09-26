<?php
declare(strict_types=1);

namespace App\Web;

use App\DB;
use App\Log;
use App\Settings;
use App\Telegram;

/** Обработка обновлений Telegram (webhook): /start, оплата подписки звёздами. */
final class Webhook
{
    public static function handle(array $u): void
    {
        if (isset($u['pre_checkout_query'])) {
            $q = $u['pre_checkout_query'];
            $parts = explode(':', (string)$q['invoice_payload']);
            $ok = count($parts) === 3 && $parts[0] === 'sub' && $parts[2] === (string)$q['from']['id'] && Settings::plan($parts[1]);
            Telegram::call('answerPreCheckoutQuery', ['pre_checkout_query_id' => $q['id'], 'ok' => (bool)$ok]
                + ($ok ? [] : ['error_message' => 'Счёт устарел, создайте новый в приложении']));
            return;
        }
        $m = $u['message'] ?? null;
        if (!$m) {
            return;
        }
        $from = $m['from'] ?? [];
        if (isset($m['successful_payment'])) {
            self::paid($m['successful_payment'], (int)$from['id']);
            return;
        }
        $text = trim((string)($m['text'] ?? ''));
        if (str_starts_with($text, '/start') || $text === '/app') {
            $user = MiniApi::ensureUser((int)$from['id'], $from['username'] ?? null, $from['first_name'] ?? null);
            if ($user['blocked']) {
                Telegram::send($m['chat']['id'], 'Доступ заблокирован. Обратитесь в поддержку.');
                return;
            }
            Telegram::send($m['chat']['id'],
                "👋 <b>AI Trader для Bybit Futures</b>\n\n"
                . "Бот торгует фьючерсами за тебя: сетка для боковика, входы по тренду и отскоки после ликвидаций. "
                . "ИИ регулярно анализирует рынок и распределяет капитал, а бот учится на результатах сделок.\n\n"
                . "• Бесплатно: демо-торговля на виртуальном счёте\n• По подписке: торговля на твоём аккаунте Bybit\n\n"
                . '⚠️ Торговля с плечом рискованна. Прошлые результаты не гарантируют будущих.',
                Telegram::homeKeyboard());
            return;
        }
        if ($text === '/menu') {
            $markup = Telegram::homeKeyboard();
            Telegram::send($m['chat']['id'], '📋 Меню', $markup ?: null);
        }
    }

    private static function paid(array $sp, int $fromId): void
    {
        [, $code, $uid] = array_pad(explode(':', (string)$sp['invoice_payload']), 3, '');
        $plan = Settings::plan($code);
        if (!$plan || (int)$uid !== $fromId) {
            Log::warn("Оплата с неизвестным payload: {$sp['invoice_payload']}");
            return;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if (DB::val('SELECT 1 FROM payments WHERE charge_id = ?', [$sp['telegram_payment_charge_id']])) {
                $pdo->rollBack();
                return;                                     // этот платёж уже учтён
            }
            DB::insert('payments', ['user_id' => (int)$uid, 'plan' => $plan['code'], 'stars' => (int)$sp['total_amount'],
                'usd' => round($sp['total_amount'] * Settings::get('stars_usd_rate'), 2), 'charge_id' => $sp['telegram_payment_charge_id'],
                'created_at' => DB::now()]);
            $cur = DB::val('SELECT sub_until FROM users WHERE id = ? FOR UPDATE', [(int)$uid]);
            $base = $cur && strtotime($cur . ' UTC') > time() ? strtotime($cur . ' UTC') : time();
            $until = $base + $plan['days'] * 86400;
            DB::update('users', ['sub_until' => gmdate('Y-m-d H:i:s', $until)], 'id = :id', [':id' => (int)$uid]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Telegram::send((int)$uid, '✅ Подписка активна до ' . gmdate('d.m.Y', $until)
            . '. Подключите Bybit в приложении и включите торговлю на бирже.', Telegram::appButton());
    }
}
