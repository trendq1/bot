<?php
declare(strict_types=1);

namespace App\Web;

use App\Crypto;
use App\DB;
use App\Engine\BybitExchange;
use App\Engine\Risk;
use App\Env;
use App\Settings;
use App\Telegram;

/** API Telegram Mini App (/api/index.php/...). Авторизация — подпись Telegram initData. */
final class MiniApi
{
    public static function handle(): mixed
    {
        $user = self::currentUser();
        $uid = (int)$user['id'];
        $b = Api::body();
        $tz = (int)($_GET['tz'] ?? 0);
        return match ([Api::method(), Api::path()]) {
            ['GET', '/me'] => self::me($user),
            ['POST', '/exchange'] => self::connectExchange($uid, $b),
            ['DELETE', '/exchange'] => self::disconnectExchange($uid),
            ['PUT', '/settings'] => self::updateSettings($user, $b),
            ['POST', '/bot/start'] => self::botAction($user, true),
            ['POST', '/bot/stop'] => self::botAction($user, false),
            ['POST', '/paper/reset'] => self::paperReset($uid),
            ['GET', '/dashboard'] => Stats::dashboard($uid, $tz),
            ['GET', '/calendar'] => self::calendar($uid, $tz),
            ['GET', '/day'] => self::day($uid, $tz),
            ['GET', '/trades'] => Stats::recent($uid, $tz),
            ['GET', '/market'] => self::market(),
            ['POST', '/pay'] => self::pay($uid, $b),
            default => Api::fail(404, 'Не найдено'),
        };
    }

    // ───────────── авторизация ─────────────

    public static function currentUser(): array
    {
        $tg = Crypto::telegramUser($_SERVER['HTTP_X_INIT_DATA'] ?? '', (string)Settings::get('bot_token'));
        $dev = (int)Env::get('DEV_USER_ID', '0');
        if ($tg === null && $dev && Settings::get('bot_token') === '') {      // только локальная отладка
            $tg = ['id' => $dev, 'first_name' => 'Dev'];
        }
        if ($tg === null) {
            Api::fail(401, 'Откройте приложение из Telegram');
        }
        $user = self::ensureUser((int)$tg['id'], $tg['username'] ?? null, $tg['first_name'] ?? null);
        if ($user['blocked']) {
            Api::fail(403, 'Доступ заблокирован. Обратитесь в поддержку.');
        }
        return $user;
    }

    public static function ensureUser(int $uid, ?string $username, ?string $firstName): array
    {
        $u = DB::row('SELECT * FROM users WHERE id = ?', [$uid]);
        if ($u === null) {
            DB::insert('users', ['id' => $uid, 'username' => $username, 'first_name' => $firstName, 'created_at' => DB::now(),
                'last_seen' => DB::now(), 'blocked' => false]);
            DB::insert('bot_settings', ['user_id' => $uid, 'running' => false, 'trading_mode' => 'paper', 'risk_profile' => 'balanced',
                'symbols' => Settings::get('default_symbols'), 'strategies' => ['grid' => true, 'trend' => true, 'liquidation' => true],
                'paper_balance' => Settings::get('paper_start_balance')]);
        } else {
            DB::update('users', array_filter(['last_seen' => DB::now(), 'username' => $username, 'first_name' => $firstName]),
                'id = :id', [':id' => $uid]);
        }
        return DB::row('SELECT * FROM users WHERE id = ?', [$uid]);
    }

    public static function hasSub(array $u): bool
    {
        return !empty($u['sub_until']) && strtotime($u['sub_until'] . ' UTC') > time();
    }

    public static function command(string $cmd, ?string $arg = null): void
    {
        DB::insert('engine_commands', ['cmd' => $cmd, 'arg' => $arg, 'created_at' => DB::now()]);
    }

    // ───────────── профиль ─────────────

