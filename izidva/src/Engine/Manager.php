<?php
declare(strict_types=1);

namespace App\Engine;

use App\Crypto;
use App\DB;
use App\Log;
use App\Settings;
use App\Telegram;

/**
 * Торговый демон (bin/daemon.php): рынок + процессы всех клиентов в одном цикле.
 * Связь с веб-частью — через БД: bot_settings (кто должен торговать), engine_commands (команды из админки),
 * worker_state и engine_status (состояние для интерфейса). ИИ работает в дочернем процессе bin/ai_worker.php.
 */
final class Manager
{
    public const TICK = 3.0;

    public Market $market;
    public Brain $brain;
    /** @var array<int,Worker> */
    public array $workers = [];
    private array $ts = ['sync' => 0, 'insights' => 0, 'publish' => 0, 'heartbeat' => 0, 'ping' => 0, 'rules' => 0];
    private ?string $summaryDay = null;
    /** @var resource|null */
    private $aiProc = null;
    private float $aiSpawnTs = 0.0;
    private bool $stop = false;
    private int $exitCode = 0;

    public function __construct(?Market $market = null)
    {
        $this->market = $market ?? new Market(Settings::get('symbols'));
        $this->brain = new Brain(new Learner());
    }

    // ───────────── запуск ─────────────

