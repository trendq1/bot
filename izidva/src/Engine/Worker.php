<?php
declare(strict_types=1);

namespace App\Engine;

use App\Log;

/**
 * Торговый процесс одного клиента. На каждом такте:
 * 1) депозит и дневной лимит убытка; 2) выбор стратегии по монете: вес ИИ × множитель обучения × настройки клиента;
 * 3) ведение сеток, открытие направленных сделок, фиксация закрытых сделок.
 * На одной монете одновременно работает одна стратегия (one-way режим позиций).
 */
final class Worker
{
    public const MAX_HOLD_SEC = 3 * 3600;
    public const LIQ_COOLDOWN_SEC = 20 * 60;
    /** Монеты, которые обычно двигаются вместе — не берём вторую направленную ставку в той же группе одновременно. */
    public const CORRELATION_GROUPS = [
        ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'AVAXUSDT'],
        ['XRPUSDT', 'ADAUSDT', 'DOGEUSDT', 'LTCUSDT'],
    ];
    /** С какой прибыли (в R) переносим стоп в безубыток / начинаем трейлить, и на каком расстоянии (тоже в R). */
    private const TRAIL_BREAKEVEN_R = 0.8;
    private const TRAIL_START_R = 1.5;
    private const TRAIL_DISTANCE_R = 0.6;
    private const PARTIAL_TAKE_R = 1.0;

    public RiskGuard $guard;
    /** @var array<string,Grid> */
    public array $grids = [];
    /** symbol => [strategy, side, entry, stop, qty, regime, opened_ms, risk_usd] */
    public array $directional = [];
    /** Лимитные ручные ордера, ждущие исполнения: symbol => [link, order_id, side, qty, stop, take, placed_ms] */
    public array $pendingManual = [];
    public array $lastTrendBar = [];
    public array $lastLiq = [];
    public float $equity = 0.0;
    public string $status = 'запуск';
    private float $equityTs = 0.0;
    private float $snapTs = 0.0;
    private array $leverageSet = [];

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
    ) {
        $this->symbols = array_values(array_filter($symbols, fn($s) => isset($market->feeds[$s])));
        $this->guard = new RiskGuard($prof);
    }

    public function step(float $now): void
    {
        if ($this->ex instanceof PaperExchange) {
            $this->ex->updatePrices($this->market->prices());
        }
        if ($now - $this->equityTs > 30 || !$this->equity) {
            $this->equity = $this->ex->equity();
            $this->guard->updateEquity($this->equity);
            $this->equityTs = $now;
        }
        if ($now - $this->snapTs > 900) {
            ($this->snapshot)($this->userId, $this->equity);
            $this->snapTs = $now;
        }
        [$allowed, $reason] = $this->guard->allowed($this->equity, $now);
        $positions = $this->ex->positions();
        $this->checkDirectional($positions, $now);
        $this->checkPendingManual($positions, $now);
        foreach ($this->symbols as $sym) {
            $this->manageSymbol($sym, $positions, $allowed, $now);
        }
        $grids = implode(', ', array_map(fn($s, $g) => "$s:{$g->plan['mode']}", array_keys($this->grids), $this->grids));
        $mode = $this->manualMode ? 'ручной режим' : 'работает';
        $this->status = $allowed ? "$mode · сетки: " . ($grids ?: 'нет') . ' · сделки: ' . count($this->directional) : "⏸ $reason";
    }

    public function weights(string $sym, array $ins): array
    {
        $m = fn($s) => $this->brain->learner->mult($sym, $s, $ins['regime']);
        return [
            'grid' => ($this->enabled['grid'] ?? true) ? $ins['w_grid'] * $m('grid') : 0.0,
            'trend' => ($this->enabled['trend'] ?? true) ? $ins['w_trend'] * $m('trend') : 0.0,
            'liquidation' => ($this->enabled['liquidation'] ?? true) ? $ins['w_liquidation'] * $m('liquidation') : 0.0,
        ];
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
        if (!isset($this->leverageSet[$sym])) {
            $this->ex->setLeverage($sym, (int)min($this->prof['leverage'], $inst->maxLeverage));
            $this->leverageSet[$sym] = true;
        }
        if ($this->checkReversal($sym, $ins, $positions)) {
            return;                                          // позицию только что закрыли по развороту — на этом такте всё
        }
        $this->applyPartialTake($sym, $feed->price);
        $this->applyTrailing($sym, $feed->price);
        $w = $this->weights($sym, $ins);
        $tuning = $this->brain->tuning[$sym] ?? [];

        if (isset($this->grids[$sym])) {
            $grid = $this->grids[$sym];
            foreach ($grid->sync($feed->price) as $ev) {
                $this->onGridEvent($sym, $grid, $ev, $ins['regime']);
            }
            $want = $allowed && $ins['grid_mode'] === $grid->plan['mode'] && $w['grid'] >= 0.4;
            if ($grid->active && !$grid->draining && !$want) {
                $grid->drain();
            } elseif ($grid->active && $grid->idleFar($feed->price)) {
                $grid->stop($feed->price);
            }
            if (!$grid->active) {
                unset($this->grids[$sym]);
            }
            return;
        }
        if ($this->manualMode || !$allowed || isset($this->directional[$sym]) || isset($positions[$sym]) || isset($this->pendingManual[$sym])) {
            return;                                          // в ручном режиме автостратегии новых сделок не открывают
        }
        arsort($w);
        $best = array_key_first($w);
        if ($best === 'grid' && $w['grid'] >= 0.5 && $ins['grid_mode'] !== 'off' && count($this->grids) < $this->prof['max_grids']) {
            $this->startGrid($sym, $f, $ins, $tuning);
            return;
        }
        if (count($this->directional) >= $this->prof['max_directional']) {
            return;
        }
        if ($this->guard->directionalTradesLeft() <= 0) {
            return;                                          // дневной лимит числа направленных сделок исчерпан
        }
        if ($this->correlatedDirectionalOpen($sym)) {
            return;                                          // по коррелирующей монете уже есть направленная ставка
        }
        if ($this->quietHours($now) || $this->quietMarket($f)) {
            return;                                          // тихие часы или мёртвая волатильность — новых ставок не берём
        }
        $setup = null;
        if ($w['trend'] >= 0.5 && ($this->lastTrendBar[$sym] ?? null) !== $f['last_closed_ts']) {
            $this->lastTrendBar[$sym] = $f['last_closed_ts'];
            $setup = Setups::trend(['price' => $feed->price] + $f, $ins['regime'], (float)($tuning['trend_rr'] ?? $this->prof['rr']));
        }
        if ($setup === null && $w['liquidation'] >= 0.4 && $now - ($this->lastLiq[$sym] ?? 0) > self::LIQ_COOLDOWN_SEC) {
            $setup = Setups::liquidation($feed, $f, (float)($tuning['liq_threshold_mult'] ?? AIAnalyst::TUNABLE['liq_threshold_mult'][2]), $now);
            if ($setup) {
                $this->lastLiq[$sym] = $now;
            }
        }
        if ($setup) {
            $this->openDirectional($sym, $setup, $ins, $w[$setup['strategy']]);
        }
    }

    private function startGrid(string $sym, array $f, array $ins, array $tuning): void
    {
        $inst = $this->market->instruments[$sym];
        $price = $this->market->feeds[$sym]->price;
        $capital = $this->equity * $this->prof['grid_alloc'] / $this->prof['max_grids'] * $ins['risk_mult'];
        $maxLoss = $this->equity * $this->prof['grid_max_loss_pct'] / 100 * $ins['risk_mult'];
        $plan = Grid::plan($price, $f['atr'], $inst, $ins['grid_mode'], (float)($tuning['grid_step_atr'] ?? $ins['grid_step_atr']),
            $this->prof['grid_levels'], $capital, (int)min($this->prof['leverage'], $inst->maxLeverage), $maxLoss);
        if ($plan === null) {
            return;
        }
        $grid = new Grid($this->ex, $sym, $inst, $plan);
        $grid->start();
        $this->grids[$sym] = $grid;
        Log::info(sprintf('user %d: сетка %s %s шаг %.2f%% x%d, объём уровня %s', $this->userId, $sym, $plan['mode'],
            $plan['step_pct'], $plan['levels'], $plan['qty']));
    }

    private function onGridEvent(string $sym, Grid $grid, array $ev, string $regime): void
    {
        $this->recordTrade($sym, 'grid', $ev['side'], $ev['qty'], $ev['entry'], $ev['exit'], $ev['pnl'],
            $ev['pnl'] / $grid->unitRisk(), $regime);
    }

    /**
     * Досрочный выход из направленной сделки (тренд/ликвидации), если рынок развернулся против позиции —
     * не ждём полного стопа или тейка. Не трогает ручные сделки трейдера и сетки (у них своя логика выхода).
     * @return bool true — позицию закрыли (или попытались), дальше на этом такте по монете делать нечего.
     */
    private function checkReversal(string $sym, array $ins, array $positions): bool
    {
        $d = $this->directional[$sym] ?? null;
        if (!$d || $d['strategy'] === 'manual' || !isset($positions[$sym])) {
            return false;
        }
        $nowMs = (int)(microtime(true) * 1000);
        if ($nowMs - $d['opened_ms'] < 60_000) {
            return false;                                     // не дёргаемся в первую минуту после входа
        }
        $long = $d['side'] === 'Buy';
        $reversed = $long ? $ins['regime'] === 'trend_down' : $ins['regime'] === 'trend_up';
        if (!$reversed) {
            return false;
        }
        try {
            $this->ex->closePosition($sym);                 // закрытие увидит checkDirectional на следующем такте и запишет сделку
            Log::info("user {$this->userId}: $sym закрыт досрочно — разворот тренда против позиции ({$ins['regime']})");
        } catch (\Throwable $e) {
            Log::error("user {$this->userId}: не удалось закрыть $sym при развороте: " . $e->getMessage());
        }
        return true;
    }

    /** Половина позиции фиксируется на +1R, остаток ведём дальше (трейлингом) — снижает разброс результата. */
    private function applyPartialTake(string $sym, float $price): void
    {
        $d = $this->directional[$sym] ?? null;
        $inst = $this->market->instruments[$sym] ?? null;
        if (!$d || !$inst || $d['strategy'] === 'manual' || !$price || ($d['partial_done'] ?? false) || $d['risk_usd'] <= 0) {
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
        if (!$d || !$inst || $d['strategy'] === 'manual' || !$price || $d['risk_usd'] <= 0 || (float)$d['stop'] <= 0) {
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

    /** По коррелирующей монете (см. CORRELATION_GROUPS) уже есть автоматическая направленная ставка. */
    private function correlatedDirectionalOpen(string $sym): bool
    {
        foreach (self::CORRELATION_GROUPS as $group) {
            if (!in_array($sym, $group, true)) {
                continue;
            }
            foreach ($group as $other) {
                if ($other !== $sym && isset($this->directional[$other]) && $this->directional[$other]['strategy'] !== 'manual') {
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

    private function openDirectional(string $sym, array $s, array $ins, float $weight): void
    {
        $inst = $this->market->instruments[$sym];
        $riskUsd = $this->equity * $this->prof['risk_pct'] / 100 * $ins['risk_mult'] * min(1.2, max(0.5, $weight));
        $qty = min($riskUsd / $s['risk'], $this->equity * $this->prof['leverage'] * 0.9 / $s['entry'], $inst->maxMktQty);
        $q = $inst->roundQty($qty);
        if (!$inst->qtyOk($q, $s['entry'])) {
            return;
        }
        $long = $s['side'] === 'Buy';
        $stop = $inst->roundPrice($s['stop'], !$long);
        $take = $inst->roundPrice($s['take'], !$long);
        $this->ex->placeMarket($sym, $s['side'], $q, $stop, $take);
        $this->directional[$sym] = ['strategy' => $s['strategy'], 'side' => $s['side'], 'entry' => $s['entry'], 'stop' => (float)$stop,
            'qty' => (float)$q, 'regime' => $ins['regime'], 'opened_ms' => (int)(microtime(true) * 1000), 'risk_usd' => (float)$q * $s['risk']];
        // Уведомление о входе стратегии в Telegram отключено — клиент видит открытые позиции в приложении.
    }

    /**
     * Ручной ордер трейдера из админ-панели: монета, рынок/лимит, тейк/стоп, плечо.
     * @param array{symbol:string,side:string,order_type:string,qty:float,price:?float,stop_loss:?float,take_profit:?float,leverage:?int,id:int} $o
     * @return array{status:string,detail:string}
     */
    public function manualOrder(array $o): array
    {
        $sym = (string)$o['symbol'];
        if (!in_array($sym, $this->symbols, true) || !isset($this->market->instruments[$sym])) {
            throw new \RuntimeException("монета $sym недоступна для этого клиента");
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
                'stop' => $stop !== null ? (float)$stop : null, 'take' => $take !== null ? (float)$take : null, 'placed_ms' => (int)(microtime(true) * 1000)];
            ($this->notify)($this->userId, "🖐 Трейдер выставил лимитный ордер: $sym " . ($long ? 'LONG' : 'SHORT') . " по $limitPrice");
            return ['status' => 'placed', 'detail' => "лимитный ордер по $limitPrice выставлен, ждём исполнения"];
        }
        $this->ex->placeMarket($sym, $side, $q, $stop, $take);
        $this->directional[$sym] = ['strategy' => 'manual', 'side' => $side, 'entry' => $price, 'stop' => $stop !== null ? (float)$stop : 0.0,
            'qty' => $qty, 'regime' => 'manual', 'opened_ms' => (int)(microtime(true) * 1000),
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
            try {
                $r = $this->ex->orderResult($sym, $p['link']);
            } catch (\Throwable $e) {
                continue;                                     // попробуем на следующем такте
            }
            if (in_array($r['status'], ['Filled', 'PartiallyFilled'], true) && ($r['filled_qty'] > 0 || isset($positions[$sym]))) {
                unset($this->pendingManual[$sym]);
                $entry = $r['avg_price'] > 0 ? $r['avg_price'] : ($positions[$sym]['entry'] ?? 0.0);
                $qty = $r['filled_qty'] > 0 ? $r['filled_qty'] : $p['qty'];
                $this->directional[$sym] = ['strategy' => 'manual', 'side' => $p['side'], 'entry' => $entry, 'stop' => $p['stop'] ?? 0.0,
                    'qty' => $qty, 'regime' => 'manual', 'opened_ms' => $nowMs,
                    'risk_usd' => $p['stop'] ? abs($entry - $p['stop']) * $qty : 0.0];
                ($this->notify)($this->userId, "🖐 Лимитный ордер по $sym исполнен по " . self::fmt($entry));
                $this->reportOrder((int)$p['order_id'], 'filled', 'исполнен по ' . self::fmt($entry));
            } elseif (in_array($r['status'], ['Cancelled', 'Rejected', 'Deactivated'], true)) {
                unset($this->pendingManual[$sym]);
                ($this->notify)($this->userId, "🖐 Лимитный ордер по $sym отменён биржей ({$r['status']})");
                $this->reportOrder((int)$p['order_id'], 'cancelled', 'отменён биржей: ' . $r['status']);
            }
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
                $ev = $grid->stop($this->market->feeds[$sym]->price ?? $grid->plan['center']);
                if ($ev) {
                    $this->onGridEvent($sym, $grid, $ev, $this->brain->insights[$sym]['regime'] ?? 'range');
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
                if ($d['strategy'] !== 'manual' && $nowMs - $d['opened_ms'] > self::MAX_HOLD_SEC * 1000) {
                    $this->ex->closePosition($sym);
                }
                continue;
            }
            if ($nowMs - $d['opened_ms'] < 3000) {
                continue;
            }
            $closed = $this->ex->closedPnl($sym, $d['opened_ms'] - 1000);
            $pnl = array_sum(array_column($closed, 'pnl'));
            $exit = $closed ? end($closed)['exit'] : $d['entry'];
            $r = $d['risk_usd'] ? $pnl / $d['risk_usd'] : 0.0;
            unset($this->directional[$sym]);
            if ($r < -1.3) {
                // Стоп должен ограничивать убыток примерно 1R — заметный перебор стоит разобрать по этим числам.
                Log::warn(sprintf('user %d: %s %s — убыток %.2fR больше расчётного риска (вход %s, стоп %s, выход %s, объём %s)',
                    $this->userId, $sym, $d['strategy'], $r, self::fmt($d['entry']), self::fmt($d['stop']), self::fmt($exit), self::fmt($d['qty'])));
            }
            $this->recordTrade($sym, $d['strategy'], $d['side'], $d['qty'], $d['entry'], $exit, $pnl, $r, $d['regime']);
        }
    }

    private function recordTrade(string $sym, string $strategy, string $side, float $qty, float $entry, float $exit,
                                 float $pnl, float $r, string $regime): void
    {
        $t = compact('strategy', 'side', 'qty', 'entry', 'exit', 'pnl', 'r', 'regime') + ['symbol' => $sym, 'mode' => $this->ex->mode()];
        if ($this->ex instanceof PaperExchange) {
            $t['paper_balance'] = $this->ex->balance;
        }
        ($this->record)($this->userId, $t);
        if (in_array($strategy, ['trend', 'liquidation'], true)) {
            $this->guard->recordDirectionalTrade();
        }
        $this->guard->onTrade($pnl, microtime(true));
        // Уведомления о входах/выходах и паузе в Telegram отключены — клиент смотрит сделки и статус в приложении.
    }

    public function liveState(): array
    {
        return [
            'grids' => array_map(fn($s, $g) => ['symbol' => $s, 'mode' => $g->plan['mode'], 'step_pct' => round($g->plan['step_pct'], 3),
                'filled' => count($g->inventory), 'levels' => $g->plan['levels']], array_keys($this->grids), array_values($this->grids)),
            'directional' => array_map(fn($s, $d) => ['symbol' => $s, 'strategy' => $d['strategy'], 'side' => $d['side'],
                'entry' => $d['entry'], 'stop' => $d['stop']], array_keys($this->directional), array_values($this->directional)),
            'pending_manual' => array_map(fn($s, $p) => ['symbol' => $s, 'side' => $p['side'], 'qty' => $p['qty']],
                array_keys($this->pendingManual), array_values($this->pendingManual)),
            'manual_mode' => $this->manualMode,
        ];
    }

    /** Остановка клиентом: сетки закрываются, направленные сделки остаются со своими SL/TP на бирже. */
    public function shutdown(): void
    {
        foreach ($this->grids as $sym => $grid) {
            try {
                $ev = $grid->stop($this->market->feeds[$sym]->price ?? $grid->plan['center']);
                if ($ev) {
                    $this->onGridEvent($sym, $grid, $ev, $this->brain->insights[$sym]['regime'] ?? 'range');
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
