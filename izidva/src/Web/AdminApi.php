<?php
declare(strict_types=1);

namespace App\Web;

use App\Crypto;
use App\DB;
use App\Engine\AIAnalyst;
use App\Engine\Risk;
use App\Log;
use App\Settings;
use App\Telegram;

/** API браузерной админ-панели (/admin/api/index.php/...). Вход по логину/паролю, сессия в подписанной cookie. */
final class AdminApi
{
    public const COOKIE = 'izidva_admin';

    public static function handle(): mixed
    {
        $path = Api::path();
        $m = Api::method();
        $b = Api::body();
        if ($m === 'POST' && $path === '/login') {
            return self::login($b);
        }
        if ($m === 'POST' && $path === '/logout') {
            self::setCookie('', -3600);
            return ['ok' => true];
        }
        $admin = self::currentAdmin();
        $a = $admin['username'];

        if (preg_match('#^/users/(\d+)$#', $path, $mm) && $m === 'GET') {
            return self::userDetail((int)$mm[1]);
        }
        if (preg_match('#^/users/(\d+)/action$#', $path, $mm) && $m === 'POST') {
            return self::userAction((int)$mm[1], $b, $a);
        }
        if (preg_match('#^/export/(users|trades|payments|finance)\.csv$#', $path, $mm) && $m === 'GET') {
            self::export($mm[1], $a);
            exit;
        }
        if (preg_match('#^/finance/(\d+)$#', $path, $mm) && $m === 'DELETE') {
            DB::q('DELETE FROM finance_entries WHERE id = ?', [(int)$mm[1]]);
            self::audit($a, 'finance.delete', $mm[1]);
            return ['ok' => true];
        }
        if (preg_match('#^/ai/lessons/(\d+)$#', $path, $mm) && $m === 'DELETE') {
            DB::q('DELETE FROM lessons WHERE id = ?', [(int)$mm[1]]);
            self::audit($a, 'ai.lesson_delete', $mm[1]);
            return ['ok' => true];
        }
        if (preg_match('#^/ai/tuning/([A-Z0-9]+)$#i', $path, $mm) && $m === 'PUT') {
            return self::tuning(strtoupper($mm[1]), $b, $a);
        }
        if (preg_match('#^/admins/(\d+)$#', $path, $mm) && $m === 'DELETE') {
            return self::adminDelete((int)$mm[1], $admin);
        }
        if (preg_match('#^/menu/(\d+)$#', $path, $mm) && $m === 'PUT') {
            return self::menuUpdate((int)$mm[1], $b, $a);
        }
        if (preg_match('#^/menu/(\d+)$#', $path, $mm) && $m === 'DELETE') {
            DB::q('DELETE FROM bot_menu_buttons WHERE id = ?', [(int)$mm[1]]);
            self::audit($a, 'menu.delete', (string)$mm[1]);
            return ['ok' => true];
        }
        return match ([$m, $path]) {
            ['GET', '/me'] => ['username' => $a],
            ['GET', '/overview'] => self::overview(max(7, min(365, (int)($_GET['days'] ?? 30)))),
            ['GET', '/users'] => self::users((string)($_GET['q'] ?? ''), (string)($_GET['filter'] ?? 'all')),
            ['GET', '/payments'] => self::payments(),
            ['GET', '/trades'] => self::trades(),
            ['GET', '/finance'] => self::finance(max(1, min(365, (int)($_GET['days'] ?? 30)))),
            ['POST', '/finance'] => self::financeAdd($b, $a),
            ['GET', '/settings'] => self::getSettings(),
            ['PUT', '/settings'] => self::putSettings($b, $a),
            ['POST', '/system/restart'] => self::restartEngine($a),
            ['GET', '/ai'] => self::aiState(),
            ['POST', '/ai/run'] => self::aiRun($a),
            ['POST', '/ai/lessons'] => self::lessonAdd($b, $a),
            ['POST', '/broadcast'] => self::broadcast($b, $a),
            ['GET', '/menu'] => self::menuList(),
            ['POST', '/menu'] => self::menuAdd($b, $a),
            ['GET', '/logs'] => self::logs(max(10, min(2000, (int)($_GET['lines'] ?? 200)))),
            ['GET', '/admins'] => self::admins(),
            ['POST', '/admins'] => self::adminAdd($b, $a),
            ['POST', '/admins/password'] => self::changePassword($b, $admin),
            default => Api::fail(404, 'Не найдено'),
        };
    }

    // ───────────── авторизация ─────────────

