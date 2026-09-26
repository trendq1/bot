<?php
declare(strict_types=1);

namespace App\Engine;

/**
 * Адаптивная сетка лимитных ордеров.
 * long: покупки на уровнях ниже центра; после покупки — продажа на шаг выше (reduce-only).
 * Исполнилась продажа -> цикл закрыт с прибылью ≈ шаг − комиссии, покупка на уровне выставляется снова.
 * short — зеркально. Цена ушла за крайний уровень на 1.5 шага -> позиция закрывается по рынку (стоп сетки).
 */
final class Grid
{
    public const MIN_STEP_PCT = 0.2;
    public const MAX_STEP_PCT = 2.0;
    public const STOP_BUFFER = 1.5;

    public string $tag;
    private int $seq = 0;
    /** linkId => [level, kind(open|close), side, price, open_price] */
    public array $orders = [];
    /** уровень => цена входа */
    public array $inventory = [];
    public bool $active = false;
    public bool $draining = false;

    /** @param array{mode:string,center:float,step_pct:float,levels:int,qty:string,max_loss:float} $plan */
    public function __construct(private ExchangeInterface $ex, public string $symbol, private Instrument $inst, public array $plan)
    {
        $this->tag = 'g' . bin2hex(random_bytes(3));
    }

    public static function worstLossPerQty(float $price, float $stepPct, int $levels): float
    {
        $step = $price * $stepPct / 100;
        $sum = 0.0;
        for ($i = 1; $i <= $levels; $i++) {
            $sum += $step * ($levels + self::STOP_BUFFER - $i);
        }
        return $sum;
    }

    /** План сетки так, чтобы убыток при стопе не превысил $maxLoss. null — если не хватает на минимальный лот. */
    public static function plan(float $price, float $atr, Instrument $inst, string $mode, float $stepAtr, int $levels,
                                float $capital, int $leverage, float $maxLoss): ?array
    {
        $stepPct = max(self::MIN_STEP_PCT, min(self::MAX_STEP_PCT, $stepAtr * $atr / $price * 100));
        $qty = $capital * $leverage / $levels / $price;
        $perQty = self::worstLossPerQty($price, $stepPct, $levels);
        if ($perQty > 0) {
            $qty = min($qty, $maxLoss / $perQty);
        }
        $q = $inst->roundQty($qty);
        if (!$inst->qtyOk($q, $price * (1 - $stepPct / 100 * $levels))) {
            return null;
        }
        return ['mode' => $mode, 'center' => $price, 'step_pct' => $stepPct, 'levels' => $levels, 'qty' => $q, 'max_loss' => $maxLoss];
    }

    private function dir(): int { return $this->plan['mode'] === 'long' ? 1 : -1; }
    public function openSide(): string { return $this->dir() === 1 ? 'Buy' : 'Sell'; }
    private function closeSide(): string { return $this->dir() === 1 ? 'Sell' : 'Buy'; }

    public function levelPrice(float $i): float
    {
        return $this->plan['center'] * (1 - $this->dir() * $i * $this->plan['step_pct'] / 100);
    }

    public function stopPrice(): float
    {
        return $this->levelPrice($this->plan['levels'] + self::STOP_BUFFER);
    }

    public function worstLoss(): float
    {
        return self::worstLossPerQty($this->plan['center'], $this->plan['step_pct'], $this->plan['levels']) * (float)$this->plan['qty'];
    }

    private function place(string $kind, int $level, float $price, ?float $openPrice = null): void
    {
        $side = $kind === 'open' ? $this->openSide() : $this->closeSide();
        $p = $this->inst->roundPrice($price, $side === 'Sell');     // покупка не дороже, продажа не дешевле расчёта
        $link = $this->tag . $kind[0] . $level . '-' . (++$this->seq);
        $this->ex->placeLimit($this->symbol, $side, $this->plan['qty'], $p, $link, $kind === 'close');
        $this->orders[$link] = ['level' => $level, 'kind' => $kind, 'side' => $side, 'price' => (float)$p, 'open_price' => $openPrice];
    }

    public function start(): void
    {
        $this->ex->cancelAll($this->symbol);
        for ($i = 1; $i <= $this->plan['levels']; $i++) {
            $this->place('open', $i, $this->levelPrice($i));
        }
        $this->active = true;
    }