    public function run(): int
    {
        $this->brain->learner->load();
        $this->loadTuning();
        // На части хостингов расширение pcntl частично отключено через disable_functions —
        // проверяем каждую функцию отдельно и оборачиваем в try/catch, чтобы демон не падал.
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal') && defined('SIGTERM')) {
            try {
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, fn() => $this->stop = true);
                pcntl_signal(SIGINT, fn() => $this->stop = true);
            } catch (\Throwable $e) {
                Log::warn('pcntl недоступен, мягкая остановка по сигналу отключена: ' . $e->getMessage());
            }
        } else {
            Log::warn('pcntl недоступен (отключён на хостинге) — мягкая остановка по SIGTERM работать не будет, только systemd kill.');
        }
        DB::q('INSERT INTO engine_status (id, heartbeat_at, started_at, pid) VALUES (1, ?, ?, ?)
               ON DUPLICATE KEY UPDATE heartbeat_at = VALUES(heartbeat_at), started_at = VALUES(started_at), pid = VALUES(pid)',
            [DB::now(), DB::now(), getmypid()]);
        Log::info('Торговый демон запущен: ' . implode(', ', $this->market->symbols));
        while (!$this->stop) {
            $started = microtime(true);
            try {
                $this->tick($started);
            } catch (\Throwable $e) {
                Log::error('демон: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                DB::reset();
                sleep(5);
            }
            $sleep = self::TICK - (microtime(true) - $started);
            if ($sleep > 0) {
                usleep((int)($sleep * 1e6));
            }
        }
        $this->shutdownAll();
        $this->stopAiWorker();
        Log::info('Торговый демон остановлен');
        return $this->exitCode;
    }

    public function tick(float $now): void
    {
        if ($now - $this->ts['ping'] > 60) {
            DB::ping();
            Settings::all(true);                           // подхватываем изменения из админ-панели
            $this->ts['ping'] = $now;
        }
        $this->handleCommands();
        $this->market->tick();
        $this->refreshFeatures();
        if ($now - $this->ts['insights'] > 30) {
            $this->loadInsights($now);
            $this->ts['insights'] = $now;
        }
        if ($now - $this->ts['sync'] > 10) {
            $this->syncWorkers();
            $this->ts['sync'] = $now;
        }
        foreach ($this->workers as $uid => $w) {
            try {
                $w->step($now);
            } catch (\Throwable $e) {
                $w->status = 'ошибка: ' . $e->getMessage();
                Log::error("user $uid: " . $e->getMessage());
            }
        }
        if ($now - $this->ts['publish'] > 10) {
            $this->publish();
            $this->ts['publish'] = $now;
        }
        $this->ensureAiWorker();
        $this->daySummaries();
    }

    // ───────────── рынок и ИИ ─────────────

    public function refreshFeatures(): void
    {
        foreach ($this->market->feeds as $sym => $feed) {
            $f = Indicators::features($feed->klines, $feed->price);
            if ($f) {
                $this->brain->features[$sym] = $f;
                $this->brain->insights[$sym] ??= AIAnalyst::ruleInsight($f, $this->brain->tuning[$sym] ?? []);
            }
        }
    }

    private function loadTuning(): void
    {
        foreach (DB::all('SELECT symbol, params FROM symbol_tuning') as $r) {
            $this->brain->tuning[$r['symbol']] = json_decode($r['params'], true) ?: [];
        }
    }

    /** Свежие выводы ИИ из БД; если ИИ выключен или анализ устарел — алгоритм. */
    public function loadInsights(float $now): void
    {
        $this->loadTuning();
        $interval = max(5, (int)Settings::get('ai_interval_min'));
        $fresh = gmdate('Y-m-d H:i:s', (int)$now - $interval * 60 * 3);
        $rows = DB::all("SELECT i.symbol, i.payload FROM ai_insights i
            JOIN (SELECT symbol, MAX(id) id FROM ai_insights WHERE source = 'ai' AND ts >= ? GROUP BY symbol) last ON last.id = i.id", [$fresh]);
        $ai = [];
        foreach ($rows as $r) {
            $ai[$r['symbol']] = AIAnalyst::clamp(json_decode($r['payload'], true) ?: [], 'ai');
        }
        $writeRules = $now - $this->ts['rules'] > $interval * 60;
        foreach ($this->brain->features as $sym => $f) {
            if (isset($ai[$sym])) {
                $this->brain->insights[$sym] = $ai[$sym];
                continue;
            }
            $ins = AIAnalyst::ruleInsight($f, $this->brain->tuning[$sym] ?? []);
            $this->brain->insights[$sym] = $ins;
            if ($writeRules) {                              // история в админке, когда ИИ не работает
                DB::insert('ai_insights', ['symbol' => $sym, 'ts' => DB::now(), 'source' => 'rules', 'regime' => $ins['regime'], 'payload' => $ins]);
            }
        }
        if ($writeRules && $this->brain->features) {
            $this->ts['rules'] = $now;
        }
    }

    /** ИИ-анализ идёт в отдельном процессе, чтобы долгие запросы к Claude не тормозили торговлю. */
    private function ensureAiWorker(): void
    {
        if ($this->aiProc && proc_get_status($this->aiProc)['running']) {
            return;
        }
        if ($this->aiProc) {
            proc_close($this->aiProc);
            $this->aiProc = null;
        }
        if (microtime(true) - $this->aiSpawnTs < 30) {
            return;                                         // не перезапускаем чаще раза в 30 секунд
        }
        $this->aiSpawnTs = microtime(true);
        $script = realpath(__DIR__ . '/../../bin/ai_worker.php');
        $this->aiProc = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes) ?: null;
    }

    private function stopAiWorker(): void
    {
        if ($this->aiProc) {
            proc_terminate($this->aiProc);
            proc_close($this->aiProc);
            $this->aiProc = null;
        }
    }

    // ───────────── клиенты ─────────────

    public function syncWorkers(): void
    {
        $rows = DB::all('SELECT b.*, u.sub_until, u.blocked, a.api_key_enc, a.api_secret_enc, a.mode AS ex_mode
            FROM bot_settings b JOIN users u ON u.id = b.user_id LEFT JOIN exchange_accounts a ON a.user_id = b.user_id');
        $should = [];
        $engineOn = (bool)Settings::get('engine_enabled');
        foreach ($rows as $r) {
            $hasSub = $r['sub_until'] && strtotime($r['sub_until'] . ' UTC') > time();
            $ok = $engineOn && $r['running'] && !$r['blocked']
                && ($r['trading_mode'] === 'paper' || ($r['api_key_enc'] && $hasSub));
            if ($ok) {
                $should[(int)$r['user_id']] = $r;
            }
        }
        foreach (array_keys($this->workers) as $uid) {
            if (!isset($should[$uid])) {
                $this->stopWorker($uid);
            }
        }
        foreach ($should as $uid => $r) {
            if (!isset($this->workers[$uid])) {
                $this->startWorker($uid, $r);
            }
        }
    }

    private function startWorker(int $uid, array $r): void
    {
        try {
            if ($r['trading_mode'] === 'paper') {
                $ex = new PaperExchange((float)$r['paper_balance']);
            } else {
                $ex = new BybitExchange(Crypto::decrypt($r['api_key_enc']), Crypto::decrypt($r['api_secret_enc']), $r['ex_mode']);
                $ex->prepare();
            }
        } catch (\Throwable $e) {
            DB::update('bot_settings', ['running' => false, 'status_text' => 'ошибка подключения к Bybit: ' . $e->getMessage()], 'user_id = :u', [':u' => $uid]);
            $this->notify($uid, '⚠️ Не удалось подключиться к Bybit: ' . $e->getMessage());
            return;
        }
        $symbols = json_decode((string)$r['symbols'], true) ?: Settings::get('default_symbols');
        $strategies = json_decode((string)$r['strategies'], true) ?: [];
        $this->workers[$uid] = new Worker($uid, $ex, $this->market, $this->brain, Risk::profile($r['risk_profile']), $symbols,
            $strategies, fn($u, $t) => $this->recordTrade($u, $t), fn($u, $m) => $this->notify($u, $m),
            fn($u, $e) => $this->saveSnapshot($u, $e));
        Log::info("user $uid: запущен ({$r['trading_mode']}, {$r['risk_profile']})");
    }

    public function stopWorker(int $uid): void
    {
        $w = $this->workers[$uid] ?? null;
        if (!$w) {
            return;
        }
        $w->shutdown();
        unset($this->workers[$uid]);
        DB::update('worker_state', ['status' => 'остановлен', 'live' => ['grids' => [], 'directional' => []], 'updated_at' => DB::now()],
            'user_id = :u', [':u' => $uid]);
        Log::info("user $uid: остановлен");
    }

    private function shutdownAll(): void
    {
        foreach (array_keys($this->workers) as $uid) {
            $this->stopWorker($uid);
        }
    }

    /** Команды из админ-панели и мини-аппа. force_ai обрабатывает процесс ИИ. */
    private function handleCommands(): void
    {
        foreach (DB::all("SELECT * FROM engine_commands WHERE done_at IS NULL AND cmd <> 'force_ai' ORDER BY id") as $c) {
            DB::update('engine_commands', ['done_at' => DB::now()], 'id = :id', [':id' => $c['id']]);
            if ($c['cmd'] === 'restart_user') {
                $this->stopWorker((int)$c['arg']);          // заново поднимется в syncWorkers
            } elseif ($c['cmd'] === 'restart_engine') {
                $this->stop = true;
                $this->exitCode = RESTART_CODE;
            }
            $this->ts['sync'] = 0;
        }
    }

    // ───────────── запись и публикация ─────────────

    public function recordTrade(int $uid, array $t): void
    {
        DB::insert('trades', ['user_id' => $uid, 'symbol' => $t['symbol'], 'strategy' => $t['strategy'], 'side' => $t['side'],
            'qty' => $t['qty'], 'entry' => $t['entry'], 'exit' => $t['exit'], 'pnl' => $t['pnl'], 'r' => $t['r'],
            'regime' => $t['regime'], 'mode' => $t['mode'], 'opened_at' => DB::now(), 'closed_at' => DB::now()]);
        if (isset($t['paper_balance'])) {
            DB::update('bot_settings', ['paper_balance' => $t['paper_balance']], 'user_id = :u', [':u' => $uid]);
        }
        $this->brain->learner->record($t['symbol'], $t['strategy'], $t['regime'] ?: 'range', (float)$t['r']);
    }

    public function saveSnapshot(int $uid, float $equity): void
    {
        DB::insert('equity_snapshots', ['user_id' => $uid, 'ts' => DB::now(), 'equity' => $equity]);
    }

    public function notify(int $uid, string $text): void
    {
        if (Settings::get('bot_token') !== '') {
            Telegram::send($uid, $text);
        }
    }

    public function publish(): void
    {
        foreach ($this->workers as $uid => $w) {
            DB::q('INSERT INTO worker_state (user_id, status, equity, day_pnl_pct, live, updated_at) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE status = VALUES(status), equity = VALUES(equity), day_pnl_pct = VALUES(day_pnl_pct),
                   live = VALUES(live), updated_at = VALUES(updated_at)',
                [$uid, $w->status, $w->equity, round($w->guard->dayPnlPct($w->equity), 2),
                    json_encode($w->liveState(), JSON_UNESCAPED_UNICODE), DB::now()]);
        }
        $symbols = [];
        foreach ($this->market->feeds as $sym => $feed) {
            $symbols[$sym] = [
                'price' => $feed->price, 'funding' => $feed->funding, 'has_klines' => (bool)$feed->klines,
                'liq_long_1h' => round($feed->liqSum('long', 3600)), 'liq_short_1h' => round($feed->liqSum('short', 3600)),
                'features' => $this->brain->features[$sym] ?? null,
                'insight' => $this->brain->insights[$sym] ?? null,
            ];
        }
        DB::q('UPDATE engine_status SET heartbeat_at = ?, state = ? WHERE id = 1', [DB::now(), json_encode([
            'workers' => count($this->workers), 'ws' => $this->market->wsConnected(), 'symbols' => $symbols,
        ], JSON_UNESCAPED_UNICODE)]);
    }

    /** Итог дня клиентам в Telegram (после 00:05 UTC). */
    private function daySummaries(): void
    {
        $today = gmdate('Y-m-d');
        if ($this->summaryDay === $today || (int)gmdate('Gi') < 5) {
            return;
        }
        if ($this->summaryDay === null) {
            $this->summaryDay = $today;                     // после перезапуска не рассылаем повторно
            return;
        }
        $this->summaryDay = $today;
        $rows = DB::all('SELECT user_id, SUM(pnl) pnl, COUNT(*) n, SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END) wins
            FROM trades WHERE closed_at >= ? GROUP BY user_id', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        foreach ($rows as $r) {
            $this->notify((int)$r['user_id'], sprintf('📊 Итог дня: %+.2f USDT · сделок %d · прибыльных %d', $r['pnl'], $r['n'], $r['wins']));
        }
    }
}