    private static function setCookie(string $value, int $ttl): void
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        setcookie(self::COOKIE, $value, ['expires' => time() + $ttl, 'path' => '/', 'httponly' => true,
            'samesite' => 'Strict', 'secure' => $https]);
    }

    public static function currentAdmin(bool $csrf = true): array
    {
        $id = Crypto::readSession($_COOKIE[self::COOKIE] ?? null);
        if ($id === null) {
            Api::fail(401, 'Требуется вход');
        }
        // защита от CSRF: изменяющие запросы только из нашего JS с этим заголовком
        if ($csrf && Api::method() !== 'GET' && ($_SERVER['HTTP_X_ADMIN'] ?? '') !== '1') {
            Api::fail(403, 'Некорректный запрос');
        }
        $admin = DB::row('SELECT * FROM admin_users WHERE id = ?', [$id]);
        if ($admin === null) {
            Api::fail(401, 'Требуется вход');
        }
        return $admin;
    }

    /** Ограничение перебора паролей: 8 неудачных попыток за 15 минут с одного IP. */
    private static function attempts(string $ip, bool $add = false): int
    {
        $f = STORAGE_DIR . '/login_attempts.json';
        $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        $now = time();
        foreach ($d as $k => $list) {
            $d[$k] = array_values(array_filter($list, fn($t) => $now - $t < 900));
            if (!$d[$k]) {
                unset($d[$k]);
            }
        }
        if ($add) {
            $d[$ip][] = $now;
        }
        file_put_contents($f, json_encode($d), LOCK_EX);
        return count($d[$ip] ?? []);
    }

    private static function login(array $b): array
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (self::attempts($ip) >= 8) {
            Api::fail(429, 'Слишком много попыток. Подождите 15 минут.');
        }
        $admin = DB::row('SELECT * FROM admin_users WHERE username = ?', [trim((string)($b['username'] ?? ''))]);
        if (!$admin || !Crypto::checkPassword((string)($b['password'] ?? ''), $admin['password_hash'])) {
            self::attempts($ip, true);
            Api::fail(401, 'Неверный логин или пароль');
        }
        DB::update('admin_users', ['last_login' => DB::now()], 'id = :id', [':id' => $admin['id']]);
        self::setCookie(Crypto::sessionToken((int)$admin['id']), 43200);
        self::audit($admin['username'], 'login', $ip);
        return ['ok' => true, 'username' => $admin['username']];
    }

    public static function audit(string $actor, string $action, string $details = ''): void
    {
        DB::insert('audit_log', ['ts' => DB::now(), 'actor' => $actor, 'action' => $action, 'details' => mb_substr($details, 0, 2000)]);
    }

    // ───────────── обзор и финансы ─────────────

    public static function financeSeries(int $days): array
    {
        $since = gmmktime(0, 0, 0, (int)gmdate('n'), (int)gmdate('j') - ($days - 1), (int)gmdate('Y'));
        $sinceStr = gmdate('Y-m-d H:i:s', $since);
        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $d = gmdate('Y-m-d', $since + $i * 86400);
            $rows[$d] = ['date' => $d, 'subs' => 0.0, 'income_other' => 0.0, 'ai' => 0.0, 'expense_other' => 0.0,
                'new_users' => 0, 'trades' => 0, 'clients_pnl' => 0.0];
        }
        $add = function (string $sql, callable $fn) use (&$rows, $sinceStr) {
            foreach (DB::all($sql, [$sinceStr]) as $r) {
                if (isset($rows[$r['d']])) {
                    $fn($rows[$r['d']], $r);
                }
            }
        };
        $add('SELECT DATE(created_at) d, SUM(usd) v FROM payments WHERE created_at >= ? GROUP BY d', fn(&$x, $r) => $x['subs'] += (float)$r['v']);
        $add("SELECT DATE(date) d, SUM(CASE WHEN kind = 'income' THEN amount_usd ELSE 0 END) i,
              SUM(CASE WHEN kind = 'expense' THEN amount_usd ELSE 0 END) e FROM finance_entries WHERE date >= ? GROUP BY d",
            function (&$x, $r) { $x['income_other'] += (float)$r['i']; $x['expense_other'] += (float)$r['e']; });
        $add('SELECT DATE(ts) d, SUM(cost_usd) v FROM ai_usage WHERE ts >= ? GROUP BY d', fn(&$x, $r) => $x['ai'] += (float)$r['v']);
        $add('SELECT DATE(created_at) d, COUNT(*) v FROM users WHERE created_at >= ? GROUP BY d', fn(&$x, $r) => $x['new_users'] += (int)$r['v']);
        $add('SELECT DATE(closed_at) d, COUNT(*) n, SUM(pnl) v FROM trades WHERE closed_at >= ? GROUP BY d',
            function (&$x, $r) { $x['trades'] += (int)$r['n']; $x['clients_pnl'] += (float)$r['v']; });
        $out = [];
        foreach ($rows as $r) {
            $income = $r['subs'] + $r['income_other'];
            $expense = $r['ai'] + $r['expense_other'];
            foreach (['subs', 'income_other', 'ai', 'expense_other', 'clients_pnl'] as $k) {
                $r[$k] = round($r[$k], 2);
            }
            $out[] = $r + ['income' => round($income, 2), 'expense' => round($expense, 2), 'profit' => round($income - $expense, 2)];
        }
        return $out;
    }

    private static function sum(array $rows, string $k, ?int $last = null): float
    {
        return round(array_sum(array_column($last ? array_slice($rows, -$last) : $rows, $k)), 2);
    }

    public static function engineState(): array
    {
        $cmd = 'sudo bash ' . realpath(BASE_DIR) . '/bin/install-service.sh';
        $row = DB::row('SELECT * FROM engine_status WHERE id = 1');
        if (!$row) {
            return ['alive' => false, 'heartbeat_age_sec' => null, 'service_command' => $cmd, 'workers' => 0, 'ws' => false,
                'symbols' => [], 'ai_enabled' => Settings::get('anthropic_api_key') !== ''];
        }
        $state = json_decode((string)$row['state'], true) ?: [];
        $age = time() - strtotime($row['heartbeat_at'] . ' UTC');
        $symbols = [];
        foreach ($state['symbols'] ?? [] as $sym => $s) {
            $ins = $s['insight'] ?? null;
            $symbols[] = ['symbol' => $sym, 'price' => $s['price'] ?? null, 'regime' => $ins['regime'] ?? null, 'source' => $ins['source'] ?? null,
                'liq_long_1h' => $s['liq_long_1h'] ?? 0, 'liq_short_1h' => $s['liq_short_1h'] ?? 0];
        }
        return ['alive' => $age < 60, 'heartbeat_age_sec' => $age, 'service_command' => $cmd, 'started_at' => Api::iso($row['started_at']),
            'workers' => $state['workers'] ?? 0, 'ws' => $state['ws'] ?? false, 'symbols' => $symbols,
            'ai_enabled' => Settings::get('anthropic_api_key') !== ''];
    }

    private static function overview(int $days): array
    {
        $rows = self::financeSeries($days);
        $now = DB::now();
        $cnt = fn(string $sql, array $p = []) => (int)DB::val($sql, $p);
        return [
            'kpi' => [
                'users_total' => $cnt('SELECT COUNT(*) FROM users'),
                'users_new_7d' => array_sum(array_column(array_slice($rows, -7), 'new_users')),
                'subs_active' => $cnt('SELECT COUNT(*) FROM users WHERE sub_until > ?', [$now]),
                'bots_running' => $cnt('SELECT COUNT(*) FROM bot_settings WHERE running = 1'),
                'bots_on_exchange' => $cnt("SELECT COUNT(*) FROM bot_settings WHERE running = 1 AND trading_mode = 'exchange'"),
                'exchange_connected' => $cnt('SELECT COUNT(*) FROM exchange_accounts'),
                'referrals' => $cnt('SELECT COUNT(*) FROM exchange_accounts WHERE referral_ok = 1'),
                'income_today' => self::sum($rows, 'income', 1), 'income_period' => self::sum($rows, 'income'),
                'expense_today' => self::sum($rows, 'expense', 1), 'expense_period' => self::sum($rows, 'expense'),
                'profit_period' => self::sum($rows, 'profit'), 'ai_cost_period' => self::sum($rows, 'ai'),
                'revenue_all' => round((float)DB::val('SELECT COALESCE(SUM(usd), 0) FROM payments'), 2),
                'stars_all' => $cnt('SELECT COALESCE(SUM(stars), 0) FROM payments'),
                'ai_cost_all' => round((float)DB::val('SELECT COALESCE(SUM(cost_usd), 0) FROM ai_usage'), 2),
                'clients_pnl_period' => self::sum($rows, 'clients_pnl'),
                'trades_period' => array_sum(array_column($rows, 'trades')),
            ],
            'series' => $rows,
            'engine' => self::engineState(),
            'recent_payments' => array_map(fn($p) => ['user' => $p['first_name'] ?: ($p['username'] ?: $p['user_id']), 'user_id' => (int)$p['user_id'],
                'plan' => $p['plan'], 'stars' => (int)$p['stars'], 'usd' => (float)$p['usd'], 'at' => Api::iso($p['created_at'])],
                DB::all('SELECT p.*, u.first_name, u.username FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 8')),
            'recent_audit' => array_map(fn($x) => ['actor' => $x['actor'], 'action' => $x['action'], 'details' => $x['details'], 'at' => Api::iso($x['ts'])],
                DB::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')),
        ];
    }

    // ───────────── клиенты ─────────────

    private static function userRow(array $u, float $pnl30 = 0.0, int $trades30 = 0, float $paid = 0.0): array
    {
        return ['id' => (int)$u['id'], 'username' => $u['username'], 'name' => $u['first_name'], 'created_at' => Api::iso($u['created_at']),
            'last_seen' => Api::iso($u['last_seen']), 'sub_until' => Api::iso($u['sub_until']),
            'has_sub' => !empty($u['sub_until']) && strtotime($u['sub_until'] . ' UTC') > time(), 'blocked' => (bool)$u['blocked'],
            'running' => (bool)($u['running'] ?? false), 'mode' => $u['trading_mode'] ?? null, 'profile' => $u['risk_profile'] ?? null,
            'exchange' => $u['ex_mode'] ?? null, 'referral' => (bool)($u['referral_ok'] ?? false),
            'pnl_30d' => round($pnl30, 2), 'trades_30d' => $trades30, 'paid_usd' => round($paid, 2)];
    }

    private static function users(string $q, string $filter): array
    {
        $where = ['1=1'];
        $p = [];
        if (trim($q) !== '') {
            $where[] = '(u.username LIKE ? OR u.first_name LIKE ? OR u.id = ?)';
            array_push($p, '%' . trim($q) . '%', '%' . trim($q) . '%', ctype_digit(trim($q)) ? (int)$q : -1);
        }
        $where[] = match ($filter) {
            'subscribed' => 'u.sub_until > UTC_TIMESTAMP()',
            'running' => 'b.running = 1',
            'exchange' => 'a.user_id IS NOT NULL',
            'blocked' => 'u.blocked = 1',
            default => '1=1',
        };
        $rows = DB::all('SELECT u.*, b.running, b.trading_mode, b.risk_profile, a.mode ex_mode, a.referral_ok
            FROM users u LEFT JOIN bot_settings b ON b.user_id = u.id LEFT JOIN exchange_accounts a ON a.user_id = u.id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC LIMIT 500', $p);
        $stats = [];
        foreach (DB::all('SELECT user_id, SUM(pnl) pnl, COUNT(*) n FROM trades WHERE closed_at >= ? GROUP BY user_id',
            [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]) as $r) {
            $stats[$r['user_id']] = [(float)$r['pnl'], (int)$r['n']];
        }
        $paid = array_column(DB::all('SELECT user_id, SUM(usd) usd FROM payments GROUP BY user_id'), 'usd', 'user_id');
        return array_map(fn($u) => self::userRow($u, ...($stats[$u['id']] ?? [0.0, 0]), paid: (float)($paid[$u['id']] ?? 0)), $rows);
    }

    private static function userDetail(int $uid): array
    {
        $u = DB::row('SELECT u.*, b.running, b.trading_mode, b.risk_profile, b.symbols, b.strategies, b.paper_balance, b.status_text,
            a.mode ex_mode, a.referral_ok, a.bybit_uid, a.created_at ex_created, a.last_error
            FROM users u LEFT JOIN bot_settings b ON b.user_id = u.id LEFT JOIN exchange_accounts a ON a.user_id = u.id WHERE u.id = ?', [$uid]);
        if (!$u) {
            Api::fail(404, 'Клиент не найден');
        }
        $pays = DB::all('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC', [$uid]);
        $agg = DB::row('SELECT COALESCE(SUM(pnl), 0) pnl, COUNT(*) n, COALESCE(SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END), 0) wins
            FROM trades WHERE user_id = ?', [$uid]);
        $eq = DB::val('SELECT equity FROM equity_snapshots WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
        $ws = DB::row('SELECT * FROM worker_state WHERE user_id = ?', [$uid]);
        $live = $ws && strtotime($ws['updated_at'] . ' UTC') > time() - 120 ? json_decode((string)$ws['live'], true) : null;
        return [
            'user' => self::userRow($u, 0.0, 0, array_sum(array_map(fn($p) => (float)$p['usd'], $pays))) + ['notes' => $u['notes']],
            'settings' => $u['trading_mode'] === null ? null : ['symbols' => json_decode((string)$u['symbols'], true) ?: [],
                'strategies' => json_decode((string)$u['strategies'], true) ?: [], 'paper_balance' => round((float)$u['paper_balance'], 2),
                'status' => $u['status_text']],
            'exchange' => $u['ex_mode'] === null ? null : ['uid' => $u['bybit_uid'], 'mode' => $u['ex_mode'], 'referral_ok' => (bool)$u['referral_ok'],
                'connected_at' => Api::iso($u['ex_created']), 'last_error' => $u['last_error']],
            'totals' => ['pnl' => round((float)$agg['pnl'], 2), 'trades' => (int)$agg['n'], 'wins' => (int)$agg['wins'],
                'equity' => $eq !== null ? (float)$eq : null],
            'live' => $live,
            'live_status' => $ws['status'] ?? null,
            'trades' => array_map(fn($t) => ['id' => (int)$t['id'], 'symbol' => $t['symbol'], 'strategy' => $t['strategy'], 'side' => $t['side'],
                'entry' => (float)$t['entry'], 'exit' => (float)$t['exit'], 'pnl' => round((float)$t['pnl'], 4), 'mode' => $t['mode'],
                'at' => Api::iso($t['closed_at'])], DB::all('SELECT * FROM trades WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$uid])),
            'payments' => array_map(fn($p) => ['plan' => $p['plan'], 'stars' => (int)$p['stars'], 'usd' => (float)$p['usd'],
                'at' => Api::iso($p['created_at'])], $pays),
        ];
    }

    private static function userAction(int $uid, array $b, string $admin): array
    {
        $action = (string)($b['action'] ?? '');
        $u = DB::row('SELECT u.*, b.trading_mode FROM users u JOIN bot_settings b ON b.user_id = u.id WHERE u.id = ?', [$uid]);
        if (!$u) {
            Api::fail(404, 'Клиент не найден');
        }
        $days = max(-3650, min(3650, (int)($b['days'] ?? 30)));
        $value = (string)($b['value'] ?? '');
        switch ($action) {
            case 'extend':
                $base = !empty($u['sub_until']) && strtotime($u['sub_until'] . ' UTC') > time() ? strtotime($u['sub_until'] . ' UTC') : time();
                DB::update('users', ['sub_until' => gmdate('Y-m-d H:i:s', $base + $days * 86400)], 'id = :id', [':id' => $uid]);
                break;
            case 'cancel_sub':
                DB::update('users', ['sub_until' => null], 'id = :id', [':id' => $uid]);
                DB::q("UPDATE bot_settings SET running = 0 WHERE user_id = ? AND trading_mode = 'exchange'", [$uid]);
                break;
            case 'start':
            case 'stop':
                DB::update('bot_settings', ['running' => $action === 'start', 'status_text' => null], 'user_id = :u', [':u' => $uid]);
                break;
            case 'block':
            case 'unblock':
                DB::update('users', ['blocked' => $action === 'block'], 'id = :id', [':id' => $uid]);
                if ($action === 'block') {
                    DB::update('bot_settings', ['running' => false], 'user_id = :u', [':u' => $uid]);
                }
                break;
            case 'profile':
                if (!isset(Risk::PROFILES[$value])) {
                    Api::fail(400, 'Неизвестный профиль');
                }
                DB::update('bot_settings', ['risk_profile' => $value], 'user_id = :u', [':u' => $uid]);
                break;
            case 'notes':
                DB::update('users', ['notes' => mb_substr($value, 0, 2000)], 'id = :id', [':id' => $uid]);
                break;
            case 'disconnect_exchange':
                DB::q('DELETE FROM exchange_accounts WHERE user_id = ?', [$uid]);
                DB::q("UPDATE bot_settings SET running = 0, trading_mode = 'paper' WHERE user_id = ? AND trading_mode = 'exchange'", [$uid]);
                break;
            case 'reset_paper':
                DB::update('bot_settings', ['paper_balance' => Settings::get('paper_start_balance')], 'user_id = :u', [':u' => $uid]);
                break;
            case 'message':
                if ($value === '' || Settings::get('bot_token') === '') {
                    Api::fail(400, 'Бот не настроен или пустое сообщение');
                }
                if (!Telegram::send($uid, htmlspecialchars(mb_substr($value, 0, 4000)))) {
                    Api::fail(400, 'Не удалось отправить — клиент мог заблокировать бота');
                }
                break;
            default:
                Api::fail(400, 'Неизвестное действие');
        }
        if (!in_array($action, ['notes', 'message', 'extend'], true)) {
            MiniApi::command('restart_user', (string)$uid);
        }
        self::audit($admin, "user.$action", "user=$uid days=$days value=" . mb_substr($value, 0, 100));
        return ['ok' => true];
    }

    // ───────────── платежи, сделки, экспорт ─────────────

    private static function payments(): array
    {
        return array_map(fn($p) => ['id' => (int)$p['id'], 'user_id' => (int)$p['user_id'], 'user' => $p['first_name'] ?: ($p['username'] ?: $p['user_id']),
            'plan' => $p['plan'], 'stars' => (int)$p['stars'], 'usd' => (float)$p['usd'], 'charge_id' => $p['charge_id'], 'at' => Api::iso($p['created_at'])],
            DB::all('SELECT p.*, u.first_name, u.username FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 500'));
    }

    private static function trades(): array
    {
        $where = ['1=1'];
        $p = [];
        foreach (['user_id' => 'user_id', 'symbol' => 'symbol', 'strategy' => 'strategy', 'mode' => 'mode'] as $q => $col) {
            $v = trim((string)($_GET[$q] ?? ''));
            if ($v !== '') {
                $where[] = "$col = ?";
                $p[] = $q === 'symbol' ? strtoupper($v) : ($q === 'user_id' ? (int)$v : $v);
            }
        }
        return array_map(fn($t) => ['id' => (int)$t['id'], 'user_id' => (int)$t['user_id'], 'symbol' => $t['symbol'], 'strategy' => $t['strategy'],
            'side' => $t['side'], 'qty' => (float)$t['qty'], 'entry' => (float)$t['entry'], 'exit' => (float)$t['exit'],
            'pnl' => round((float)$t['pnl'], 4), 'r' => round((float)$t['r'], 2), 'regime' => $t['regime'], 'mode' => $t['mode'],
            'at' => Api::iso($t['closed_at'])], DB::all('SELECT * FROM trades WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 500', $p));
    }

    private static function export(string $what, string $admin): void
    {
        $table = ['users' => 'users', 'trades' => 'trades', 'payments' => 'payments', 'finance' => 'finance_entries'][$what];
        self::audit($admin, 'export', $what);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$what.csv\"");
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $st = DB::q("SELECT * FROM $table");
        $first = true;
        while ($r = $st->fetch()) {
            if ($first) {
                fputcsv($out, array_keys($r));
                $first = false;
            }
            fputcsv($out, $r);
        }
        fclose($out);
    }

    // ───────────── ручные доходы и расходы ─────────────

    private static function finance(int $days): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $series = self::financeSeries($days);
        $totals = [];
        foreach (['subs', 'income_other', 'ai', 'expense_other', 'income', 'expense', 'profit'] as $k) {
            $totals[$k] = self::sum($series, $k);
        }
        return [
            'entries' => array_map(fn($e) => ['id' => (int)$e['id'], 'date' => Api::iso($e['date']), 'kind' => $e['kind'], 'category' => $e['category'],
                'amount_usd' => (float)$e['amount_usd'], 'note' => $e['note'], 'by' => $e['created_by']],
                DB::all('SELECT * FROM finance_entries WHERE date >= ? ORDER BY date DESC', [$since])),
            'ai_usage' => array_map(fn($r) => ['model' => $r['model'], 'kind' => $r['kind'], 'calls' => (int)$r['calls'],
                'input_tokens' => (int)$r['i'], 'output_tokens' => (int)$r['o'], 'cost_usd' => round((float)$r['c'], 2)],
                DB::all('SELECT model, kind, COUNT(*) calls, SUM(input_tokens) i, SUM(output_tokens) o, SUM(cost_usd) c
                    FROM ai_usage WHERE ts >= ? GROUP BY model, kind', [$since])),
            'totals' => $totals,
            'series' => $series,
        ];
    }

    private static function financeAdd(array $b, string $admin): array
    {
        $kind = in_array($b['kind'] ?? '', ['income', 'expense'], true) ? $b['kind'] : Api::fail(400, 'Тип: income или expense');
        $amount = (float)($b['amount_usd'] ?? 0);
        if ($amount <= 0 || $amount > 10_000_000) {
            Api::fail(400, 'Сумма должна быть больше нуля');
        }
        $date = !empty($b['date']) && strtotime((string)$b['date']) ? gmdate('Y-m-d H:i:s', strtotime((string)$b['date'])) : DB::now();
        DB::insert('finance_entries', ['date' => $date, 'kind' => $kind, 'category' => Api::str($b, 'category', 1, 32),
            'amount_usd' => $amount, 'note' => mb_substr((string)($b['note'] ?? ''), 0, 255), 'created_by' => $admin]);
        self::audit($admin, 'finance.add', "$kind {$b['category']} $amount");
        return ['ok' => true];
    }

    // ───────────── настройки и ключи ─────────────

    private static function mask(string $v): string
    {
        return $v === '' ? '' : str_repeat('•', 8) . mb_substr($v, -4);
    }

    private static function getSettings(): array
    {
        $s = Settings::all(true);
        $out = [];
        foreach (Settings::FIELDS as $k => [$type, , $label, $group, $secret, $help]) {
            $v = $s[$k];
            $out[] = ['key' => $k, 'label' => $label, 'group' => $group, 'type' => $type, 'secret' => $secret,
                'restart' => in_array($k, ['symbols'], true), 'help' => $help, 'is_set' => $v !== '' && $v !== [],
                'value' => $secret ? self::mask((string)$v) : ($type === 'list' ? implode(',', $v) : $v)];
        }
        return $out;
    }

    private static function putSettings(array $b, string $admin): array
    {
        $old = Settings::all(true);
        $changes = [];
        foreach ($b as $k => $v) {
            if (!isset(Settings::FIELDS[$k])) {
                continue;
            }
            if (Settings::FIELDS[$k][4] && is_string($v) && str_starts_with($v, '•')) {
                continue;                                    // маска — значение не меняли
            }
            $v = Settings::coerce($k, $v);
            if ($k === 'ai_interval_min' && ($v < 5 || $v > 1440)) {
                Api::fail(400, 'Интервал ИИ: от 5 до 1440 минут');
            }
            if (str_starts_with($k, 'price_') && ($v < 1 || $v > 100000)) {
                Api::fail(400, 'Цена в звёздах: от 1 до 100000');
            }
            if ($v !== $old[$k]) {
                $changes[$k] = $v;
            }
        }
        Settings::save($changes);
        $notes = [];
        if (isset($changes['symbols'])) {
            MiniApi::command('restart_engine');
            $notes[] = 'Список монет изменён — торговый движок перезапускается';
        }
        if ((isset($changes['bot_token']) || isset($changes['webapp_url'])) && Settings::get('bot_token') !== '') {
            try {
                Telegram::setup(self::baseUrl());
                $notes[] = 'Telegram-бот подключён (webhook и кнопка меню)';
            } catch (\Throwable $e) {
                $notes[] = 'Telegram: ' . $e->getMessage();
            }
        }
        self::audit($admin, 'settings.update', implode(', ', array_keys($changes)));
        return ['ok' => true, 'changed' => array_keys($changes), 'notes' => $notes];
    }

    /** Внешний адрес установки (с учётом подпапки, например https://site.com/izidva). */
    public static function baseUrl(): string
    {
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach (['/admin/api/index.php', '/api/index.php', '/migrate.php', '/tg/webhook.php', '/admin/'] as $marker) {
            $pos = strpos($uri, $marker);
            if ($pos !== false) {
                $uri = substr($uri, 0, $pos);
                break;
            }
        }
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim($uri, '/');
    }

    private static function restartEngine(string $admin): array
    {
        MiniApi::command('restart_engine');
        self::audit($admin, 'engine.restart');
        return ['ok' => true];
    }

    // ───────────── ИИ и рынок ─────────────

    private static function aiState(): array
    {
        $tunable = [];
        foreach (AIAnalyst::TUNABLE as $k => [$lo, $hi, $def]) {
            $tunable[$k] = ['min' => $lo, 'max' => $hi, 'default' => $def];
        }
        return [
            'market' => MiniApi::marketView(),
            'engine' => self::engineState(),
            'symbols' => Settings::get('symbols'),
            'lessons' => array_map(fn($l) => ['id' => (int)$l['id'], 'text' => $l['text'], 'at' => Api::iso($l['ts'])],
                DB::all('SELECT * FROM lessons ORDER BY id DESC LIMIT 50')),
            'tuning' => array_map(fn($t) => ['symbol' => $t['symbol'], 'params' => json_decode($t['params'], true) ?: (object)[]],
                DB::all('SELECT * FROM symbol_tuning')),
            'tunable' => $tunable,
            'stats' => array_map(fn($s) => ['key' => $s['key'], 'n' => (int)$s['n'], 'winrate' => $s['n'] ? (int)round($s['wins'] / $s['n'] * 100) : 0,
                'ewma_r' => round((float)$s['ewma_r'], 3)], DB::all('SELECT * FROM strategy_stats ORDER BY n DESC LIMIT 200')),
            'history' => array_map(fn($i) => ['symbol' => $i['symbol'], 'regime' => $i['regime'], 'source' => $i['source'], 'at' => Api::iso($i['ts']),
                'summary' => json_decode($i['payload'], true)['summary'] ?? ''], DB::all('SELECT * FROM ai_insights ORDER BY id DESC LIMIT 60')),
        ];
    }

    private static function aiRun(string $admin): array
    {
        MiniApi::command('force_ai');
        self::audit($admin, 'ai.run');
        return ['ok' => true];
    }

    private static function lessonAdd(array $b, string $admin): array
    {
        $text = Api::str($b, 'text', 3, 500);
        DB::insert('lessons', ['ts' => DB::now(), 'text' => $text]);
        self::audit($admin, 'ai.lesson_add', mb_substr($text, 0, 100));
        return ['ok' => true];
    }

    private static function tuning(string $symbol, array $b, string $admin): array
    {
        $params = [];
        foreach ($b as $k => $v) {
            if (isset(AIAnalyst::TUNABLE[$k])) {
                [$lo, $hi] = AIAnalyst::TUNABLE[$k];
                $params[$k] = round(max($lo, min($hi, (float)$v)), 4);
            }
        }
        DB::q('INSERT INTO symbol_tuning (symbol, params, updated_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE params = VALUES(params), updated_at = VALUES(updated_at)', [$symbol, json_encode($params), DB::now()]);
        self::audit($admin, 'ai.tuning', "$symbol " . json_encode($params));
        return ['ok' => true, 'params' => $params];
    }

    // ───────────── рассылка ─────────────

    /** Оставляет только теги, которые понимает Telegram (parse_mode=HTML). */
    private static function sanitizeTelegramHtml(string $s): string
    {
        return strip_tags($s, '<b><strong><i><em><u><ins><s><strike><del><code><pre><a><tg-spoiler>');
    }

    /** Декодирует data:image/...;base64,... во временный файл. null, если картинки нет. */
    private static function decodeBroadcastImage(mixed $dataUrl): ?string
    {
        if (!is_string($dataUrl) || $dataUrl === '') {
            return null;
        }
        if (!preg_match('/^data:image\/(jpeg|jpg|png|webp|gif);base64,(.+)$/s', $dataUrl, $mm)) {
            Api::fail(400, 'Неподдерживаемый формат картинки (jpeg, png, webp, gif)');
        }
        $bin = base64_decode($mm[2], true);
        if ($bin === false || strlen($bin) > 10 * 1024 * 1024) {
            Api::fail(400, 'Картинка повреждена или больше 10 МБ');
        }
        $path = STORAGE_DIR . '/tmp_broadcast_' . bin2hex(random_bytes(6)) . '.' . ($mm[1] === 'jpg' ? 'jpeg' : $mm[1]);
        file_put_contents($path, $bin);
        return $path;
    }

    private static function broadcast(array $b, string $admin): array
    {
        if (Settings::get('bot_token') === '') {
            Api::fail(400, 'Telegram-бот не настроен — задайте токен в настройках');
        }
        $text = self::sanitizeTelegramHtml(Api::str($b, 'text', 1, 4000));
        $aud = (string)($b['audience'] ?? 'all');
        $btnText = trim((string)($b['button_text'] ?? ''));
        $btnUrl = trim((string)($b['button_url'] ?? ''));
        $markup = null;
        if ($btnText !== '' && $btnUrl !== '') {
            if (!preg_match('#^https?://#i', $btnUrl)) {
                Api::fail(400, 'Ссылка на кнопке должна начинаться с http(s)://');
            }
            $markup = ['inline_keyboard' => [[['text' => mb_substr($btnText, 0, 64), 'url' => $btnUrl]]]];
        }
        $imagePath = self::decodeBroadcastImage($b['image'] ?? null);
        $sql = match ($aud) {
            'subscribers' => 'SELECT id FROM users WHERE blocked = 0 AND sub_until > UTC_TIMESTAMP()',
            'no_subscription' => 'SELECT id FROM users WHERE blocked = 0 AND (sub_until IS NULL OR sub_until <= UTC_TIMESTAMP())',
            'running' => 'SELECT u.id FROM users u JOIN bot_settings b ON b.user_id = u.id WHERE u.blocked = 0 AND b.running = 1',
            default => 'SELECT id FROM users WHERE blocked = 0',
        };
        $ids = array_column(DB::all($sql), 'id');
        // отвечаем сразу, рассылка продолжается после ответа (PHP-FPM)
        Api::send(['ok' => true, 'recipients' => count($ids)]);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        ignore_user_abort(true);
        set_time_limit(0);
        $ok = 0;
        foreach ($ids as $id) {
            $ok += ($imagePath ? Telegram::sendPhoto((int)$id, $imagePath, $text, $markup) : Telegram::send((int)$id, $text, $markup)) ? 1 : 0;
            usleep(50_000);                                 // ~20 сообщений в секунду — лимит Telegram
        }
        if ($imagePath) {
            @unlink($imagePath);
        }
        self::audit($admin, 'broadcast.done', "$aud: $ok/" . count($ids));
        exit;
    }

    // ───────────── меню бота ─────────────

    private static function menuList(): array
    {
        return DB::all('SELECT id, title, url, sort_order, enabled FROM bot_menu_buttons ORDER BY sort_order, id');
    }

    private static function menuAdd(array $b, string $admin): array
    {
        $title = Api::str($b, 'title', 1, 64);
        $url = Api::str($b, 'url', 4, 512);
        if (!preg_match('#^https?://#i', $url)) {
            Api::fail(400, 'Ссылка должна начинаться с http(s)://');
        }
        $order = (int)DB::val('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM bot_menu_buttons');
        $id = DB::insert('bot_menu_buttons', ['title' => $title, 'url' => $url, 'sort_order' => $order, 'enabled' => true, 'created_at' => DB::now()]);
        self::audit($admin, 'menu.add', "$title -> $url");
        return ['ok' => true, 'id' => $id];
    }

    private static function menuUpdate(int $id, array $b, string $admin): array
    {
        $upd = [];
        if (isset($b['title'])) {
            $upd['title'] = Api::str($b, 'title', 1, 64);
        }
        if (isset($b['url'])) {
            $url = Api::str($b, 'url', 4, 512);
            if (!preg_match('#^https?://#i', $url)) {
                Api::fail(400, 'Ссылка должна начинаться с http(s)://');
            }
            $upd['url'] = $url;
        }
        if (isset($b['enabled'])) {
            $upd['enabled'] = (bool)$b['enabled'];
        }
        if (isset($b['sort_order'])) {
            $upd['sort_order'] = (int)$b['sort_order'];
        }
        if ($upd) {
            DB::update('bot_menu_buttons', $upd, 'id = :id', [':id' => $id]);
        }
        self::audit($admin, 'menu.update', (string)$id);
        return ['ok' => true];
    }

    // ───────────── журнал ─────────────

    private static function logs(int $lines): array
    {
        $tail = '';
        $f = Log::file();
        if (is_file($f)) {
            $all = file($f);
            $tail = implode('', array_slice($all, -$lines));
        }
        return [
            'audit' => array_map(fn($r) => ['at' => Api::iso($r['ts']), 'actor' => $r['actor'], 'action' => $r['action'], 'details' => $r['details']],
                DB::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300')),
            'app_log' => $tail,
        ];
    }

    // ───────────── администраторы ─────────────

    private static function admins(): array
    {
        return array_map(fn($a) => ['id' => (int)$a['id'], 'username' => $a['username'], 'created_at' => Api::iso($a['created_at']),
            'last_login' => Api::iso($a['last_login'])], DB::all('SELECT * FROM admin_users ORDER BY id'));
    }

    private static function adminAdd(array $b, string $admin): array
    {
        $user = Api::str($b, 'username', 3, 64);
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $user)) {
            Api::fail(400, 'Логин: латиница, цифры, _ . -');
        }
        $pass = Api::str($b, 'password', 8, 128);
        if (DB::val('SELECT 1 FROM admin_users WHERE username = ?', [$user])) {
            Api::fail(400, 'Такой логин уже есть');
        }
        DB::insert('admin_users', ['username' => $user, 'password_hash' => Crypto::hashPassword($pass), 'created_at' => DB::now()]);
        self::audit($admin, 'admin.add', $user);
        return ['ok' => true];
    }

    private static function adminDelete(int $id, array $me): array
    {
        if ($id === (int)$me['id']) {
            Api::fail(400, 'Нельзя удалить самого себя');
        }
        DB::q('DELETE FROM admin_users WHERE id = ?', [$id]);
        self::audit($me['username'], 'admin.delete', (string)$id);
        return ['ok' => true];
    }

    private static function changePassword(array $b, array $me): array
    {
        if (!Crypto::checkPassword((string)($b['old_password'] ?? ''), $me['password_hash'])) {
            Api::fail(400, 'Старый пароль неверный');
        }
        $new = Api::str($b, 'new_password', 8, 128);
        DB::update('admin_users', ['password_hash' => Crypto::hashPassword($new)], 'id = :id', [':id' => $me['id']]);
        self::audit($me['username'], 'admin.password');
        return ['ok' => true];
    }
}
