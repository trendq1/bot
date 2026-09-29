<?php
declare(strict_types=1);

namespace App\Web;

use App\DB;
use App\Log;
use App\Referral;
use App\Settings;
use App\SignalParser;
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
        $m = $u['message'] ?? $u['channel_post'] ?? null;
        if (!$m) {
            return;
        }
        if (self::isSignalSource($m)) {
            self::handleSignal($m);
            return;
        }
        $from = $m['from'] ?? [];
        if (isset($m['successful_payment'])) {
            self::paid($m['successful_payment'], (int)$from['id']);
            return;
        }
        $text = trim((string)($m['text'] ?? ''));
        if (str_starts_with($text, '/start') || $text === '/app') {
            $isNew = !DB::val('SELECT 1 FROM users WHERE id = ?', [(int)$from['id']]);
            $user = MiniApi::ensureUser((int)$from['id'], $from['username'] ?? null, $from['first_name'] ?? null);
            if ($user['blocked']) {
                Telegram::send($m['chat']['id'], 'Доступ заблокирован. Обратитесь в поддержку.');
                return;
            }
            if ($isNew) {
                $arg = trim(substr($text, 6));                        // "/start ref_123" -> "ref_123"
                if (preg_match('/^ref_(\d+)$/', $arg, $mm)) {
                    Referral::attach((int)$from['id'], (int)$mm[1]);
                }
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

    /** Сообщение из разрешённого источника сигналов: личка от вашего Telegram ID или пост разрешённого канала. */
    private static function isSignalSource(array $m): bool
    {
        if (!Settings::get('signal_enabled')) {
            return false;
        }
        $allowed = Settings::get('signal_allowed_ids');
        $chat = $m['chat'] ?? [];
        if (($chat['type'] ?? '') === 'channel') {
            return in_array((string)($chat['id'] ?? ''), $allowed, true);
        }
        if (($chat['type'] ?? '') === 'private') {
            $text = trim((string)($m['text'] ?? $m['caption'] ?? ''));
            return in_array((string)($m['from']['id'] ?? ''), $allowed, true) && !str_starts_with($text, '/');
        }
        return false;
    }

    /** Разбор и постановка сигнала в очередь; исполнение клиентам — в демоне (Manager::processSignals). */
    private static function handleSignal(array $m): void
    {
        $chat = $m['chat'];
        $private = ($chat['type'] ?? '') === 'private';
        $reply = $private ? (int)$chat['id'] : null;
        $say = fn(string $t) => $reply ? Telegram::send($reply, $t) : null;
        $text = trim((string)($m['text'] ?? $m['caption'] ?? ''));
        $s = SignalParser::parse($text);
        if ($s === null) {
            $say('❔ Не распознал сигнал: нужны #МОНЕТА/USDT, ЛОНГ/ШОРТ, диапазон входа, цели и СТОП ЛОСС. Ничего не открыто.');
            if (!$private) {
                Log::info('signal: сообщение канала не похоже на сигнал, пропущено');
            }
            return;
        }
        $source = $private ? 'user:' . (int)($m['from']['id'] ?? 0) : 'channel:' . (int)$chat['id'];
        $row = ['symbol' => $s['symbol'], 'side' => $s['side'], 'entry_lo' => $s['entry_lo'], 'entry_hi' => $s['entry_hi'], 'stop_loss' => $s['stop'],
            'targets' => $s['targets'], 'channel_leverage' => $s['leverage'], 'raw_text' => mb_substr($text, 0, 2000), 'source' => $source,
            'reply_chat' => $reply, 'created_at' => DB::now()];
        $error = SignalParser::validate($s);
        if ($error !== null) {
            DB::insert('signals', $row + ['status' => 'rejected', 'summary' => $error]);
            $say("⚠️ Сигнал {$s['symbol']} отклонён: $error. Ничего не открыто.");
            return;
        }
        $mid = ($s['entry_lo'] + $s['entry_hi']) / 2;
        $dupe = DB::val("SELECT id FROM signals WHERE symbol = ? AND side = ? AND status IN ('new','processed') AND created_at > ?
            AND ABS((entry_lo + entry_hi) / 2 - ?) / ? < 0.015 LIMIT 1", [$s['symbol'], $s['side'], gmdate('Y-m-d H:i:s', time() - 12 * 3600), $mid, $mid]);
        if ($dupe) {
            DB::insert('signals', $row + ['status' => 'duplicate', 'summary' => "дубль сигнала #$dupe"]);
            $say("♻️ {$s['symbol']}: дубль сигнала #$dupe (тот же вход за последние 12 часов) — второй раз не открываю.");
            return;
        }
        $id = DB::insert('signals', $row + ['status' => 'new']);
        $say("📡 Сигнал #$id принят: {$s['symbol']} " . ($s['side'] === 'Buy' ? 'LONG' : 'SHORT') . ", вход {$s['entry_lo']}–{$s['entry_hi']}, стоп {$s['stop']}, целей "
            . count($s['targets']) . ". Исполняю клиентам, отчёт пришлю сюда.");
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
            $usd = round($sp['total_amount'] * Settings::get('stars_usd_rate'), 2);
            $paymentId = DB::insert('payments', ['user_id' => (int)$uid, 'plan' => $plan['code'], 'stars' => (int)$sp['total_amount'],
                'usd' => $usd, 'charge_id' => $sp['telegram_payment_charge_id'], 'created_at' => DB::now()]);
            $cur = DB::val('SELECT sub_until FROM users WHERE id = ? FOR UPDATE', [(int)$uid]);
            $base = $cur && strtotime($cur . ' UTC') > time() ? strtotime($cur . ' UTC') : time();
            $until = $base + $plan['days'] * 86400;
            DB::update('users', ['sub_until' => gmdate('Y-m-d H:i:s', $until)], 'id = :id', [':id' => (int)$uid]);
            Referral::creditForPayment((int)$uid, $paymentId, $usd);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Telegram::send((int)$uid, '✅ Подписка активна до ' . gmdate('d.m.Y', $until)
            . '. Подключите Bybit в приложении и включите торговлю на бирже.', Telegram::appButton());
    }
}
