<?php
declare(strict_types=1);

namespace App\Engine;

use App\Log;

/**
 * Торговый процесс одного клиента. На каждом такте:
 * 1) депозит, дневной лимит, аварийная остановка; 2) жёсткий режим рынка (Regime) решает, какие стратегии вообще
 * допустимы — иначе NO_TRADE; 3) среди допустимых — вес ИИ × множитель обучения × настройки клиента;
 * 4) риск портфеля/корреляции/экспозиции и комиссия относительно стопа; 5) вход, размер от риска до стопа;
 * 6) выход по стопу/тейку/трейлингу, слому структуры, развороту режима, времени; 7) статистика по результату.
 * На одной монете одновременно работает одна стратегия (one-way режим позиций).
 */
final class Worker
{
    /** Максимальное удержание сделки на отскок после ликвидаций (5м-стратегия). */
    public const MAX_HOLD_SEC = 3 * 3600;
    /** Трендовая сделка на 1h живёт дольше, но не бесконечно: нет движения за двое суток — edge исчез. */
    public const TREND_MAX_HOLD_SEC = 48 * 3600;
    /** Пробой 4h — трендследование: держим, пока ведёт трейлинг, но не дольше месяца. */
    public const BREAKOUT_MAX_HOLD_SEC = 30 * 86400;
    /** Трейлинг пробоя (Chandelier Exit): стоп на этом числе ATR(4h) от лучшей цены с момента входа. */
    public const BREAKOUT_TRAIL_ATR = 3.0;
    /** Стратегии, которыми бот сам не управляет (ни трейлинга, ни досрочных выходов, ни принудительного закрытия по времени). */
    public const UNMANAGED = ['manual', 'signal'];
    /** Лимитный вход по сигналу, не исполненный за это время, снимается: сигнал устарел. */
    public const SIGNAL_LIMIT_TTL_SEC = 4 * 3600;
    /** Комиссия тейкера за вход+выход не должна съедать больше этой доли R — иначе сделка не имеет смысла. */
    public const MAX_FEE_R = 0.15;
    /** Стоп трендовой сделки не ближе стольких ATR(1h). */
    public const TREND_MIN_STOP_ATR = 1.0;
    public const TREND_MIN_RR = 1.5;
    /** Пауза стратегии после просадки больше max_strategy_dd_pct профиля. */
    public const STRATEGY_PAUSE_SEC = 24 * 3600;
    /** Сколько тактов подряд позиции сетки нет на бирже, прежде чем считать, что её закрыл стоп-лосс биржи. */
    public const GRID_FLAT_TICKS = 3;
    /** Боковик закончился (режим не RANGE) дольше этого — у сетки больше нет причины держать убыточный инвентарь. */
    public const GRID_REGIME_EXIT_SEC = 30 * 60;
    public const LIQ_COOLDOWN_SEC = 20 * 60;
    /** После стопа сетки на монете не открываем новую сразу — даём рынку успокоиться. */
    public const GRID_STOP_COOLDOWN_SEC = 30 * 60;
    /** После направленной сделки с результатом хуже этого R — пауза перед новой направленной ставкой на той же монете. */
    public const BIG_LOSS_R_THRESHOLD = -1.5;
    public const BIG_LOSS_COOLDOWN_SEC = 30 * 60;
    /** Монеты, которые обычно двигаются вместе — не берём вторую направленную ставку в той же группе одновременно. */
    public const CORRELATION_GROUPS = [
        ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'AVAXUSDT'],
        ['XRPUSDT', 'ADAUSDT', 'DOGEUSDT', 'LTCUSDT'],
    ];
    /** С какой прибыли (в R) переносим стоп в безубыток / начинаем трейлить, и на каком расстоянии (тоже в R). */
    private const TRAIL_BREAKEVEN_R = 1.0;
    private const TRAIL_START_R = 1.5;
    private const TRAIL_DISTANCE_R = 1.0;
    private const PARTIAL_TAKE_R = 1.0;
    /** Сколько ждём, пока закрытие позиции появится в /v5/position/closed-pnl (у Bybit бывает задержка в секунды). */
    private const CLOSED_PNL_WAIT_MS = 60_000;
    /** Bybit рассчитывает funding раз в 8 часов — используется для грубой оценки funding за время удержания. */
    private const FUNDING_INTERVAL_HOURS = 8.0;
    /** Границы клиентского множителя бюджета на сделку — скромный диапазон, чтобы не подрывать Risk Engine. */
    public const BUDGET_MULT_MIN = 0.5;
    public const BUDGET_MULT_MAX = 1.5;

    public RiskGuard $guard;
    /** @var array<string,Grid> */
    public array $grids = [];
    /** symbol => [strategy, side, entry, stop, qty, regime, opened_ms, risk_usd] */
    public array $directional = [];
    /** Лимитные ручные ордера, ждущие исполнения: symbol => [link, order_id, side, qty, stop, take, placed_ms] */
    public array $pendingManual = [];
    public array $lastTrendBar = [];
    public array $lastBreakoutBar = [];
    public array $lastLiq = [];
    /** symbol => время последнего стопа сетки — для паузы перед новым входом на той же монете. */
    public array $lastGridStop = [];
    /** symbol => причина отправленного, но ещё не подтверждённого биржей стопа сетки (см. checkGridStops). */
    public array $pendingStopReason = [];
    /** symbol => время направленной сделки с результатом хуже BIG_LOSS_R_THRESHOLD — пауза перед новой ставкой. */
    public array $lastBigLoss = [];
    /** strategy => ['cum' => реализованный PnL, 'peak' => пик] — для лимита просадки стратегии. */
    public array $strategyPnl = [];
    /** strategy => до какого времени стратегия на паузе после просадки. */
    public array $strategyPausedUntil = [];
    /** symbol => сколько тактов подряд у активной сетки с инвентарём нет позиции на бирже. */
    public array $gridFlatTicks = [];
    /** symbol => с какого момента режим рынка перестал быть RANGE для открытой сетки. */
    public array $gridRegimeOffSince = [];
    /** Аварийная остановка (kill switch из админки): всё закрыть и не открывать новых сделок. */
    public bool $halted = false;
    /** Выключатели стратегий на уровне платформы (админка) — поверх настроек клиента: strategy => bool. */
    public array $platformEnabled = [];
    private bool $haltDone = false;
    public float $equity = 0.0;
    public string $status = 'запуск';
    /** Время текущего такта (в демоне — реальное, в бэктесте — время свечи). */
    private float $now = 0.0;
    private float $equityTs = 0.0;
    private float $snapTs = 0.0;
    private array $leverageSet = [];
    private array $viewCache = [];
    private float $viewCacheTs = 0.0;

    /**
     * @param callable(int,array):void $record   запись закрытой сделки
     * @param callable(int,string):void $notify  уведомление клиенту
     * @param callable(int,float):void $snapshot снимок баланса
     * @param bool $manualMode  true — автостратегии не открывают новые сделки, только трейдер вручную
     * @param ?callable(int,string,?string):void $orderUpdate  апдейт статуса строки manual_orders (id, статус, детали)
     */
    public function __construct(
        public int $userId,
        public ExchangeInterface $ex,
        private Market $market,
        private Brain $brain,
        public array $prof,
        public array $symbols,
        private array $enabled,
        private $record,
        private $notify,
        private $snapshot,
        public bool $manualMode = false,
        private $orderUpdate = null,
        /** Клиентский множитель размера позиции/риска на открытие сделки (0.5–1.5) — поверх risk_mult ИИ и Adaptive Risk. */
        public float $budgetMult = 1.0,
    ) {
        $this->symbols = array_values(array_filter($symbols, fn($s) => isset($market->feeds[$s])));
        $this->guard = new RiskGuard($prof);
        $this->budgetMult = max(self::BUDGET_MULT_MIN, min(self::BUDGET_MULT_MAX, $this->budgetMult ?: 1.0));
    }

    private function now(): float
    {
        return $this->now ?: microtime(true);
    }

    public function step(float $now): void
    {
        $this->now = $now;
        if ($this->ex instanceof PaperExchange) {
            $this->ex->now = $now;
            $this->ex->updatePrices($this->market->prices());
        }
        if ($now - $this->equityTs > 30 || !$this->equity) {
            $this->equity = $this->ex->equity();
            $this->guard->updateEquity($this->equity, $now);
            $this->equityTs = $now;
        }
        if ($this->halted) {
            $this->emergencyFlatten();
            $this->status = '⛔ аварийная остановка: позиции закрыты, новых сделок нет';
            return;
        }
        $this->haltDone = false;
        if ($now - $this->snapTs > 900) {
            ($this->snapshot)($this->userId, $this->equity);
            $this->snapTs = $now;
        }
        [$allowed, $reason] = $this->guard->allowed($this->equity, $now, $this->floatingPortfolioPnl());
        $positions = $this->ex->positions();
        $this->checkDirectional($positions, $now);
        $this->checkGridStops($now);
        $this->checkPendingManual($positions, $now);
        $this->reconcileExternalPositions($positions, $now);
        // Сигналы бывают по любым монетам (не только из списка клиента), поэтому лестница целей ведётся здесь, а не в manageSymbol()
        foreach (array_keys($this->directional) as $sym) {
            $this->applySignalLadder($sym, $positions);
        }
        foreach ($this->symbols as $sym) {
            $this->manageSymbol($sym, $positions, $allowed, $now);
        }
        $grids = implode(', ', array_map(fn($s, $g) => "$s:{$g->plan['mode']}", array_keys($this->grids), $this->grids));
        $mode = $this->manualMode ? 'ручной режим' : 'работает';
        $this->status = $allowed ? "$mode · сетки: " . ($grids ?: 'нет') . ' · сделки: ' . count($this->directional) : "⏸ $reason";
    }

    public function weights(string $sym, array $ins, ?string $regime = null): array
    {
        $m = fn($s) => $this->brain->learner->mult($sym, $s, $regime ?? $ins['regime']);
        return [
            'grid' => ($this->enabled['grid'] ?? true) ? $ins['w_grid'] * $m('grid') : 0.0,
            'trend' => ($this->enabled['trend'] ?? true) ? $ins['w_trend'] * $m('trend') : 0.0,
            'liquidation' => ($this->enabled['liquidation'] ?? true) ? $ins['w_liquidation'] * $m('liquidation') : 0.0,
            // у ИИ нет веса для пробоя — решает жёсткий сигнал, вес снижает только статистика обучения
            'breakout' => ($this->enabled['breakout'] ?? true) ? $m('breakout') : 0.0,
        ];
    }

    /** Суммарный нереализованный PnL по всем открытым сеткам и направленным сделкам — для Portfolio Risk Engine. */
    private function floatingPortfolioPnl(): float
    {
        $total = 0.0;
        foreach ($this->grids as $sym => $grid) {
            $price = $this->market->feeds[$sym]->price ?? null;
            if ($price) {
                $total += $grid->floatingPnl($price);
            }
        }
        foreach ($this->directional as $sym => $d) {
            $price = $this->market->feeds[$sym]->price ?? null;
            if ($price) {
                $total += ($d['side'] === 'Buy' ? 1 : -1) * ($price - $d['entry']) * $d['qty'];
            }
        }
        return $total;
    }

    /**
     * Грубая оценка funding за время удержания позиции: notional × текущая ставка × (часы удержания / 8).
     * Реальный funding не входит ни в закрытые ордера, ни в closedPnl() биржи — это отдельный расчёт Bybit
     * раз в 8 часов, и без отдельного похода в историю транзакций по каждой сделке точнее не посчитать.
     * Знак: лонг платит при положительной ставке, шорт получает — стандартное правило перпетуалов.
     */
    private function fundingEstimate(string $sym, string $side, float $notional, float $holdSec): float
    {
        $rate = $this->market->feeds[$sym]->funding ?? 0.0;
        if ($rate == 0.0 || $holdSec <= 0) {
            return 0.0;
        }
        $periods = $holdSec / 3600 / self::FUNDING_INTERVAL_HOURS;
        return $notional * $rate * $periods * ($side === 'Buy' ? 1 : -1);
    }

    private function manageSymbol(string $sym, array $positions, bool $allowed, float $now): void
    {
        $feed = $this->market->feeds[$sym];
        $inst = $this->market->instruments[$sym] ?? null;
        $f = $this->brain->features[$sym] ?? null;
        $ins = $this->brain->insights[$sym] ?? null;
        if (!$feed->price || !$inst || !$f || !$ins) {
            return;
        }
        $regime = $this->brain->regimes[$sym] ?? Regime::NO_TRADE;
        $h1 = $this->brain->featuresH1[$sym] ?? null;
        if (!isset($this->leverageSet[$sym])) {
            $this->ex->setLeverage($sym, (int)min($this->prof['leverage'], $inst->maxLeverage));
            $this->leverageSet[$sym] = true;
        }
        if ($this->checkExit($sym, $ins, $regime, $h1, $positions)) {
            return;                                          // позицию только что закрыли — на этом такте всё
        }
        $this->applyPartialTake($sym, $feed->price);
        $this->applyTrailing($sym, $feed->price);
        $this->applyChandelier($sym, $feed->price);
        $w = $this->weights($sym, $ins, $regime);
        $tuning = $this->brain->tuning[$sym] ?? [];

        if (isset($this->grids[$sym])) {
            $this->manageGrid($sym, $this->grids[$sym], $positions, $allowed, $regime, $w, $now);
            return;
        }
        if ($this->manualMode || !$allowed || isset($this->directional[$sym]) || isset($positions[$sym]) || isset($this->pendingManual[$sym])) {
            return;                                          // в ручном режиме автостратегии новых сделок не открывают
        }
        // NO_TRADE по умолчанию: стратегия может открыться только если режим рынка это прямо разрешает (Regime::allows)
        // — это жёсткая проверка кода, ИИ её не видит и обойти не может. Остальное (вес, статистика) — только среди допустимых.
        if ($h1 && $this->strategyAllowed('grid', $regime, $now) && $w['grid'] >= 0.4
            && count($this->grids) < $this->prof['max_grids']
            && (!isset($this->lastGridStop[$sym]) || $now - $this->lastGridStop[$sym] > self::GRID_STOP_COOLDOWN_SEC)
            && !$this->correlatedGridOpen($sym)) {
            $side = Regime::rangeGridSide(['price' => $feed->price] + $h1);
            if ($side !== null) {
                $this->startGrid($sym, $f, $ins, $tuning, $side);
                return;
            }
        }
        if (count($this->directional) >= $this->prof['max_directional'] || $this->guard->directionalTradesLeft() <= 0
            || $this->correlatedDirectionalOpen($sym) || $now - ($this->lastBigLoss[$sym] ?? 0) < self::BIG_LOSS_COOLDOWN_SEC) {
            return;
        }
        $setup = null;
        if ($this->strategyAllowed('breakout', $regime, $now) && $w['breakout'] >= 0.4) {
            $k4 = Indicators::closedBars($feed->klines1h, 4);
            $bar = $k4 ? (string)end($k4)[0] : null;
            if ($bar !== null && ($this->lastBreakoutBar[$sym] ?? null) !== $bar) {
                $this->lastBreakoutBar[$sym] = $bar;        // сигнал — один раз на закрытие 4h-свечи
                $setup = Setups::breakout($k4, $feed->price);
            }
        }
        if ($setup === null && $h1 && $this->strategyAllowed('trend', $regime, $now) && $w['trend'] >= 0.4
            && ($this->lastTrendBar[$sym] ?? null) !== $h1['last_closed_ts']) {
            $this->lastTrendBar[$sym] = $h1['last_closed_ts'];
            $rr = max(self::TREND_MIN_RR, (float)($tuning['trend_rr'] ?? $this->prof['rr']));
            $setup = Setups::trend(['price' => $feed->price] + $h1, $regime === Regime::STRONG_UP ? 'trend_up' : 'trend_down',
                $rr, self::TREND_MIN_STOP_ATR);
        }
        if ($setup === null && $this->strategyAllowed('liquidation', $regime, $now) && $w['liquidation'] >= 0.4
            && !$this->quietHours($now) && !$this->quietMarket($f) && $now - ($this->lastLiq[$sym] ?? 0) > self::LIQ_COOLDOWN_SEC) {
            $setup = Setups::liquidation($feed, $f, (float)($tuning['liq_threshold_mult'] ?? AIAnalyst::TUNABLE['liq_threshold_mult'][2]), $now);
            if ($setup) {
                $this->lastLiq[$sym] = $now;
            }
        }
        if ($setup) {
            $this->openDirectional($sym, $setup, $ins, $w[$setup['strategy']], $regime);
        }
    }

    /** Стратегия включена клиентом, разрешена режимом рынка и не на паузе после собственной просадки. */
    private function strategyAllowed(string $strategy, string $regime, float $now): bool
    {
        return ($this->enabled[$strategy] ?? true) && ($this->platformEnabled[$strategy] ?? true) && Regime::allows($regime, $strategy)
            && $now >= ($this->strategyPausedUntil[$strategy] ?? 0);
    }

    private function manageGrid(string $sym, Grid $grid, array $positions, bool $allowed, string $regime, array $w, float $now): void
    {
        $price = $this->market->feeds[$sym]->price;
        // Стоп-лосс сетки теперь стоит на бирже: если позиции нет несколько тактов подряд, а инвентарь есть —
        // её закрыл стоп биржи (или трейдер вручную). Забираем реальный результат из closed-pnl.
        if ($grid->active && $grid->inventory && !isset($positions[$sym])) {
            $this->gridFlatTicks[$sym] = ($this->gridFlatTicks[$sym] ?? 0) + 1;
            if ($this->gridFlatTicks[$sym] >= self::GRID_FLAT_TICKS) {
                unset($this->gridFlatTicks[$sym]);
                $grid->stop($price, $now, true);
                return;
            }
        } else {
            unset($this->gridFlatTicks[$sym]);
        }
        foreach ($grid->sync($price, $now) as $ev) {
            $this->onGridEvent($sym, $grid, $ev, $regime, $now);
        }
        $inRange = Regime::allows($regime, 'grid');
        if ($inRange) {
            unset($this->gridRegimeOffSince[$sym]);
        } else {
            $this->gridRegimeOffSince[$sym] ??= $now;
        }
        $want = $allowed && $inRange && $w['grid'] >= 0.4;
        if ($grid->active && !$want) {
            // Сетка зарабатывает только в боковике. Режим ушёл против неё (сильный тренд против, пробой, выброс
            // волатильности) — в минусе закрываем сразу. Боковик просто закончился и не вернулся за 30 минут —
            // в минусе тоже закрываем: держать убыточный инвентарь «пока сигнал формально не отменён» — это ровно
            // тот путь к крупному стопу, из-за которого десятки мелких плюсов обнулялись. В плюсе — мягко доводим.
            $long = $grid->plan['mode'] === 'long';
            $against = in_array($regime, [Regime::BREAKOUT, Regime::HIGH_VOL, $long ? Regime::STRONG_DOWN : Regime::STRONG_UP], true);
            $rangeGone = !$inRange && $now - $this->gridRegimeOffSince[$sym] >= self::GRID_REGIME_EXIT_SEC;
            if (($against || $rangeGone) && $grid->floatingPnl($price) < 0) {
                $reason = $against ? 'reversal' : 'regime_exit';
                $this->pendingStopReason[$sym] = $reason;
                $ev = $grid->stop($price, $now);
                if ($ev) {
                    unset($this->pendingStopReason[$sym]);
                    $this->onGridEvent($sym, $grid, $ev, $regime, $now, $reason);
                }
            } elseif (!$grid->draining) {
                $grid->drain();
            }
        } elseif ($grid->active && $grid->idleFar($price)) {
            $grid->stop($price, $now);
        }
        if (!$grid->active && $grid->pendingStop === null) {
            unset($this->grids[$sym], $this->gridRegimeOffSince[$sym]);   // пока не заберём реальный PnL стопа (см. checkGridStops) — не освобождаем монету
        }
    }

    private function startGrid(string $sym, array $f, array $ins, array $tuning, string $side): void
    {
        $inst = $this->market->instruments[$sym];
        $price = $this->market->feeds[$sym]->price;
        // Adaptive Risk и клиентский бюджет только уменьшают/масштабируют риск, риск ИИ (risk_mult ≤ 1.2) — тоже в рамках.
        $riskMult = min(1.0, $ins['risk_mult']) * $this->guard->adaptiveMult($this->equity) * $this->budgetMult;
        $capital = $this->equity * $this->prof['grid_alloc'] / $this->prof['max_grids'] * $riskMult;
        $maxLoss = $this->equity * $this->prof['grid_max_loss_pct'] / 100 * $riskMult;
        // Grid Risk Protection: суммарный max_loss всех сеток клиента не выше лимита профиля.
        $usedRisk = array_sum(array_map(fn(Grid $g) => (float)$g->plan['max_loss'], $this->grids));
        $riskCap = $this->equity * (float)($this->prof['max_total_grid_risk_pct'] ?? 100) / 100;
        if ($usedRisk + $maxLoss > $riskCap) {
            return;
        }
        $plan = Grid::plan($price, $f['atr'], $inst, $side, (float)($tuning['grid_step_atr'] ?? $ins['grid_step_atr']),
            $this->prof['grid_levels'], $capital, (int)min($this->prof['leverage'], $inst->maxLeverage), $maxLoss);
        if ($plan === null || !$this->canAddRisk($sym, (float)$plan['max_loss'], (float)$plan['qty'] * $plan['levels'] * $price)) {
            return;
        }
        $grid = new Grid($this->ex, $sym, $inst, $plan);
        $grid->start($this->now());
        $this->grids[$sym] = $grid;
        Log::info(sprintf('user %d: сетка %s %s шаг %.2f%% x%d, объём уровня %s', $this->userId, $sym, $plan['mode'],
            $plan['step_pct'], $plan['levels'], $plan['qty']));
    }

    /** $kindOverride — точная причина выхода для аналитики (Grid Session PnL): cycle/stop/reversal; по умолчанию берётся из события сетки. */
    private function onGridEvent(string $sym, Grid $grid, array $ev, string $regime, float $now, ?string $kindOverride = null): void
    {
        $pnl = $ev['pnl'];
        if ($ev['kind'] === 'stop') {
            $this->lastGridStop[$sym] = $now;                 // пауза перед новой сеткой на этой монете — см. GRID_STOP_COOLDOWN_SEC
            // funding применяется только к финальному стопу (не к каждому мелкому циклу — те держатся слишком
            // коротко, чтобы funding был заметен): оценка по времени с момента запуска этой сетки.
            $pnl -= $this->fundingEstimate($sym, $ev['side'], $ev['qty'] * $ev['entry'], $now - $grid->startedAt);
        }
        $grid->lastEventMs = (int)($now * 1000);
        $this->recordTrade($sym, 'grid', $ev['side'], $ev['qty'], $ev['entry'], $ev['exit'], $pnl,
            $pnl / $grid->unitRisk(), $regime, $grid->tag, $kindOverride ?? $ev['kind']);
    }

    /**
     * Выход из автоматической направленной сделки раньше стопа/тейка, если исчезла причина её держать.
     * Ручные сделки трейдера и сетки не трогает. Для тренда (1h):
     *  - слом структуры: закрытая часовая свеча по другую сторону EMA50 — выход;
     *  - режим развернулся против позиции / выброс волатильности: в минусе — выход, в плюсе — стоп в безубыток
     *    (не отдаём прибыль, но и не режем прибыльную сделку по шуму).
     * Для отскока после ликвидаций (5м) — прежняя логика по режиму ИИ.
     * @return bool true — позицию закрыли (или попытались), дальше на этом такте по монете делать нечего.
     */
    private function checkExit(string $sym, array $ins, string $regime, ?array $h1, array $positions): bool
    {
        $d = $this->directional[$sym] ?? null;
        if (!$d || in_array($d['strategy'], self::UNMANAGED, true) || !isset($positions[$sym]) || isset($d['gone_ms'])) {
            return false;
        }
        $nowMs = (int)($this->now() * 1000);
        if ($nowMs - $d['opened_ms'] < 60_000) {
            return false;                                     // не дёргаемся в первую минуту после входа
        }
        $long = $d['side'] === 'Buy';
        $price = $this->market->feeds[$sym]->price;
        $inProfit = $price && ($long ? $price > $d['entry'] : $price < $d['entry']);
        $reason = null;
        if ($d['strategy'] === 'trend') {
            $against = in_array($regime, [$long ? Regime::STRONG_DOWN : Regime::STRONG_UP, Regime::HIGH_VOL], true);
            if ($h1 && ($long ? $h1['c1'] < $h1['ema50_1'] : $h1['c1'] > $h1['ema50_1'])) {
                $reason = 'слом структуры (часовая свеча закрылась за EMA50)';
            } elseif ($against && !$inProfit) {
                $reason = "режим рынка развернулся против позиции ($regime)";
            } elseif ($against && $inProfit) {
                $this->moveStopToBreakeven($sym);
            }
        } elseif ($d['strategy'] === 'liquidation' && ($long ? $ins['regime'] === 'trend_down' : $ins['regime'] === 'trend_up')) {
            $reason = "разворот тренда против позиции ({$ins['regime']})";
        }
        if ($reason === null) {
            return false;
        }
        try {
            $this->ex->closePosition($sym);                 // закрытие увидит checkDirectional на следующем такте и запишет сделку
            Log::info("user {$this->userId}: $sym закрыт досрочно — $reason");
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: не удалось закрыть $sym досрочно: " . $e->getMessage());
        }
        return true;
    }

    /** Монета вне списка клиента: подгрузить инструмент и цену, а демо-бирже сразу передать цену (иначе у неё её нет до следующего такта). */
    private function ensureMarket(string $sym): bool
    {
        if (!$this->market->ensureSymbol($sym)) {
            return false;
        }
        $price = (float)($this->market->feeds[$sym]->price ?? 0);
        if ($price > 0 && $this->ex instanceof PaperExchange) {
            $this->ex->updatePrices([$sym => $price]);
        }
        return true;
    }

    private function moveStopToBreakeven(string $sym): void
    {
        $d = $this->directional[$sym];
        $inst = $this->market->instruments[$sym] ?? null;
        if (!$inst || ($d['trail_be'] ?? false)) {
            return;
        }
        $long = $d['side'] === 'Buy';
        $be = (float)$inst->roundPrice($long ? $d['entry'] * (1 + 2 * ExchangeInterface::TAKER_FEE) : $d['entry'] * (1 - 2 * ExchangeInterface::TAKER_FEE), !$long);
        if ($long ? $be <= $d['stop'] : $be >= $d['stop']) {
            return;
        }
        try {
            $this->ex->setStopLoss($sym, (string)$be);
            $this->directional[$sym]['stop'] = $be;
            $this->directional[$sym]['trail_be'] = true;
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: не удалось перенести стоп $sym в безубыток: " . $e->getMessage());
        }
    }

    /** Половина позиции фиксируется на +1R, остаток ведём дальше (трейлингом) — снижает разброс результата. */
    private function applyPartialTake(string $sym, float $price): void
    {
        $d = $this->directional[$sym] ?? null;
        $inst = $this->market->instruments[$sym] ?? null;
        if (!$d || !$inst || in_array($d['strategy'], [...self::UNMANAGED, 'breakout'], true) || !$price || ($d['partial_done'] ?? false) || $d['risk_usd'] <= 0 || isset($d['gone_ms'])) {
            return;
        }
        $riskDist = $d['risk_usd'] / $d['qty'];
        if ($riskDist <= 0) {
            return;
        }
        $long = $d['side'] === 'Buy';
        $profitR = $long ? ($price - $d['entry']) / $riskDist : ($d['entry'] - $price) / $riskDist;
        if ($profitR < self::PARTIAL_TAKE_R) {
            return;
        }
        $half = $inst->roundQty($d['qty'] / 2);
        if ((float)$half <= 0 || !$inst->qtyOk($half, $price)) {
            return;                                          // остаток слишком мал, чтобы делить — ведём как есть
        }
        try {
            $this->ex->placeMarket($sym, $long ? 'Sell' : 'Buy', $half, null, null, true);
            $this->directional[$sym]['partial_done'] = true;
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: не удалось частично закрыть $sym: " . $e->getMessage());
        }
    }

    /** Трейлинг-стоп: в безубыток на +0.8R, дальше следом за ценой на расстоянии 0.6R с +1.5R — чтобы не отдавать набежавшую прибыль. */
    private function applyTrailing(string $sym, float $price): void
    {
        $d = $this->directional[$sym] ?? null;
        $inst = $this->market->instruments[$sym] ?? null;
        if (!$d || !$inst || in_array($d['strategy'], [...self::UNMANAGED, 'breakout'], true) || !$price || $d['risk_usd'] <= 0 || (float)$d['stop'] <= 0 || isset($d['gone_ms'])) {
            return;
        }
        $riskDist = $d['risk_usd'] / $d['qty'];
        if ($riskDist <= 0) {
            return;
        }
        $long = $d['side'] === 'Buy';
        $profitR = $long ? ($price - $d['entry']) / $riskDist : ($d['entry'] - $price) / $riskDist;
        $target = null;
        if ($profitR >= self::TRAIL_START_R) {
            $target = $long ? $price - self::TRAIL_DISTANCE_R * $riskDist : $price + self::TRAIL_DISTANCE_R * $riskDist;
        } elseif ($profitR >= self::TRAIL_BREAKEVEN_R && !($d['trail_be'] ?? false)) {
            $target = $long ? $d['entry'] * 1.0006 : $d['entry'] * 0.9994;      // небольшой буфер сверх входа на комиссию
            $this->directional[$sym]['trail_be'] = true;
        }
        if ($target === null) {
            return;
        }
        $newStop = (float)$inst->roundPrice($target, !$long);
        $improves = $long ? $newStop > $d['stop'] : $newStop < $d['stop'];
        if (!$improves) {
            return;                                          // стоп двигаем только в свою пользу, никогда не расширяем риск
        }
        try {
            $this->ex->setStopLoss($sym, (string)$newStop);
            $this->directional[$sym]['stop'] = $newStop;
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: не удалось подвинуть стоп $sym: " . $e->getMessage());
        }
    }

    /**
     * Трейлинг пробоя (Chandelier Exit): стоп следует за лучшей ценой с момента входа на BREAKOUT_TRAIL_ATR × ATR(4h)
     * и двигается только в свою пользу. Фиксированного тейка нет — сделка живёт, пока тренд не развернётся на 3 ATR.
     */
    private function applyChandelier(string $sym, float $price): void
    {
        $d = $this->directional[$sym] ?? null;
        $inst = $this->market->instruments[$sym] ?? null;
        if (!$d || !$inst || $d['strategy'] !== 'breakout' || !$price || empty($d['atr']) || isset($d['gone_ms'])) {
            return;
        }
        $long = $d['side'] === 'Buy';
        $best = $long ? max($d['best'] ?? $d['entry'], $price) : min($d['best'] ?? $d['entry'], $price);
        $this->directional[$sym]['best'] = $best;
        $newStop = (float)$inst->roundPrice($long ? $best - self::BREAKOUT_TRAIL_ATR * $d['atr'] : $best + self::BREAKOUT_TRAIL_ATR * $d['atr'], !$long);
        if ($long ? $newStop <= $d['stop'] : $newStop >= $d['stop']) {
            return;                                          // стоп двигаем только в свою пользу
        }
        try {
            $this->ex->setStopLoss($sym, (string)$newStop);
            $this->directional[$sym]['stop'] = $newStop;
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: не удалось подтянуть трейлинг-стоп пробоя $sym: " . $e->getMessage());
        }
    }

    /** По коррелирующей монете (см. CORRELATION_GROUPS) уже есть автоматическая направленная ставка. */
    private function correlatedDirectionalOpen(string $sym): bool
    {
        foreach (self::CORRELATION_GROUPS as $group) {
            if (!in_array($sym, $group, true)) {
                continue;
            }
            foreach ($group as $other) {
                if ($other !== $sym && isset($this->directional[$other]) && !in_array($this->directional[$other]['strategy'], self::UNMANAGED, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * По коррелирующей монете (см. CORRELATION_GROUPS) уже открыта сетка — не открываем вторую в той же группе:
     * при общем движении группы (например, альты вместе с BTC) несколько сеток может выбить стопом одновременно.
     */
    private function correlatedGridOpen(string $sym): bool
    {
        foreach (self::CORRELATION_GROUPS as $group) {
            if (!in_array($sym, $group, true)) {
                continue;
            }
            foreach ($group as $other) {
                if ($other !== $sym && isset($this->grids[$other])) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Азиатская ночь по UTC — минимальная ликвидность даже на мажорах, новых направленных ставок не берём. */
    private function quietHours(float $now): bool
    {
        $h = (int)gmdate('G', (int)$now);
        return $h >= 0 && $h < 5;
    }

    /** ATR исчезающе мал относительно цены — рынок «спит», сигналы в такой момент ненадёжны. */
    private function quietMarket(array $f): bool
    {
        return empty($f['price']) || ($f['atr'] / $f['price']) < 0.0007;
    }

    private function openDirectional(string $sym, array $s, array $ins, float $weight, string $regime): void
    {
        $inst = $this->market->instruments[$sym];
        // Комиссия за вход+выход относительно расстояния до стопа: на 5м-стопах она съедала 50–120% R,
        // из-за чего прибыльная сделка давала ~+0.9R, а убыточная ~−1.6R. Такие сделки не открываем вообще.
        $feeR = Setups::feeR($s['entry'], $s['risk']);
        if ($feeR > self::MAX_FEE_R) {
            return;
        }
        // Размер — от допустимого риска и реального расстояния до стопа С УЧЁТОМ комиссий. Вес стратегии и ИИ
        // могут только уменьшить риск (≤ 1.0): после серии плюсов риск не растёт.
        $riskMult = min(1.0, $ins['risk_mult']) * $this->guard->adaptiveMult($this->equity) * $this->budgetMult;
        $riskUsd = $this->equity * $this->prof['risk_pct'] / 100 * $riskMult * min(1.0, max(0.5, $weight));
        $perUnit = $s['risk'] + 2 * ExchangeInterface::TAKER_FEE * $s['entry'];
        $qty = min($riskUsd / $perUnit, $this->equity * $this->prof['leverage'] * 0.9 / $s['entry'], $inst->maxMktQty);
        $q = $inst->roundQty($qty);
        if (!$inst->qtyOk($q, $s['entry']) || !$this->canAddRisk($sym, (float)$q * $perUnit, (float)$q * $s['entry'])) {
            return;
        }
        $long = $s['side'] === 'Buy';
        $stop = $inst->roundPrice($s['stop'], !$long);
        $take = $s['take'] !== null ? $inst->roundPrice($s['take'], !$long) : null;
        $this->ex->placeMarket($sym, $s['side'], $q, $stop, $take);
        $this->directional[$sym] = ['strategy' => $s['strategy'], 'side' => $s['side'], 'entry' => $s['entry'], 'stop' => (float)$stop,
            'qty' => (float)$q, 'regime' => $regime, 'opened_ms' => (int)($this->now() * 1000), 'risk_usd' => (float)$q * $s['risk'],
            'atr' => $s['atr'] ?? null, 'best' => $s['entry']];
        // Уведомление о входе стратегии в Telegram отключено — клиент видит открытые позиции в приложении.
    }

    /** Риск (до стопа), который несёт открытая позиция/сетка — для лимитов портфеля и корреляции. */
    private function openRisks(): array
    {
        $out = [];
        foreach ($this->grids as $sym => $g) {
            $out[$sym] = ['risk' => (float)$g->plan['max_loss'], 'notional' => (float)$g->plan['qty'] * $g->plan['levels'] * $g->plan['center']];
        }
        foreach ($this->directional as $sym => $d) {
            // у ручных/внешних позиций стоп неизвестен — считаем риск как 2% номинала, чтобы они не были «бесплатными»
            $risk = $d['risk_usd'] > 0 ? $d['risk_usd'] : 0.02 * $d['qty'] * $d['entry'];
            $out[$sym] = ['risk' => $risk, 'notional' => $d['qty'] * $d['entry']];
        }
        return $out;
    }

    /**
     * Лимиты портфеля: суммарный риск до стопов, риск внутри одной группы коррелирующих монет и общая экспозиция
     * (номинал всех позиций относительно депозита). Одна плохая сделка или одно движение рынка не должны стирать
     * результат десятков предыдущих — поэтому новая позиция открывается только если все три лимита соблюдены.
     */
    private function canAddRisk(string $sym, float $risk, float $notional): bool
    {
        if ($this->equity <= 0) {
            return false;
        }
        $open = $this->openRisks();
        $total = array_sum(array_column($open, 'risk')) + $risk;
        if ($total > $this->equity * (float)($this->prof['max_portfolio_risk_pct'] ?? 100) / 100) {
            return false;
        }
        $exposure = array_sum(array_column($open, 'notional')) + $notional;
        if ($exposure > $this->equity * (float)($this->prof['max_exposure_mult'] ?? 1000)) {
            return false;
        }
        foreach (self::CORRELATION_GROUPS as $group) {
            if (!in_array($sym, $group, true)) {
                continue;
            }
            $groupRisk = $risk;
            foreach ($group as $other) {
                $groupRisk += $open[$other]['risk'] ?? 0.0;
            }
            if ($groupRisk > $this->equity * (float)($this->prof['max_correlated_risk_pct'] ?? 100) / 100) {
                return false;
            }
        }
        return true;
    }

    /**
     * Сигнал из Telegram-канала (App\SignalParser): вход по зоне, стоп из сигнала, цели — лестницей reduce-only
     * лимиток. Размер — от риска клиента и расстояния до стопа С УЧЁТОМ комиссий, а не «плечо X25» из канала.
     * Бот не догоняет: цена ушла из зоны дальше $maxChasePct или уже достигла первой цели — сигнал пропускается.
     * @param array{symbol:string,side:string,entry_lo:float,entry_hi:float,stop:float,targets:list<float>} $sig
     * @return array{status:string,detail:string} status: opened | skipped
     */
    public function signalOrder(array $sig, float $maxChasePct, float $riskMult = 1.0): array
    {
        $skip = fn(string $why) => ['status' => 'skipped', 'detail' => $why];
        $sym = (string)$sig['symbol'];
        if ($this->halted) {
            return $skip('аварийная остановка');
        }
        if ($this->now() < ($this->strategyPausedUntil['signal'] ?? 0)) {
            return $skip('сигналы на паузе после просадки');
        }
        if (!$this->ensureMarket($sym)) {
            return $skip("монета $sym не найдена на Bybit");
        }
        if (isset($this->grids[$sym]) || isset($this->directional[$sym]) || isset($this->pendingManual[$sym]) || isset($this->ex->positions()[$sym])) {
            return $skip('по монете уже есть позиция, сетка или ордер');
        }
        $inst = $this->market->instruments[$sym];
        $price = (float)$this->market->feeds[$sym]->price;
        if ($price <= 0) {
            return $skip('нет текущей цены');
        }
        $sid = (int)($sig['id'] ?? 0);
        $cid = isset($sig['channel_id']) ? (int)$sig['channel_id'] : null;
        $long = $sig['side'] === 'Buy';
        [$lo, $hi, $stop] = [(float)$sig['entry_lo'], (float)$sig['entry_hi'], (float)$sig['stop']];
        $marketEntry = $lo <= 0 && $hi <= 0;                  // «вход по рынку»: цены входа в сигнале нет
        if ($marketEntry) {
            $lo = $hi = $price;
            if (abs($price - $stop) / $price > 0.15) {
                return $skip('стоп дальше 15% от текущей цены — похоже на ошибку в сообщении');
            }
        }
        $targets = array_map('floatval', $sig['targets']);
        if ($long ? $price >= $targets[0] : $price <= $targets[0]) {
            return $skip('цена уже достигла первой цели');
        }
        if ($long ? $price <= $hi : $price >= $lo) {
            $limit = null;                                   // цена в зоне или лучше — входим по рынку
            $ref = $price;
        } else {
            $edge = $long ? $hi : $lo;
            $away = abs($price - $edge) / $edge;
            if ($away > $maxChasePct / 100) {
                return $skip(sprintf('цена ушла от зоны входа на %.2f%% (допуск %.2f%%)', $away * 100, $maxChasePct));
            }
            $limit = $edge;                                  // ждём возврата цены на границу зоны
            $ref = $edge;
        }
        if ($long ? $stop >= $ref : $stop <= $ref) {
            return $skip('цена уже за стопом');
        }
        $risk = abs($ref - $stop);
        if (Setups::feeR($ref, $risk) > self::MAX_FEE_R) {
            return $skip('стоп слишком близко — комиссия съедает больше 15% риска');
        }
        $riskUsd = $this->equity * $this->prof['risk_pct'] / 100 * min(1.0, $this->guard->adaptiveMult($this->equity)) * $this->budgetMult * max(0.0, $riskMult);
        $perUnit = $risk + 2 * ExchangeInterface::TAKER_FEE * $ref;
        $lev = (int)min($this->prof['leverage'], $inst->maxLeverage);
        $q = $inst->roundQty(min($riskUsd / $perUnit, $this->equity * $lev * 0.9 / $ref, $inst->maxMktQty));
        if (!$inst->qtyOk($q, $ref)) {
            return $skip('депозит слишком мал для минимального лота');
        }
        if (!$this->canAddRisk($sym, (float)$q * $perUnit, (float)$q * $ref)) {
            return $skip('лимит риска портфеля');
        }
        $this->ex->setLeverage($sym, $lev);
        $this->leverageSet[$sym] = true;
        $stopStr = (string)$inst->roundPrice($stop, !$long);
        $side = $long ? 'Buy' : 'Sell';
        $nowMs = (int)($this->now() * 1000);
        if ($limit === null) {
            $this->ex->placeMarket($sym, $side, $q, $stopStr, null);
            $this->directional[$sym] = ['strategy' => 'signal', 'side' => $side, 'entry' => $price, 'stop' => (float)$stopStr, 'qty' => (float)$q,
                'regime' => 'signal', 'opened_ms' => $nowMs, 'risk_usd' => (float)$q * $risk, 'targets' => $targets, 'signal_id' => $sid, 'channel_id' => $cid];
            return ['status' => 'opened', 'detail' => "по рынку ~" . self::fmt($price) . ", объём $q, риск " . round((float)$q * $risk, 2) . '$'];
        }
        $limitStr = (string)$inst->roundPrice($limit, !$long);
        $link = 'sig-' . bin2hex(random_bytes(6));
        $this->ex->placeLimit($sym, $side, $q, $limitStr, $link, false, $stopStr, null);
        $this->pendingManual[$sym] = ['link' => $link, 'order_id' => 0, 'side' => $side, 'qty' => (float)$q, 'stop' => (float)$stopStr, 'take' => null,
            'placed_ms' => $nowMs, 'signal' => ['targets' => $targets, 'signal_id' => $sid, 'channel_id' => $cid]];
        return ['status' => 'opened', 'detail' => "лимит $limitStr (цена вне зоны), объём $q, риск " . round((float)$q * $risk, 2) . '$'];
    }

    /**
     * Обновление от канала по сигналу: «в безубыток» или «закрыть». Касается только сделок ЭТОГО канала (по signal_id
     * канала), чужие позиции и ручные сделки не трогает.
     * @return array{status:string,detail:string}
     */
    public function signalUpdate(string $sym, string $action, ?int $channelId): array
    {
        $d = $this->directional[$sym] ?? null;
        $p = $this->pendingManual[$sym] ?? null;
        $mine = fn($x) => $x && ($channelId === null || (int)($x['channel_id'] ?? 0) === $channelId);
        if ($d && ($d['strategy'] ?? '') === 'signal' && $mine($d)) {
            if ($action === 'close') {
                $this->closeManual($sym);
                return ['status' => 'opened', 'detail' => 'позиция закрыта по сообщению канала'];
            }
            $this->moveStopToBreakeven($sym);
            return ['status' => 'opened', 'detail' => !empty($this->directional[$sym]['trail_be']) ? 'стоп перенесён в безубыток' : 'безубыток не применён (цена ещё не ушла в плюс)'];
        }
        if ($p && isset($p['signal']) && $mine($p['signal'])) {
            if ($action === 'close') {
                $this->closeManual($sym);
                return ['status' => 'opened', 'detail' => 'лимитный вход отменён по сообщению канала'];
            }
            return ['status' => 'skipped', 'detail' => 'позиции ещё нет (лимит не исполнен)'];
        }
        return ['status' => 'skipped', 'detail' => 'нет сделки этого канала по монете'];
    }

    /**
     * Цели сигнала — лестница reduce-only лимиток (равные доли позиции), ставится один раз, когда позиция уже есть на
     * бирже. После первой сработавшей цели стоп переносится в безубыток. Остаток ордеров снимается при закрытии.
     */
    private function applySignalLadder(string $sym, array $positions): void
    {
        $d = $this->directional[$sym] ?? null;
        $inst = $this->market->instruments[$sym] ?? null;
        if (!$d || !$inst || $d['strategy'] !== 'signal' || !isset($positions[$sym]) || isset($d['gone_ms'])) {
            return;
        }
        $long = $d['side'] === 'Buy';
        $posQty = (float)$positions[$sym]['qty'];
        if (!empty($d['ladder_done'])) {
            if (!($d['trail_be'] ?? false) && $posQty < $d['qty'] * 0.95) {
                $this->moveStopToBreakeven($sym);            // первая цель взята — защищаем остаток
            }
            return;
        }
        $targets = $d['targets'];
        $n = count($targets);
        while ($n > 1 && !$inst->qtyOk($inst->roundQty($posQty / $n), (float)$targets[0])) {
            $n--;
        }
        $closeSide = $long ? 'Sell' : 'Buy';
        $chosen = $n >= count($targets) ? $targets : array_slice($targets, 0, $n);
        $per = (float)$inst->roundQty($posQty / $n);
        $left = $posQty;
        try {
            foreach ($chosen as $i => $t) {
                $qty = $i === $n - 1 ? $left : $per;
                $left -= $per;
                $this->ex->placeLimit($sym, $closeSide, self::fmt($qty), (string)$inst->roundPrice((float)$t, $closeSide === 'Sell'),
                    'sigtp-' . bin2hex(random_bytes(4)), true);
            }
            $this->directional[$sym]['ladder_done'] = true;
            $this->directional[$sym]['qty'] = $posQty;
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: лестница целей сигнала $sym не выставлена: " . $e->getMessage());
        }
    }

    /**
     * Изменить SL/TP открытой позиции по команде трейдера. null — снять уровень. Проверяет сторону относительно текущей
     * цены. У позиции сетки стоп ведёт сама сетка (иначе разойдётся с её логикой) — редактировать нельзя.
     */
    public function editStops(string $sym, ?float $stop, ?float $take): string
    {
        if (isset($this->grids[$sym])) {
            throw new \RuntimeException('по монете работает сетка — её стоп и цели задаёт план сетки; закройте сетку целиком');
        }
        $pos = $this->ex->positions()[$sym] ?? null;
        if (!$pos) {
            throw new \RuntimeException("по $sym нет открытой позиции");
        }
        if (!$this->ensureMarket($sym)) {
            throw new \RuntimeException("не удалось получить данные по $sym");
        }
        $inst = $this->market->instruments[$sym];
        $price = (float)($pos['mark'] ?? $this->market->feeds[$sym]->price ?? $pos['entry']);
        $long = $pos['side'] === 'Buy';
        if ($stop !== null && ($stop <= 0 || ($long ? $stop >= $price : $stop <= $price))) {
            throw new \RuntimeException('стоп-лосс должен быть ' . ($long ? 'ниже' : 'выше') . ' текущей цены ' . self::fmt($price));
        }
        if ($take !== null && ($take <= 0 || ($long ? $take <= $price : $take >= $price))) {
            throw new \RuntimeException('тейк-профит должен быть ' . ($long ? 'выше' : 'ниже') . ' текущей цены ' . self::fmt($price));
        }
        $stopStr = $stop !== null ? (string)$inst->roundPrice($stop, !$long) : null;
        $takeStr = $take !== null ? (string)$inst->roundPrice($take, $long) : null;
        $this->ex->setTradingStop($sym, $stopStr, $takeStr);
        if (isset($this->directional[$sym])) {
            $this->directional[$sym]['stop'] = $stopStr !== null ? (float)$stopStr : 0.0;
            $this->directional[$sym]['take'] = $takeStr !== null ? (float)$takeStr : null;
            if ($stopStr !== null && $this->directional[$sym]['risk_usd'] > 0) {
                $this->directional[$sym]['trail_be'] = true;    // стоп задан вручную — автоматика его не «улучшает» задним числом
            }
        }
        $this->viewCacheTs = 0.0;
        ($this->notify)($this->userId, "🖐 Трейдер изменил $sym: SL " . ($stopStr ?? 'снят') . ', TP ' . ($takeStr ?? 'снят'));
        return 'SL ' . ($stopStr ?? 'снят') . ', TP ' . ($takeStr ?? 'снят');
    }

    /** Снять один открытый ордер по команде трейдера (лимитка входа, цель сигнала). Ордера сетки ведёт сама сетка. */
    public function cancelOrder(string $sym, string $link): string
    {
        if (isset($this->grids[$sym]) && isset($this->grids[$sym]->orders[$link])) {
            throw new \RuntimeException('это ордер сетки — снимите сетку целиком кнопкой «Закрыть»');
        }
        $known = false;
        foreach ($this->ex->openOrders() as $o) {
            $known = $known || ($o['symbol'] === $sym && $o['link'] === $link);
        }
        if (!$known) {
            throw new \RuntimeException('ордер уже не активен (исполнен или снят)');
        }
        $this->ex->cancel($sym, $link);
        if (($this->pendingManual[$sym]['link'] ?? null) === $link) {
            unset($this->pendingManual[$sym]);
        }
        $this->viewCacheTs = 0.0;
        return 'ордер снят';
    }

    /**
     * Ручной ордер трейдера из админ-панели: монета, рынок/лимит, тейк/стоп, плечо.
     * @param array{symbol:string,side:string,order_type:string,qty:float,price:?float,stop_loss:?float,take_profit:?float,leverage:?int,id:int} $o
     * @return array{status:string,detail:string}
     */
    public function manualOrder(array $o): array
    {
        $sym = (string)$o['symbol'];
        // Ручные сделки не ограничены символами автостратегий клиента — агент их не анализирует, трейдер
        // выбирает монету сам; Market лениво подгружает инструмент+цену для любой реальной монеты Bybit.
        if (!$this->ensureMarket($sym)) {
            throw new \RuntimeException("монета $sym не найдена на Bybit (фьючерсы) или сейчас недоступна");
        }
        if (isset($this->grids[$sym]) || isset($this->directional[$sym]) || isset($this->pendingManual[$sym])) {
            throw new \RuntimeException("по $sym уже есть открытая позиция, сетка или неисполненный ордер");
        }
        $inst = $this->market->instruments[$sym];
        $price = $this->market->feeds[$sym]->price;
        if (!$price) {
            throw new \RuntimeException('нет текущей цены по монете, попробуйте через минуту');
        }
        if (!empty($o['leverage'])) {
            $this->ex->setLeverage($sym, (int)min(max(1, (int)$o['leverage']), $inst->maxLeverage));
            $this->leverageSet[$sym] = true;
        }
        $side = $o['side'] === 'Sell' ? 'Sell' : 'Buy';
        $long = $side === 'Buy';
        $q = $inst->roundQty((float)$o['qty']);                // строка — для вызовов биржи
        $qty = (float)$q;                                      // число — для directional/pendingManual
        $refPrice = $o['order_type'] === 'limit' ? (float)$o['price'] : $price;
        if ($qty <= 0 || !$inst->qtyOk($q, $refPrice)) {
            throw new \RuntimeException('слишком маленький объём для этой монеты');
        }
        $stop = $o['stop_loss'] ? (string)$inst->roundPrice((float)$o['stop_loss'], !$long) : null;
        $take = $o['take_profit'] ? (string)$inst->roundPrice((float)$o['take_profit'], $long) : null;
        if ($o['order_type'] === 'limit') {
            $limitPrice = (string)$inst->roundPrice((float)$o['price'], false);
            $linkId = 'manual-' . bin2hex(random_bytes(6));
            $this->ex->placeLimit($sym, $side, $q, $limitPrice, $linkId, false, $stop, $take);
            $this->pendingManual[$sym] = ['link' => $linkId, 'order_id' => (int)$o['id'], 'side' => $side, 'qty' => $qty,
                'stop' => $stop !== null ? (float)$stop : null, 'take' => $take !== null ? (float)$take : null, 'placed_ms' => (int)($this->now() * 1000)];
            ($this->notify)($this->userId, "🖐 Трейдер выставил лимитный ордер: $sym " . ($long ? 'LONG' : 'SHORT') . " по $limitPrice");
            return ['status' => 'placed', 'detail' => "лимитный ордер по $limitPrice выставлен, ждём исполнения"];
        }
        $this->ex->placeMarket($sym, $side, $q, $stop, $take);
        $this->directional[$sym] = ['strategy' => 'manual', 'side' => $side, 'entry' => $price, 'stop' => $stop !== null ? (float)$stop : 0.0,
            'qty' => $qty, 'regime' => 'manual', 'opened_ms' => (int)($this->now() * 1000),
            'risk_usd' => $stop !== null ? abs($price - (float)$stop) * $qty : 0.0];
        ($this->notify)($this->userId, '🖐 Трейдер открыл ' . ($long ? '🟢 LONG' : '🔴 SHORT') . " $sym по рынку\nВход ~" . self::fmt($price)
            . ($stop !== null ? ' · SL ' . $stop : '') . ($take !== null ? ' · TP ' . $take : ''));
        return ['status' => 'done', 'detail' => 'позиция открыта по рынку, вход ~' . self::fmt($price)];
    }

    /** Ждём исполнения лимитных ручных ордеров; при исполнении — в directional, чтобы отследить закрытие как обычно. */
    private function checkPendingManual(array $positions, float $now): void
    {
        $nowMs = (int)($now * 1000);
        foreach ($this->pendingManual as $sym => $p) {
            if ($nowMs - $p['placed_ms'] < 2000) {
                continue;                                     // даём бирже время исполнить/отразить ордер
            }
            if (isset($p['signal']) && $nowMs - $p['placed_ms'] > self::SIGNAL_LIMIT_TTL_SEC * 1000) {
                try {
                    $this->ex->cancel($sym, $p['link']);
                } catch (\Throwable) {
                }
                unset($this->pendingManual[$sym]);          // сигнал устарел, цена в зону так и не пришла
                continue;
            }
            try {
                $r = $this->ex->orderResult($sym, $p['link']);
            } catch (\Throwable $e) {
                continue;                                     // попробуем на следующем такте
            }
            if (in_array($r['status'], ['Filled', 'PartiallyFilled'], true) && ($r['filled_qty'] > 0 || isset($positions[$sym]))) {
                unset($this->pendingManual[$sym]);
                $entry = $r['avg_price'] > 0 ? $r['avg_price'] : ($positions[$sym]['entry'] ?? 0.0);
                $qty = $r['filled_qty'] > 0 ? $r['filled_qty'] : $p['qty'];
                $this->directional[$sym] = ['strategy' => isset($p['signal']) ? 'signal' : 'manual', 'side' => $p['side'], 'entry' => $entry, 'stop' => $p['stop'] ?? 0.0,
                    'qty' => $qty, 'regime' => isset($p['signal']) ? 'signal' : 'manual', 'opened_ms' => $nowMs,
                    'risk_usd' => $p['stop'] ? abs($entry - $p['stop']) * $qty : 0.0] + (isset($p['signal']) ? ['targets' => $p['signal']['targets'], 'signal_id' => $p['signal']['signal_id'] ?? 0, 'channel_id' => $p['signal']['channel_id'] ?? null] : []);
                ($this->notify)($this->userId, (isset($p['signal']) ? '📡 ' : '🖐 ') . "Лимитный ордер по $sym исполнен по " . self::fmt($entry));
                $this->reportOrder((int)$p['order_id'], 'filled', 'исполнен по ' . self::fmt($entry));
            } elseif (in_array($r['status'], ['Cancelled', 'Rejected', 'Deactivated'], true)) {
                unset($this->pendingManual[$sym]);
                ($this->notify)($this->userId, "🖐 Лимитный ордер по $sym отменён биржей ({$r['status']})");
                $this->reportOrder((int)$p['order_id'], 'cancelled', 'отменён биржей: ' . $r['status']);
            }
        }
    }

    /**
     * Позиция могла появиться на бирже без ведома Worker: трейдер открыл её прямо на Bybit (мимо ручной
     * торговли бота), либо она уже была открыта, а демон перезапустился — $directional/$grids не хранятся
     * между рестартами. Без этого такие позиции не попадают ни в directional, ни в grids, и checkDirectional()
     * никогда не заметит их закрытие — сделка молча пропадает из статистики. Берём такую позицию под
     * наблюдение (strategy=manual — бот её не трогает: без трейлинга/частичного тейка/принудительного
     * закрытия по времени), стоп и риск неизвестны (r=0), но сумма и PnL в статистике будут настоящими.
     */
    private function reconcileExternalPositions(array $positions, float $now): void
    {
        foreach ($positions as $sym => $p) {
            if (isset($this->directional[$sym]) || isset($this->grids[$sym]) || isset($this->pendingManual[$sym])) {
                continue;
            }
            $this->directional[$sym] = ['strategy' => 'manual', 'side' => $p['side'], 'entry' => $p['entry'], 'stop' => 0.0,
                'qty' => $p['qty'], 'regime' => 'external', 'opened_ms' => (int)($now * 1000), 'risk_usd' => 0.0];
            Log::info("user {$this->userId}: $sym — позиция вне учёта бота (открыта напрямую на бирже или потеряна после рестарта), взята под наблюдение для статистики");
        }
    }

    /** Отмена ещё не исполненного ручного лимитного ордера по команде из админки. */
    public function cancelManualOrder(string $sym, int $orderId): bool
    {
        $p = $this->pendingManual[$sym] ?? null;
        if (!$p || $p['order_id'] !== $orderId) {
            return false;
        }
        $this->ex->cancel($sym, $p['link']);
        unset($this->pendingManual[$sym]);
        return true;
    }

    /** Закрыть по команде трейдера всё, что сейчас есть по монете: сетку, позицию или неисполненный ордер. */
    public function closeManual(string $sym): void
    {
        if (isset($this->grids[$sym])) {
            $grid = $this->grids[$sym];
            try {
                $ev = $grid->stop($this->market->feeds[$sym]->price ?? $grid->plan['center'], $this->now());
                if ($ev) {
                    $this->onGridEvent($sym, $grid, $ev, $this->brain->regimes[$sym] ?? Regime::NO_TRADE, $this->now(), 'manual');
                }
            } catch (\Throwable $e) {
                Log::error("user {$this->userId}: не удалось закрыть сетку $sym по команде трейдера: " . $e->getMessage());
            }
            unset($this->grids[$sym]);
            ($this->notify)($this->userId, "🖐 Трейдер закрыл сетку по $sym");
            return;
        }
        if (isset($this->pendingManual[$sym])) {
            $this->ex->cancel($sym, $this->pendingManual[$sym]['link']);
            unset($this->pendingManual[$sym]);
            ($this->notify)($this->userId, "🖐 Трейдер отменил неисполненный ордер по $sym");
            return;
        }
        if (isset($this->directional[$sym]) || isset($this->ex->positions()[$sym])) {
            $this->ex->closePosition($sym);            // закрытие увидит checkDirectional на следующем такте и запишет сделку
            ($this->notify)($this->userId, "🖐 Трейдер закрыл позицию по $sym");
            return;
        }
        throw new \RuntimeException("по $sym нет ни сетки, ни позиции, ни неисполненного ордера");
    }

    private function reportOrder(int $orderId, string $status, ?string $detail = null): void
    {
        if ($this->orderUpdate && $orderId > 0) {
            ($this->orderUpdate)($orderId, $status, $detail);
        }
    }

    private function checkDirectional(array $positions, float $now): void
    {
        $nowMs = (int)($now * 1000);
        foreach ($this->directional as $sym => $d) {
            if (isset($positions[$sym])) {
                unset($this->directional[$sym]['gone_ms']);
                $maxHold = match ($d['strategy']) { 'trend' => self::TREND_MAX_HOLD_SEC, 'breakout' => self::BREAKOUT_MAX_HOLD_SEC, default => self::MAX_HOLD_SEC };
                if (!in_array($d['strategy'], self::UNMANAGED, true) && $nowMs - $d['opened_ms'] > $maxHold * 1000) {
                    $this->ex->closePosition($sym);
                }
                continue;
            }
            if ($nowMs - $d['opened_ms'] < 3000) {
                continue;
            }
            $goneMs = $d['gone_ms'] ?? $nowMs;
            $this->directional[$sym]['gone_ms'] = $goneMs;
            $closed = $this->ex->closedPnl($sym, $d['opened_ms'] - 1000);
            // после частичного тейка в истории уже есть его запись — ждём ещё и запись финального закрытия
            $partial = (bool)($d['partial_done'] ?? false);
            $complete = count($closed) >= ($partial ? 2 : 1);
            if (!$complete && $nowMs - $goneMs < self::CLOSED_PNL_WAIT_MS) {
                continue;                                     // Bybit ещё не отразил закрытие в closed-pnl — иначе запишем PnL 0 вместо реального
            }
            $pnl = array_sum(array_column($closed, 'pnl'));
            $exit = $closed ? (float)end($closed)['exit'] : $d['entry'];
            if (!$complete) {
                $exit = ($this->market->feeds[$sym]->price ?? 0.0) ?: $d['entry'];
                $restQty = $partial ? $d['qty'] / 2 : $d['qty'];
                $pnl += ($d['side'] === 'Buy' ? 1 : -1) * ($exit - $d['entry']) * $restQty
                    - ($d['entry'] + $exit) * $restQty * ExchangeInterface::TAKER_FEE;
                Log::warn("user {$this->userId}: $sym — closed-pnl с биржи не пришёл за минуту, PnL оценён по последней цене");
            }
            $pnl -= $this->fundingEstimate($sym, $d['side'], $d['qty'] * $d['entry'], $now - $d['opened_ms'] / 1000);
            $r = $d['risk_usd'] ? $pnl / $d['risk_usd'] : 0.0;
            unset($this->directional[$sym]);
            if ($r < -1.3) {
                // Стоп должен ограничивать убыток примерно 1R — заметный перебор стоит разобрать по этим числам.
                Log::warn(sprintf('user %d: %s %s — убыток %.2fR больше расчётного риска (вход %s, стоп %s, выход %s, объём %s)',
                    $this->userId, $sym, $d['strategy'], $r, self::fmt($d['entry']), self::fmt($d['stop']), self::fmt($exit), self::fmt($d['qty'])));
            }
            if ($r <= self::BIG_LOSS_R_THRESHOLD) {
                $this->lastBigLoss[$sym] = $now;              // Cooldown: крупный убыток — пауза перед новой направленной ставкой на этой монете
            }
            $this->recordTrade($sym, $d['strategy'], $d['side'], $d['qty'], $d['entry'], $exit, $pnl, $r, $d['regime'],
                !empty($d['signal_id']) ? 'sig' . $d['signal_id'] : null);
            if ($d['strategy'] === 'signal') {
                try {
                    $this->ex->cancelAll($sym);              // закрыл стоп — оставшиеся ордера лестницы целей больше не нужны
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Забираем реальный PnL стопов сетки на реальной бирже (см. Grid::stop()) — сама сделка (маркет-ордер на
     * закрытие) уже отправлена, ждём, пока Bybit отразит её в /v5/position/closed-pnl, чтобы посчитать PnL
     * по фактической цене исполнения, а не по цене ДО отправки ордера (там может быть проскальзывание).
     */
    private function checkGridStops(float $now): void
    {
        $nowMs = (int)($now * 1000);
        foreach ($this->grids as $sym => $grid) {
            $p = $grid->pendingStop;
            if ($p === null) {
                continue;
            }
            $placed = $p['placed_ms'] ?? $p['since_ms'];
            if ($nowMs - $placed < 3000) {
                continue;                                      // даём бирже время провести маркет-ордер
            }
            // с последнего учтённого события сетки — чтобы не посчитать дважды уже записанные циклы
            $closed = $this->ex->closedPnl($sym, $p['since_ms']);
            if (!$closed && $nowMs - $placed < 60_000) {
                continue;                                      // ещё не отразилось в истории биржи — проверим на следующем такте
            }
            if ($closed) {
                $pnl = array_sum(array_column($closed, 'pnl'));
                $exit = (float)end($closed)['exit'];
            } else {
                // биржа не ответила за минуту — не теряем сделку из статистики, считаем по последней известной цене
                $exit = $this->market->feeds[$sym]->price ?: $p['entry'];
                $pnl = $grid->stopPnl($p['entry'], $exit, $p['qty']);
                Log::warn("user {$this->userId}: $sym стоп сетки — не дождались closedPnl с биржи за минуту, PnL оценён по последней цене");
            }
            $grid->pendingStop = null;
            unset($this->grids[$sym]);
            $reason = $this->pendingStopReason[$sym] ?? 'stop';
            unset($this->pendingStopReason[$sym]);
            $this->onGridEvent($sym, $grid, ['kind' => 'stop', 'side' => $p['side'], 'qty' => $p['qty'], 'entry' => $p['entry'],
                'exit' => $exit, 'pnl' => $pnl], $this->brain->regimes[$sym] ?? Regime::NO_TRADE, $now, $reason);
        }
    }

    private function recordTrade(string $sym, string $strategy, string $side, float $qty, float $entry, float $exit,
                                 float $pnl, float $r, string $regime, ?string $sessionId = null, ?string $kind = null): void
    {
        $t = compact('strategy', 'side', 'qty', 'entry', 'exit', 'pnl', 'r', 'regime') + ['symbol' => $sym, 'mode' => $this->ex->mode(),
            'session_id' => $sessionId, 'kind' => $kind];
        if ($this->ex instanceof PaperExchange) {
            $t['paper_balance'] = $this->ex->balance;
        }
        ($this->record)($this->userId, $t);
        if (in_array($strategy, ['trend', 'liquidation', 'breakout'], true)) {
            $this->guard->recordDirectionalTrade();
        }
        $this->guard->onTrade($pnl, $this->now());
        $this->trackStrategyDrawdown($strategy, $pnl);
        // Уведомления о входах/выходах и паузе в Telegram отключены — клиент смотрит сделки и статус в приложении.
    }

    /** Позиции и открытые ордера с плавающим результатом — для вкладки «Ордера и позиции» (кэш на несколько секунд). */
    private function liveExchangeView(): array
    {
        $now = microtime(true);
        if ($this->viewCache && $now - $this->viewCacheTs < 8) {
            return $this->viewCache;
        }
        $positions = [];
        $orders = [];
        try {
            foreach ($this->ex->positions() as $sym => $p) {
                $d = $this->directional[$sym] ?? null;
                $mark = $p['mark'] ?? ($this->market->feeds[$sym]->price ?? null) ?: $p['entry'];
                $sign = $p['side'] === 'Buy' ? 1 : -1;
                $upnl = $p['upnl'] ?? $sign * ($mark - $p['entry']) * $p['qty'];
                $kind = isset($this->grids[$sym]) ? 'grid' : ($d['strategy'] ?? 'external');
                $positions[] = ['symbol' => $sym, 'side' => $p['side'], 'qty' => $p['qty'], 'entry' => $p['entry'], 'mark' => $mark,
                    'stop' => $p['stop'] ?? ($d['stop'] ?? null) ?: null, 'take' => $p['take'] ?? null,
                    'upnl' => round($upnl, 4), 'pnl_pct' => $p['entry'] > 0 ? round($sign * ($mark / $p['entry'] - 1) * 100, 3) : 0.0,
                    'pct_equity' => $this->equity > 0 ? round($upnl / $this->equity * 100, 3) : 0.0,
                    'strategy' => $kind, 'editable' => $kind !== 'grid'];
            }
            foreach ($this->ex->openOrders() as $o) {
                $link = $o['link'];
                $origin = str_starts_with($link, 'sigtp-') ? 'target' : (str_starts_with($link, 'sig-') ? 'signal'
                    : (str_starts_with($link, 'manual-') ? 'manual' : (isset($this->grids[$o['symbol']]) ? 'grid' : 'other')));
                $orders[] = $o + ['origin' => $origin, 'cancelable' => $origin !== 'grid'];
            }
        } catch (\Throwable $e) {
            Log::warn("user {$this->userId}: состояние для админки не собралось: " . $e->getMessage());
        }
        $this->viewCacheTs = $now;
        return $this->viewCache = ['positions' => $positions, 'orders' => $orders];
    }

    public function liveState(): array
    {
        return $this->liveExchangeView() + [
            'grids' => array_map(fn($s, $g) => ['symbol' => $s, 'mode' => $g->plan['mode'], 'step_pct' => round($g->plan['step_pct'], 3),
                'filled' => count($g->inventory), 'levels' => $g->plan['levels']], array_keys($this->grids), array_values($this->grids)),
            'directional' => array_map(fn($s, $d) => ['symbol' => $s, 'strategy' => $d['strategy'], 'side' => $d['side'],
                'entry' => $d['entry'], 'stop' => $d['stop']], array_keys($this->directional), array_values($this->directional)),
            'pending_manual' => array_map(fn($s, $p) => ['symbol' => $s, 'side' => $p['side'], 'qty' => $p['qty']],
                array_keys($this->pendingManual), array_values($this->pendingManual)),
            'manual_mode' => $this->manualMode,
        ];
    }

    /**
     * Max strategy drawdown: если реализованный результат стратегии у клиента упал от своего пика больше чем на
     * max_strategy_dd_pct депозита — стратегия на паузе STRATEGY_PAUSE_SEC. Ручные сделки не считаются.
     */
    private function trackStrategyDrawdown(string $strategy, float $pnl): void
    {
        if ($strategy === 'manual' || $this->equity <= 0) {
            return;
        }
        $st = $this->strategyPnl[$strategy] ?? ['cum' => 0.0, 'peak' => 0.0];
        $st['cum'] += $pnl;
        $st['peak'] = max($st['peak'], $st['cum']);
        $limit = $this->equity * (float)($this->prof['max_strategy_dd_pct'] ?? 100) / 100;
        if ($st['peak'] - $st['cum'] >= $limit) {
            $this->strategyPausedUntil[$strategy] = $this->now() + self::STRATEGY_PAUSE_SEC;
            $st = ['cum' => 0.0, 'peak' => 0.0];            // после паузы — отсчёт просадки заново
            Log::warn("user {$this->userId}: стратегия $strategy на паузе 24ч — просадка больше {$this->prof['max_strategy_dd_pct']}% депозита");
        }
        $this->strategyPnl[$strategy] = $st;
    }

    /** Состояние, которое должно пережить рестарт демона (сохраняется в worker_state.risk_state). */
    public function exportState(): array
    {
        return ['guard' => $this->guard->export(), 'last_grid_stop' => $this->lastGridStop, 'last_big_loss' => $this->lastBigLoss,
            'strategy_pnl' => $this->strategyPnl, 'strategy_paused_until' => $this->strategyPausedUntil];
    }

    public function importState(array $s): void
    {
        $this->guard->import((array)($s['guard'] ?? []));
        $this->lastGridStop = array_map('floatval', (array)($s['last_grid_stop'] ?? []));
        $this->lastBigLoss = array_map('floatval', (array)($s['last_big_loss'] ?? []));
        $this->strategyPnl = (array)($s['strategy_pnl'] ?? []);
        $this->strategyPausedUntil = array_map('floatval', (array)($s['strategy_paused_until'] ?? []));
    }

    /** Kill switch: отменить все ордера сеток и закрыть по рынку все позиции клиента, включая ручные и внешние. */
    private function emergencyFlatten(): void
    {
        if ($this->haltDone) {
            return;
        }
        foreach ($this->grids as $sym => $grid) {
            try {
                $ev = $grid->stop($this->market->feeds[$sym]->price ?? $grid->plan['center'], $this->now());
                if ($ev) {
                    $this->onGridEvent($sym, $grid, $ev, $this->brain->regimes[$sym] ?? Regime::NO_TRADE, $this->now(), 'emergency');
                }
            } catch (\Throwable $e) {
                Log::error("user {$this->userId}: аварийная остановка — сетка $sym: " . $e->getMessage());
            }
        }
        foreach ($this->pendingManual as $sym => $p) {
            try {
                $this->ex->cancel($sym, $p['link']);
            } catch (\Throwable) {
            }
        }
        $this->pendingManual = [];
        foreach (array_keys($this->ex->positions()) as $sym) {
            try {
                $this->ex->closePosition($sym);
            } catch (\Throwable $e) {
                Log::error("user {$this->userId}: аварийная остановка — позиция $sym: " . $e->getMessage());
            }
        }
        $this->haltDone = true;
        Log::warn("user {$this->userId}: АВАРИЙНАЯ ОСТАНОВКА — позиции закрыты");
    }

    /**
     * Остановка воркера. Бумажный счёт живёт только в памяти — его сетки закрываем, иначе позиции просто исчезнут.
     * На реальной бирже сетки НЕ закрываем по рынку (это фиксировало плавающий убыток на каждом рестарте):
     * снимаем ордера на новые входы, а набранная позиция остаётся с тейками и стоп-лоссом на бирже; после
     * запуска её подхватит reconcileExternalPositions() и запишет результат в статистику при закрытии.
     */
    public function shutdown(): void
    {
        foreach ($this->grids as $sym => $grid) {
            try {
                if ($this->ex->mode() === 'paper') {
                    $ev = $grid->stop($this->market->feeds[$sym]->price ?? $grid->plan['center'], $this->now());
                    if ($ev) {
                        $this->onGridEvent($sym, $grid, $ev, $this->brain->regimes[$sym] ?? Regime::NO_TRADE, $this->now());
                    }
                } else {
                    $grid->detach();
                }
            } catch (\Throwable $e) {
                Log::error("user {$this->userId}: не удалось остановить сетку $sym: " . $e->getMessage());
            }
        }
        $this->grids = [];
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(sprintf('%.8F', $v), '0'), '.');
    }
}