    /** @return list<array{kind:string,side:string,qty:float,entry:float,exit:float,pnl:float}> */
    public function sync(float $price): array
    {
        if (!$this->active) {
            return [];
        }
        if ($this->inventory && $this->dir() * ($price - $this->stopPrice()) <= 0) {
            $ev = $this->stop($price);
            return $ev ? [$ev] : [];
        }
        $events = [];
        $live = array_flip($this->ex->openOrderIds($this->symbol));
        $step = $this->plan['step_pct'] / 100;
        foreach ($this->orders as $link => $o) {
            if (isset($live[$link])) {
                continue;
            }
            $res = $this->ex->orderResult($this->symbol, (string)$link);
            if (in_array($res['status'], ['New', 'Untriggered', 'Unknown', 'PartiallyFilled'], true)) {
                continue;                              // ещё не отразилось в истории
            }
            unset($this->orders[$link]);
            $filled = in_array($res['status'], ['Filled', 'PartiallyFilledCanceled'], true) && $res['filled_qty'] > 0;
            $fill = $res['avg_price'] ?: $o['price'];
            if ($o['kind'] === 'open') {
                if ($filled) {
                    $this->inventory[$o['level']] = $fill;
                    $this->place('close', $o['level'], $fill * (1 + $this->dir() * $step), $fill);
                } elseif (!$this->draining) {
                    $this->place('open', $o['level'], $this->levelPrice($o['level']));
                }
            } elseif ($filled) {
                $entry = $o['open_price'] ?? ($this->inventory[$o['level']] ?? $fill);
                unset($this->inventory[$o['level']]);
                $qty = (float)$this->plan['qty'];
                $pnl = $this->dir() * ($fill - $entry) * $qty - ($entry + $fill) * $qty * ExchangeInterface::MAKER_FEE;
                $events[] = ['kind' => 'cycle', 'side' => $this->openSide(), 'qty' => $qty, 'entry' => $entry, 'exit' => $fill, 'pnl' => $pnl];
                if (!$this->draining) {
                    $this->place('open', $o['level'], $this->levelPrice($o['level']));
                }
            } else {
                // закрывающий ордер отменён извне — выставляем снова, иначе позиция останется без выхода
                $this->place('close', $o['level'], $o['price'], $o['open_price']);
            }
        }
        if ($this->draining && !$this->inventory) {
            $this->ex->cancelAll($this->symbol);
            $this->active = false;
        }
        return $events;
    }

    /** Мягкая остановка: новых входов нет, открытые уровни закрываются по своим тейкам. */
    public function drain(): void
    {
        $this->draining = true;
        foreach ($this->orders as $link => $o) {
            if ($o['kind'] === 'open') {
                $this->ex->cancel($this->symbol, (string)$link);
                unset($this->orders[$link]);
            }
        }
        if (!$this->inventory) {
            $this->active = false;
        }
    }

    /** Жёсткая остановка: отмена ордеров и закрытие позиции по рынку. */
    public function stop(float $price): ?array
    {
        $this->ex->cancelAll($this->symbol);
        $this->orders = [];
        $this->active = false;
        if (!$this->inventory) {
            return null;
        }
        $closed = $this->ex->closePosition($this->symbol);
        $qty = (float)$this->plan['qty'] * count($this->inventory);
        $entry = array_sum($this->inventory) / count($this->inventory);
        $this->inventory = [];
        if (!$closed) {
            return null;
        }
        $exit = $this->ex->mode() === 'paper' ? $closed[0] : $price;
        $pnl = $this->dir() * ($exit - $entry) * $qty - $entry * $qty * ExchangeInterface::MAKER_FEE - $exit * $qty * ExchangeInterface::TAKER_FEE;
        return ['kind' => 'stop', 'side' => $this->openSide(), 'qty' => $qty, 'entry' => $entry, 'exit' => $exit, 'pnl' => $pnl];
    }

    /** Позиции нет, а цена ушла от центра против сетки — пора перестроить её вокруг новой цены. */
    public function idleFar(float $price): bool
    {
        return !$this->inventory && $this->dir() * ($price - $this->plan['center']) > 2 * $this->plan['center'] * $this->plan['step_pct'] / 100;
    }

    /** Риск одного уровня в USDT — для нормализации результата в R. */
    public function unitRisk(): float
    {
        return max($this->plan['max_loss'] / $this->plan['levels'], 1e-9);
    }
}
