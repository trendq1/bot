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
        $this->processManualOrders();
        $this->market->tick();
        $this->processSignals();
        $analysis = (bool)Settings::get('analysis_enabled');
        if ($analysis) {
            $this->refreshFeatures();
            if ($now - $this->ts['insights'] > 30) {
                $this->loadInsights($now);
                $this->ts['insights'] = $now;
            }
        }
        if ($now - $this->ts['sync'] > 10) {
            $this->syncWorkers();
            $this->ts['sync'] = $now;
        }
        $this->applyModes($analysis);
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

    /**
     * Глобальные режимы платформы на воркеры: аварийная остановка и разрешённые стратегии. Анализ выключен —
     * все автостратегии запрещены, режим рынка принудительно NO_TRADE (ручная торговля и сигналы не затрагиваются).
     */
    private function applyModes(bool $analysis): void
    {
        $platform = $analysis
            ? ['grid' => (bool)Settings::get('strategy_grid_enabled'), 'trend' => (bool)Settings::get('strategy_trend_enabled'),
                'liquidation' => (bool)Settings::get('strategy_liquidation_enabled'), 'breakout' => (bool)Settings::get('strategy_breakout_enabled')]
            : ['grid' => false, 'trend' => false, 'liquidation' => false, 'breakout' => false];
        if (!$analysis) {
            foreach ($this->market->symbols as $sym) {
                $this->brain->regimes[$sym] = Regime::NO_TRADE;
            }
        }
        $halt = (bool)Settings::get('kill_switch');
        foreach ($this->workers as $w) {
            $w->halted = $halt;
            $w->platformEnabled = $platform;
        }
    }

    // ───────────── рынок и ИИ ─────────────

    public function refreshFeatures(): void
    {
        foreach ($this->market->symbols as $sym) {
            $this->brain->refresh($sym, $this->market->feeds[$sym]);
            if (isset($this->brain->features[$sym])) {
                $this->brain->insights[$sym] ??= AIAnalyst::ruleInsight($this->brain->features[$sym], $this->brain->tuning[$sym] ?? []);
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
            fn($u, $e) => $this->saveSnapshot($u, $e), (bool)($r['manual_mode'] ?? false),
            fn($id, $status, $detail) => $this->updateManualOrder($id, $status, $detail), (float)($r['budget_mult'] ?? 1.0));
        $saved = DB::val('SELECT risk_state FROM worker_state WHERE user_id = ?', [$uid]);
        if ($saved) {
            $this->workers[$uid]->importState(json_decode((string)$saved, true) ?: []);
        }
        Log::info("user $uid: запущен ({$r['trading_mode']}, {$r['risk_profile']}" . (($r['manual_mode'] ?? false) ? ', ручной режим' : '') . ')');
    }

    private function saveWorkerState(int $uid, Worker $w): void
    {
        DB::q('INSERT INTO worker_state (user_id, status, equity, day_pnl_pct, live, risk_state, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE status = VALUES(status), equity = VALUES(equity), day_pnl_pct = VALUES(day_pnl_pct),
               live = VALUES(live), risk_state = VALUES(risk_state), updated_at = VALUES(updated_at)',
            [$uid, $w->status, $w->equity, round($w->guard->dayPnlPct($w->equity), 2),
                json_encode($w->liveState(), JSON_UNESCAPED_UNICODE), json_encode($w->exportState()), DB::now()]);
    }

    public function stopWorker(int $uid): void
    {
        $w = $this->workers[$uid] ?? null;
        if (!$w) {
            return;
        }
        $w->shutdown();
        $this->saveWorkerState($uid, $w);
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
            } elseif ($c['cmd'] === 'reset_stats') {
                try {
                    if (strtotime($c['created_at'] . ' UTC') < time() - 600) {
                        throw new \RuntimeException('команда просрочена (движок был остановлен) — повторите сброс');
                    }
                    $res = $this->resetStats(json_decode((string)$c['arg'], true) ?: []);
                    DB::update('engine_commands', ['result' => 'ok: ' . $res], 'id = :id', [':id' => $c['id']]);
                } catch (\Throwable $e) {
                    Log::warn('reset_stats: ' . $e->getMessage());
                    DB::update('engine_commands', ['result' => 'error: ' . $e->getMessage()], 'id = :id', [':id' => $c['id']]);
                }
            } elseif ($c['cmd'] === 'edit_stops' || $c['cmd'] === 'cancel_order') {
                $a = json_decode((string)$c['arg'], true) ?: [];
                $w = $this->workers[(int)($a['user_id'] ?? 0)] ?? null;
                try {
                    if (!$w) {
                        throw new \RuntimeException('клиент не подключён — бот остановлен или биржа не подключена');
                    }
                    $res = $c['cmd'] === 'edit_stops'
                        ? $w->editStops((string)$a['symbol'], isset($a['stop']) ? (float)$a['stop'] : null, isset($a['take']) ? (float)$a['take'] : null)
                        : $w->cancelOrder((string)$a['symbol'], (string)$a['link']);
                    DB::update('engine_commands', ['result' => 'ok: ' . $res], 'id = :id', [':id' => $c['id']]);
                } catch (\Throwable $e) {
                    DB::update('engine_commands', ['result' => 'error: ' . $e->getMessage()], 'id = :id', [':id' => $c['id']]);
                }
            } elseif ($c['cmd'] === 'manual_close') {
                [$uid, $sym] = array_pad(explode(':', (string)$c['arg'], 2), 2, '');
                $w = $this->workers[(int)$uid] ?? null;
                try {
                    if (!$w) {
                        throw new \RuntimeException('клиент не подключён — бот остановлен или биржа не подключена');
                    }
                    $w->closeManual($sym);
                    DB::update('engine_commands', ['result' => 'ok: закрытие отправлено'], 'id = :id', [':id' => $c['id']]);
                } catch (\Throwable $e) {
                    Log::warn("manual_close user $uid $sym: " . $e->getMessage());
                    DB::update('engine_commands', ['result' => 'error: ' . $e->getMessage()], 'id = :id', [':id' => $c['id']]);
                }
            }
            $this->ts['sync'] = 0;
            $this->ts['publish'] = 0;                        // админка сразу увидит результат команды
        }
    }

    /** Ручные ордера трейдера из админ-панели (таблица manual_orders): новые заявки и запросы на отмену лимиток. */
    private function processManualOrders(): void
    {
        foreach (DB::all("SELECT * FROM manual_orders WHERE status = 'pending' ORDER BY id") as $o) {
            $w = $this->workers[(int)$o['user_id']] ?? null;
            if (!$w) {
                $this->updateManualOrder((int)$o['id'], 'error', 'клиент не подключён — бот остановлен или биржа не подключена');
                continue;
            }
            $order = ['id' => (int)$o['id'], 'symbol' => $o['symbol'], 'side' => $o['side'], 'order_type' => $o['order_type'],
                'qty' => (float)$o['qty'], 'price' => $o['price'] !== null ? (float)$o['price'] : null,
                'stop_loss' => $o['stop_loss'] !== null ? (float)$o['stop_loss'] : null,
                'take_profit' => $o['take_profit'] !== null ? (float)$o['take_profit'] : null,
                'leverage' => $o['leverage'] !== null ? (int)$o['leverage'] : null];
            try {
                $r = $w->manualOrder($order);
                $this->updateManualOrder((int)$o['id'], $r['status'] === 'placed' ? 'open' : 'done', $r['detail']);
            } catch (\Throwable $e) {
                $this->updateManualOrder((int)$o['id'], 'error', $e->getMessage());
            }
        }
        foreach (DB::all("SELECT * FROM manual_orders WHERE status = 'cancel_requested' ORDER BY id") as $o) {
            $w = $this->workers[(int)$o['user_id']] ?? null;
            if ($w && $w->cancelManualOrder($o['symbol'], (int)$o['id'])) {
                $this->updateManualOrder((int)$o['id'], 'cancelled', 'отменено трейдером');
            } else {
                $this->updateManualOrder((int)$o['id'], 'done', 'ордер уже не активен (исполнен или клиент офлайн)');
            }
        }
    }

    /**
     * Обнуление показателей (команда из админки; выполняет демон — единственный, кто пишет сделки, поэтому гонок нет).
     *  trading: сделки, обучение (strategy_stats и кэш), снимки баланса, сигналы, ручные ордера, состояние риска клиентов;
     *           демо-балансы возвращаются к стартовому, воркеры пересоздаются с нуля. Позиции на биржах (demo/live) не трогаются.
     *  finance: платежи и ручные доходы/расходы. Расходы на ИИ (ai_usage) НЕ трогаются никогда. Платежи не стираются, пока есть
     *           неоплаченные реферальные начисления — они каскадно удаляются вместе с платежом, а это долг перед людьми.
     * @param array{trading?:bool,finance?:bool} $opt
     */
    public function resetStats(array $opt): string
    {
        $done = [];
        if (!empty($opt['finance'])) {
            $unpaid = (float)DB::val('SELECT COALESCE(SUM(amount_usd), 0) FROM referral_earnings WHERE paid = 0');
            if ($unpaid > 0) {
                throw new \RuntimeException(sprintf('нельзя стереть платежи: есть неоплаченные реферальные начисления на %.2f$ — сначала отметьте их выплаченными', $unpaid));
            }
        }
        if (!empty($opt['trading'])) {
            foreach (array_keys($this->workers) as $uid) {
                if ($this->workers[$uid]->ex->mode() !== 'paper') {
                    $this->workers[$uid]->shutdown();            // реальные позиции остаются на бирже под своими стопами
                }
                unset($this->workers[$uid]);                      // бумажный счёт живёт только в памяти — просто отбрасываем
            }
            $n = [];
            foreach (['trades', 'strategy_stats', 'equity_snapshots', 'signals', 'manual_orders'] as $t) {
                $n[$t] = (int)DB::val("SELECT COUNT(*) FROM $t");
                DB::q("DELETE FROM $t");
            }
            DB::q('DELETE FROM worker_state');
            DB::q('UPDATE bot_settings SET paper_balance = ?', [(float)Settings::get('paper_start_balance')]);
            $this->brain->learner->cache = [];
            $this->ts['sync'] = 0;
            $done[] = "торговля: сделок {$n['trades']}, снимков баланса {$n['equity_snapshots']}, сигналов {$n['signals']}, ручных ордеров {$n['manual_orders']}, записей обучения {$n['strategy_stats']}; демо-балансы = " . Settings::get('paper_start_balance') . '$';
        }
        if (!empty($opt['finance'])) {
            $p = (int)DB::val('SELECT COUNT(*) FROM payments');
            $f = (int)DB::val('SELECT COUNT(*) FROM finance_entries');
            DB::q('DELETE FROM payments');
            DB::q('DELETE FROM finance_entries');
            $done[] = "финансы: платежей $p, ручных записей доходов/расходов $f";
        }
        if (!$done) {
            throw new \RuntimeException('ничего не выбрано');
        }
        return implode('; ', $done) . '. Расходы на ИИ сохранены.';
    }

    /**
     * Сигналы из Telegram (таблица signals, status=new): каждому подходящему клиенту — Worker::signalOrder().
     * По умолчанию только демо-счета (signal_real_enabled=0); итог по клиентам пишется в signal_orders и приходит в чат.
     */
    public function processSignals(): void
    {
        foreach (DB::all("SELECT * FROM signals WHERE status = 'new' ORDER BY id") as $sig) {
            $order = ['symbol' => $sig['symbol'], 'side' => $sig['side'], 'entry_lo' => (float)$sig['entry_lo'], 'entry_hi' => (float)$sig['entry_hi'],
                'stop' => (float)$sig['stop_loss'], 'targets' => json_decode((string)$sig['targets'], true) ?: []];
            $real = (bool)Settings::get('signal_real_enabled');
            $chase = (float)Settings::get('signal_max_chase_pct');
            $opened = 0;
            $skipped = [];
            foreach ($this->workers as $uid => $w) {
                if (!$real && $w->ex->mode() !== 'paper') {
                    continue;
                }
                try {
                    $r = $w->signalOrder($order, $chase);
                } catch (\Throwable $e) {
                    $r = ['status' => 'skipped', 'detail' => 'ошибка: ' . $e->getMessage()];
                }
                DB::insert('signal_orders', ['signal_id' => (int)$sig['id'], 'user_id' => $uid, 'status' => $r['status'], 'detail' => $r['detail'], 'created_at' => DB::now()]);
                if ($r['status'] === 'opened') {
                    $opened++;
                } else {
                    $skipped[$r['detail']] = ($skipped[$r['detail']] ?? 0) + 1;
                }
            }
            $why = $skipped ? ' Пропущено: ' . implode('; ', array_map(fn($k, $v) => "$k ($v)", array_keys($skipped), $skipped)) . '.' : '';
            $summary = "открыто клиентам: $opened.$why";
            DB::update('signals', ['status' => 'processed', 'summary' => $summary], 'id = :id', [':id' => $sig['id']]);
            if ($sig['reply_chat']) {
                Telegram::send((int)$sig['reply_chat'], "✅ Сигнал #{$sig['id']} {$sig['symbol']}: $summary");
            }
            Log::info("signal #{$sig['id']} {$sig['symbol']}: $summary");
        }
    }

    public function updateManualOrder(int $id, string $status, ?string $detail = null): void
    {
        DB::update('manual_orders', ['status' => $status, 'error' => $detail, 'done_at' => DB::now()], 'id = :id', [':id' => $id]);
    }

    // ───────────── запись и публикация ─────────────

    public function recordTrade(int $uid, array $t): void
    {
        DB::insert('trades', ['user_id' => $uid, 'symbol' => $t['symbol'], 'strategy' => $t['strategy'], 'side' => $t['side'],
            'qty' => $t['qty'], 'entry' => $t['entry'], 'exit' => $t['exit'], 'pnl' => $t['pnl'], 'r' => $t['r'],
            'regime' => $t['regime'], 'session_id' => $t['session_id'] ?? null, 'kind' => $t['kind'] ?? null,
            'mode' => $t['mode'], 'opened_at' => DB::now(), 'closed_at' => DB::now()]);
        if (isset($t['paper_balance'])) {
            DB::update('bot_settings', ['paper_balance' => $t['paper_balance']], 'user_id = :u', [':u' => $uid]);
        }
        $this->brain->learner->record($t['symbol'], $t['strategy'], $t['regime'] ?: 'range', (float)$t['r'], (float)$t['pnl']);
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
            $this->saveWorkerState($uid, $w);
        }
        $symbols = [];
        foreach ($this->market->feeds as $sym => $feed) {
            $symbols[$sym] = [
                'price' => $feed->price, 'funding' => $feed->funding, 'has_klines' => (bool)$feed->klines,
                'liq_long_1h' => round($feed->liqSum('long', 3600)), 'liq_short_1h' => round($feed->liqSum('short', 3600)),
                'features' => $this->brain->features[$sym] ?? null,
                'regime' => $this->brain->regimes[$sym] ?? null,
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
