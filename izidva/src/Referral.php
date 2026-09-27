<?php
declare(strict_types=1);

namespace App;

/**
 * Партнёрская программа: до 5 уровней вглубь по цепочке users.referred_by.
 * Начисление — только через creditForPayment(), которую вызывают из мест, где уже создана
 * запись в payments (Webhook::paid — оплата звёздами, nowpayments/webhook.php — оплата криптой).
 * Обе оплаты возможны только за подписку на торговлю на бирже — демо-счёт бесплатный и в payments
 * не попадает, поэтому доход партнёров автоматически считается только с реальных счетов.
 */
final class Referral
{
    public static function levelPercents(): array
    {
        return [1 => (float)Settings::get('referral_pct_l1'), 2 => (float)Settings::get('referral_pct_l2'),
            3 => (float)Settings::get('referral_pct_l3'), 4 => (float)Settings::get('referral_pct_l4'),
            5 => (float)Settings::get('referral_pct_l5')];
    }

    /** Привязать нового пользователя к пригласившему (только один раз, только для только что созданного). */
    public static function attach(int $uid, int $referrerId): void
    {
        if ($referrerId === $uid || !DB::val('SELECT 1 FROM users WHERE id = ?', [$referrerId])) {
            return;
        }
        DB::q('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL', [$referrerId, $uid]);
    }

    /** Начислить комиссию по цепочке рефереров за реальный платёж $payerId → $paymentId на сумму $usd. */
    public static function creditForPayment(int $payerId, int $paymentId, float $usd): void
    {
        if (!Settings::get('referral_program_enabled')) {
            return;
        }
        $pct = self::levelPercents();
        $current = $payerId;
        for ($level = 1; $level <= 5; $level++) {
            $referrerId = DB::val('SELECT referred_by FROM users WHERE id = ?', [$current]);
            if (!$referrerId) {
                break;
            }
            $referrerId = (int)$referrerId;
            $p = $pct[$level] ?? 0.0;
            if ($p > 0) {
                DB::insert('referral_earnings', ['beneficiary_id' => $referrerId, 'from_user_id' => $payerId, 'level' => $level,
                    'payment_id' => $paymentId, 'pct' => $p, 'amount_usd' => round($usd * $p / 100, 4), 'paid' => false,
                    'created_at' => DB::now()]);
            }
            $current = $referrerId;
        }
    }

    /** Сколько человек в команде на каждом из 5 уровней (по цепочке referred_by), считая от $uid. */
    public static function downlineCounts(int $uid): array
    {
        $counts = [];
        $parents = [$uid];
        for ($level = 1; $level <= 5; $level++) {
            if (!$parents) {
                $counts[$level] = 0;
                continue;
            }
            $ph = implode(',', array_fill(0, count($parents), '?'));
            $ids = array_column(DB::all("SELECT id FROM users WHERE referred_by IN ($ph)", $parents), 'id');
            $counts[$level] = count($ids);
            $parents = $ids;
        }
        return $counts;
    }

    /** Косметический ранг по размеру команды (весь даунлайн, все 5 уровней). */
    public static function rank(int $teamSize): int
    {
        foreach ([50 => 5, 20 => 4, 10 => 3, 5 => 2] as $min => $r) {
            if ($teamSize >= $min) {
                return $r;
            }
        }
        return 1;
    }
}
