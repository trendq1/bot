<?php
declare(strict_types=1);

namespace App\Web;

use App\Bybit;
use App\Crypto;
use App\DB;
use App\Engine\BybitExchange;
use App\Engine\Risk;
use App\Env;
use App\NowPayments;
use App\Referral;
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
            ['GET', '/chart'] => self::chart($uid),
            ['POST', '/pay'] => self::pay($uid, $b),
            ['GET', '/team'] => self::team($uid),
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
                'crypto_pay' => (bool)Settings::get('nowpayments_enabled') && Settings::get('nowpayments_api_key') !== '',
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
        $vision = [];
        foreach (DB::all('SELECT symbol, bias, key_level, summary FROM vision_notes') as $v) {
            $vision[$v['symbol']] = ['bias' => $v['bias'], 'key_level' => $v['key_level'] !== null ? (float)$v['key_level'] : null, 'summary' => $v['summary']];
        }
        $out = [];
        foreach ($state['symbols'] ?? [] as $sym => $s) {
            if (!empty($s['insight']) && !empty($s['features'])) {
                $out[] = ['symbol' => $sym, 'price' => $s['price'] ?? $s['features']['price']] + $s['insight']
                    + ['change_24h' => round((float)$s['features']['ret_24h'], 2), 'vision' => $vision[$sym] ?? null];
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

    // ───────────── живой график ─────────────

    /** Свечи Bybit (публичный эндпоинт, без ключей) + отметки сделок клиента + текущие позиции/сетки. */
    private static function chart(int $uid): array
    {
        $symbol = strtoupper(trim((string)($_GET['symbol'] ?? '')));
        if (!in_array($symbol, Settings::get('symbols'), true)) {
            Api::fail(400, 'Неизвестная монета');
        }
        $wantedInterval = (string)($_GET['interval'] ?? '5');
        $interval = in_array($wantedInterval, ['1', '3', '5', '15', '60'], true) ? $wantedInterval : '5';
        $limit = max(50, min(300, (int)($_GET['limit'] ?? 200)));
        try {
            $r = (new Bybit())->get('/v5/market/kline', ['category' => 'linear', 'symbol' => $symbol, 'interval' => $interval, 'limit' => $limit]);
        } catch (\Throwable $e) {
            Api::fail(502, 'Bybit: ' . $e->getMessage());
        }
        $candles = [];
        foreach (array_reverse($r['list'] ?? []) as $k) {
            $candles[] = ['time' => intdiv((int)$k[0], 1000), 'open' => (float)$k[1], 'high' => (float)$k[2], 'low' => (float)$k[3], 'close' => (float)$k[4]];
        }
        $markers = [];
        if ($candles) {
            $from = gmdate('Y-m-d H:i:s', $candles[0]['time']);
            foreach (DB::all('SELECT side, entry, `exit`, pnl, opened_at, closed_at FROM trades
                    WHERE user_id = ? AND symbol = ? AND closed_at >= ? ORDER BY closed_at', [$uid, $symbol, $from]) as $tr) {
                $markers[] = ['kind' => 'entry', 'time' => strtotime($tr['opened_at'] . ' UTC'), 'price' => round((float)$tr['entry'], 8), 'side' => $tr['side']];
                $markers[] = ['kind' => 'exit', 'time' => strtotime($tr['closed_at'] . ' UTC'), 'price' => round((float)$tr['exit'], 8),
                    'side' => $tr['side'], 'pnl' => round((float)$tr['pnl'], 2)];
            }
        }
        $ws = DB::row('SELECT live, updated_at FROM worker_state WHERE user_id = ?', [$uid]);
        $live = $ws && strtotime($ws['updated_at'] . ' UTC') > time() - 120 ? (json_decode((string)$ws['live'], true) ?: []) : [];
        $lines = [];
        foreach ($live['grids'] ?? [] as $g) {
            if ($g['symbol'] === $symbol) {
                $lines[] = ['kind' => 'grid', 'mode' => $g['mode'], 'step_pct' => $g['step_pct']];
            }
        }
        foreach ($live['directional'] ?? [] as $d) {
            if ($d['symbol'] === $symbol) {
                $lines[] = ['kind' => 'position', 'side' => $d['side'], 'entry' => (float)$d['entry'], 'stop' => (float)$d['stop']];
            }
        }
        return ['symbol' => $symbol, 'candles' => $candles, 'markers' => $markers, 'live' => $lines];
    }

    // ───────────── оплата ─────────────

    private static function pay(int $uid, array $b): array
    {
        $plan = Settings::plan((string)($b['plan'] ?? ''));
        if (!$plan) {
            Api::fail(400, 'Неизвестный тариф');
        }
        if (($b['method'] ?? 'stars') === 'crypto') {
            return self::payCrypto($uid, $plan);
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
        return ['link' => $link, 'method' => 'stars'];
    }

    /** Оплата криптовалютой через NOWPayments: инвойс создаётся сразу, подтверждение — вебхуком (IPN). */
    private static function payCrypto(int $uid, array $plan): array
    {
        if (!Settings::get('nowpayments_enabled') || Settings::get('nowpayments_api_key') === '') {
            Api::fail(503, 'Оплата криптовалютой временно недоступна');
        }
        $usd = (float)$plan['usd'];
        $orderId = sprintf('sub-%d-%s-%s', $uid, $plan['code'], bin2hex(random_bytes(4)));
        $base = rtrim((string)Settings::get('webapp_url'), '/');
        try {
            $inv = NowPayments::createInvoice([
                'price_amount' => $usd, 'price_currency' => 'usd', 'order_id' => $orderId,
                'order_description' => "Подписка AI Trader · {$plan['title']}",
                'ipn_callback_url' => AdminApi::baseUrl() . '/nowpayments/webhook.php',
                'success_url' => $base ?: null, 'cancel_url' => $base ?: null,
            ]);
        } catch (\Throwable $e) {
            Api::fail(502, 'NOWPayments: ' . $e->getMessage());
        }
        DB::insert('crypto_invoices', ['user_id' => $uid, 'plan' => $plan['code'], 'order_id' => $orderId,
            'invoice_id' => (string)($inv['id'] ?? ''), 'price_amount' => $usd, 'price_currency' => 'usd',
            'status' => 'waiting', 'invoice_url' => (string)$inv['invoice_url'], 'created_at' => DB::now()]);
        return ['link' => $inv['invoice_url'], 'method' => 'crypto'];
    }

    // ───────────── партнёрская программа ─────────────

    private static function team(int $uid): array
    {
        if (!Settings::get('referral_program_enabled')) {
            return ['enabled' => false];
        }
        $pct = Referral::levelPercents();
        $counts = Referral::downlineCounts($uid);
        $earnByLevel = array_column(DB::all('SELECT level, SUM(amount_usd) s FROM referral_earnings WHERE beneficiary_id = ? GROUP BY level', [$uid]), 's', 'level');
        $levels = [];
        $teamSize = 0;
        foreach (range(1, 5) as $lvl) {
            $teamSize += $counts[$lvl];
            $levels[] = ['level' => $lvl, 'pct' => $pct[$lvl], 'people' => $counts[$lvl], 'earned' => round((float)($earnByLevel[$lvl] ?? 0), 2)];
        }
        $total = round((float)DB::val('SELECT SUM(amount_usd) FROM referral_earnings WHERE beneficiary_id = ?', [$uid]), 2);
        $month = round((float)DB::val('SELECT SUM(amount_usd) FROM referral_earnings WHERE beneficiary_id = ? AND created_at >= ?',
            [$uid, gmdate('Y-m-01 00:00:00')]), 2);
        $pending = round((float)DB::val('SELECT SUM(amount_usd) FROM referral_earnings WHERE beneficiary_id = ? AND paid = 0', [$uid]), 2);
        $botUsername = (string)Settings::get('bot_username');
        $tree = [];
        foreach (DB::all('SELECT id, username, first_name, created_at FROM users WHERE referred_by = ? ORDER BY created_at DESC LIMIT 30', [$uid]) as $r) {
            $earned = round((float)DB::val('SELECT SUM(amount_usd) FROM referral_earnings WHERE beneficiary_id = ? AND level = 1 AND from_user_id = ?',
                [$uid, $r['id']]), 2);
            $tree[] = ['id' => $r['id'], 'name' => $r['first_name'] ?: ($r['username'] ?: ('ID' . $r['id'])),
                'joined' => Api::iso($r['created_at']), 'sub_team' => array_sum(Referral::downlineCounts((int)$r['id'])), 'earned' => $earned];
        }
        return [
            'enabled' => true,
            'rank' => Referral::rank($teamSize),
            'team_size' => $teamSize,
            'total_earned' => $total,
            'month_earned' => $month,
            'pending_payout' => $pending,
            'referral_link' => $botUsername !== '' ? "https://t.me/{$botUsername}?start=ref_{$uid}" : null,
            'levels' => $levels,
            'tree' => $tree,
        ];
    }
}