    private static function me(array $u): array
    {
        $uid = (int)$u['id'];
        $bs = DB::row('SELECT * FROM bot_settings WHERE user_id = ?', [$uid]);
        $acc = DB::row('SELECT mode, bybit_uid, referral_ok FROM exchange_accounts WHERE user_id = ?', [$uid]);
        $ws = DB::row('SELECT * FROM worker_state WHERE user_id = ?', [$uid]);
        $live = $ws && strtotime($ws['updated_at'] . ' UTC') > time() - 120 ? (json_decode((string)$ws['live'], true) ?: []) : [];
        $running = (bool)$bs['running'];
        $status = $running && $ws ? $ws['status'] : ($bs['status_text'] ?: ($running ? 'запуск' : 'остановлен'));
        $profiles = [];
        foreach (Risk::PROFILES as $code => $p) {
            $profiles[] = ['code' => $code, 'title' => $p['title'], 'risk_pct' => $p['risk_pct'], 'leverage' => $p['leverage'],
                'daily_loss_pct' => $p['daily_loss_pct'], 'grid_levels' => $p['grid_levels']];
        }
        return [
            'user' => ['id' => $uid, 'name' => $u['first_name'] ?: ($u['username'] ?: ''), 'subscription_until' => Api::iso($u['sub_until']),
                'has_subscription' => self::hasSub($u)],
            'exchange' => $acc ? ['mode' => $acc['mode'], 'uid' => $acc['bybit_uid'], 'referral_ok' => (bool)$acc['referral_ok']] : null,
            'settings' => ['running' => $running, 'trading_mode' => $bs['trading_mode'], 'risk_profile' => $bs['risk_profile'],
                'symbols' => json_decode((string)$bs['symbols'], true) ?: [], 'strategies' => json_decode((string)$bs['strategies'], true) ?: [],
                'paper_balance' => round((float)$bs['paper_balance'], 2)],
            'status' => $status,
            'live' => $live + ['equity' => $ws && $running ? (float)$ws['equity'] : null],
            'options' => [
                'symbols' => Settings::get('symbols'), 'profiles' => $profiles, 'plans' => Settings::plans(),
                'referral_link' => Settings::get('referral_link'), 'support' => Settings::get('support_contact'),
                'maintenance' => Settings::get('maintenance'),
            ],
        ];
    }

    // ───────────── биржа ─────────────

    private static function connectExchange(int $uid, array $b): array
    {
        $key = Api::str($b, 'api_key', 10, 64);
        $secret = Api::str($b, 'api_secret', 10, 128);
        $mode = in_array($b['mode'] ?? 'demo', ['demo', 'live'], true) ? $b['mode'] : 'demo';
        try {
            $info = BybitExchange::verifyKeys($key, $secret, $mode);
        } catch (\RuntimeException $e) {
            Api::fail(400, $e->getMessage());
        }
        DB::q('INSERT INTO exchange_accounts (user_id, bybit_uid, api_key_enc, api_secret_enc, mode, referral_ok, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE bybit_uid = VALUES(bybit_uid), api_key_enc = VALUES(api_key_enc),
               api_secret_enc = VALUES(api_secret_enc), mode = VALUES(mode), referral_ok = VALUES(referral_ok), last_error = NULL',
            [$uid, $info['uid'], Crypto::encrypt($key), Crypto::encrypt($secret), $mode, (int)$info['referral_ok'], DB::now()]);
        self::command('restart_user', (string)$uid);
        return ['ok' => true, 'uid' => $info['uid']];
    }

    private static function disconnectExchange(int $uid): array
    {
        DB::q("UPDATE bot_settings SET running = 0, trading_mode = 'paper' WHERE user_id = ? AND trading_mode = 'exchange'", [$uid]);
        DB::q('DELETE FROM exchange_accounts WHERE user_id = ?', [$uid]);
        self::command('restart_user', (string)$uid);
        return ['ok' => true];
    }

    // ───────────── настройки и запуск ─────────────

    private static function updateSettings(array $u, array $b): array
    {
        $uid = (int)$u['id'];
        $upd = [];
        if (isset($b['trading_mode'])) {
            if (!in_array($b['trading_mode'], ['paper', 'exchange'], true)) {
                Api::fail(400, 'Неверный режим');
            }
            if ($b['trading_mode'] === 'exchange') {
                if (!DB::val('SELECT 1 FROM exchange_accounts WHERE user_id = ?', [$uid])) {
                    Api::fail(400, 'Сначала подключите Bybit');
                }
                if (!self::hasSub($u)) {
                    Api::fail(402, 'Торговля на бирже доступна по подписке');
                }
            }
            $upd['trading_mode'] = $b['trading_mode'];
        }
        if (isset($b['risk_profile'])) {
            if (!isset(Risk::PROFILES[$b['risk_profile']])) {
                Api::fail(400, 'Неизвестный профиль');
            }
            $upd['risk_profile'] = $b['risk_profile'];
        }
        if (isset($b['symbols'])) {
            $syms = array_slice(array_values(array_intersect((array)$b['symbols'], Settings::get('symbols'))), 0, 8);
            if (!$syms) {
                Api::fail(400, 'Выберите хотя бы одну монету');
            }
            $upd['symbols'] = $syms;
        }
        if (isset($b['strategies'])) {
            $st = [];
            foreach (['grid', 'trend', 'liquidation'] as $k) {
                $st[$k] = (bool)($b['strategies'][$k] ?? false);
            }
            if (!array_filter($st)) {
                Api::fail(400, 'Включите хотя бы одну стратегию');
            }
            $upd['strategies'] = $st;
        }
        if ($upd) {
            DB::update('bot_settings', $upd, 'user_id = :u', [':u' => $uid]);
            self::command('restart_user', (string)$uid);
        }
        return ['ok' => true];
    }

    private static function botAction(array $u, bool $start): array
    {
        $uid = (int)$u['id'];
        $bs = DB::row('SELECT trading_mode FROM bot_settings WHERE user_id = ?', [$uid]);
        if ($start && Settings::get('maintenance')) {
            Api::fail(423, 'Идёт техобслуживание — запуск временно недоступен');
        }
        if ($start && $bs['trading_mode'] === 'exchange' && !self::hasSub($u)) {
            Api::fail(402, 'Подписка закончилась — продлите её, чтобы торговать на бирже');
        }
        DB::update('bot_settings', ['running' => $start, 'status_text' => null], 'user_id = :u', [':u' => $uid]);
        return ['ok' => true];
    }

    private static function paperReset(int $uid): array
    {
        DB::update('bot_settings', ['paper_balance' => Settings::get('paper_start_balance')], 'user_id = :u', [':u' => $uid]);
        self::command('restart_user', (string)$uid);
        return ['ok' => true];
    }

    // ───────────── статистика ─────────────

    private static function calendar(int $uid, int $tz): array
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', (string)($_GET['month'] ?? ''), $m) || (int)$m[2] < 1 || (int)$m[2] > 12) {
            Api::fail(400, 'month=YYYY-MM');
        }
        return Stats::calendar($uid, (int)$m[1], (int)$m[2], $tz);
    }

    private static function day(int $uid, int $tz): array
    {
        $d = (string)($_GET['d'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            Api::fail(400, 'd=YYYY-MM-DD');
        }
        return ['date' => $d, 'trades' => Stats::dayTrades($uid, $d, $tz)];
    }

    public static function marketView(): array
    {
        $state = json_decode((string)DB::val('SELECT state FROM engine_status WHERE id = 1'), true) ?: [];
        $out = [];
        foreach ($state['symbols'] ?? [] as $sym => $s) {
            if (!empty($s['insight']) && !empty($s['features'])) {
                $out[] = ['symbol' => $sym, 'price' => $s['price'] ?? $s['features']['price']] + $s['insight']
                    + ['change_24h' => round((float)$s['features']['ret_24h'], 2)];
            }
        }
        return $out;
    }

    private static function market(): array
    {
        return ['market' => self::marketView(),
            'lessons' => array_column(DB::all('SELECT text FROM lessons ORDER BY id DESC LIMIT 5'), 'text'),
            'ai_enabled' => Settings::get('anthropic_api_key') !== ''];
    }

    // ───────────── оплата ─────────────

    private static function pay(int $uid, array $b): array
    {
        $plan = Settings::plan((string)($b['plan'] ?? ''));
        if (!$plan) {
            Api::fail(400, 'Неизвестный тариф');
        }
        if (Settings::get('bot_token') === '') {
            Api::fail(503, 'Оплата временно недоступна');
        }
        $link = Telegram::call('createInvoiceLink', [
            'title' => "Аренда AI-бота · {$plan['title']}",
            'description' => 'Автоторговля фьючерсами Bybit: сетка + тренд + ликвидации под управлением ИИ',
            'payload' => "sub:{$plan['code']}:$uid", 'currency' => 'XTR',
            'prices' => [['label' => $plan['title'], 'amount' => $plan['stars']]],
        ]);
        return ['link' => $link];
    }
}
