<?php
declare(strict_types=1);

namespace App\Engine;

/**
 * Бумажная биржа: виртуальный счёт на реальных ценах, с комиссиями Bybit.
 * Намеренно не оптимистичная: лимитный ордер исполняется, только если цена прошла СКВОЗЬ него (касание уровня —
 * не гарантия исполнения, в очереди перед нами другие ордера); стоп исполняется по худшей из цены стопа и текущей
 * цены (гэп мимо стопа), а рыночные исполнения — с проскальзыванием SLIPPAGE.
 */
final class PaperExchange implements ExchangeInterface
{
    public array $prices = [];
    /** symbol => linkId => [side, qty, price, reduce] */
    public array $orders = [];
    public array $results = [];
    /** symbol => [size (+лонг/-шорт), entry, stop, take] */
    public array $pos = [];
    public array $closed = [];
    /** Проскальзывание рыночных и стоп-исполнений (доля цены). */
    public const SLIPPAGE = 0.0002;
    /** Время текущего такта (в бэктесте — время свечи); null — реальное время. */
    public ?float $now = null;

    public function __construct(public float $balance) {}

    private function slip(string $side, float $price): float
    {
        return $side === 'Buy' ? $price * (1 + self::SLIPPAGE) : $price * (1 - self::SLIPPAGE);
    }

    public function mode(): string { return 'paper'; }

    public function updatePrices(array $prices): void
    {
        foreach ($prices as $symbol => $price) {
            $this->prices[$symbol] = $price;
            foreach ($this->orders[$symbol] ?? [] as $id => $o) {
                if ($o['side'] === 'Buy' ? $price < $o['price'] : $price > $o['price']) {
                    $this->fill($symbol, $id);
                }
            }
            $p = $this->pos[$symbol] ?? null;
            if ($p && $p['size'] != 0) {
                $long = $p['size'] > 0;
                if ($p['stop'] !== null && ($long ? $price <= $p['stop'] : $price >= $p['stop'])) {
                    $fill = $long ? min($price, $p['stop']) : max($price, $p['stop']);
                    $this->trade($symbol, $long ? 'Sell' : 'Buy', abs($p['size']), $this->slip($long ? 'Sell' : 'Buy', $fill), self::TAKER_FEE);
                } elseif ($p['take'] !== null && ($long ? $price >= $p['take'] : $price <= $p['take'])) {
                    $this->trade($symbol, $long ? 'Sell' : 'Buy', abs($p['size']), $p['take'], self::TAKER_FEE);
                }
            }
        }
    }

    private function fill(string $symbol, string $id): void
    {
        $o = $this->orders[$symbol][$id];
        unset($this->orders[$symbol][$id]);
        $size = $this->pos[$symbol]['size'] ?? 0.0;
        $reduces = ($size > 0 && $o['side'] === 'Sell') || ($size < 0 && $o['side'] === 'Buy');
        if ($o['reduce'] && !$reduces) {
            $this->results[$id] = ['status' => 'Cancelled', 'avg_price' => 0.0, 'filled_qty' => 0.0];
            return;
        }
        $qty = $o['reduce'] ? min($o['qty'], abs($size)) : $o['qty'];
        $this->trade($symbol, $o['side'], $qty, $o['price'], self::MAKER_FEE);
        if (!$o['reduce']) {
            if (($o['stop'] ?? null) !== null) {
                $this->pos[$symbol]['stop'] = $o['stop'];
            }
            if (($o['take'] ?? null) !== null) {
                $this->pos[$symbol]['take'] = $o['take'];
            }
        }
        $this->results[$id] = ['status' => 'Filled', 'avg_price' => $o['price'], 'filled_qty' => $qty];
    }

    private function trade(string $symbol, string $side, float $qty, float $price, float $fee): void
    {
        if ($qty <= 0) {
            return;                                          // нулевой объём (например, reduceOnly по уже закрытой позиции) — не сделка
        }
        $p = $this->pos[$symbol] ?? ['size' => 0.0, 'entry' => 0.0, 'stop' => null, 'take' => null];
        $signed = $side === 'Buy' ? $qty : -$qty;
        $this->balance -= $qty * $price * $fee;
        if ($p['size'] == 0 || ($p['size'] > 0) === ($signed > 0)) {
            $new = $p['size'] + $signed;
            $p['entry'] = ($p['entry'] * abs($p['size']) + $price * $qty) / abs($new);
            $p['size'] = $new;
            $this->pos[$symbol] = $p;
            return;
        }
        $closing = min($qty, abs($p['size']));
        $pnl = $closing * ($price - $p['entry']) * ($p['size'] > 0 ? 1 : -1);
        $this->balance += $pnl;
        $this->closed[$symbol][] = ['pnl' => $pnl - $closing * ($price + $p['entry']) * $fee, 'exit' => $price,
            'ts' => (int)(($this->now ?? microtime(true)) * 1000)];
        $rest = $qty - $closing;
        $p['size'] = $rest == 0 ? $p['size'] + $signed : 0.0;
        if (abs($p['size']) < 1e-12) {
            $p = ['size' => 0.0, 'entry' => 0.0, 'stop' => null, 'take' => null];
        }
        if ($rest > 0) {
            $p['size'] = $side === 'Buy' ? $rest : -$rest;
            $p['entry'] = $price;
        }
        $this->pos[$symbol] = $p;
    }

    public function equity(): float
    {
        $u = 0.0;
        foreach ($this->pos as $s => $p) {
            if ($p['size'] != 0) {
                $u += $p['size'] * (($this->prices[$s] ?? $p['entry']) - $p['entry']);
            }
        }
        return $this->balance + $u;
    }

    public function positions(): array
    {
        $out = [];
        foreach ($this->pos as $s => $p) {
            if ($p['size'] != 0) {
                $out[$s] = ['side' => $p['size'] > 0 ? 'Buy' : 'Sell', 'qty' => abs($p['size']), 'entry' => $p['entry']];
            }
        }
        return $out;
    }

    public function setLeverage(string $symbol, int $leverage): void {}

    public function placeLimit(string $symbol, string $side, string $qty, string $price, string $linkId, bool $reduceOnly = false,
                               ?string $stop = null, ?string $take = null): void
    {
        $this->orders[$symbol][$linkId] = ['side' => $side, 'qty' => (float)$qty, 'price' => (float)$price, 'reduce' => $reduceOnly,
            'stop' => $stop !== null ? (float)$stop : null, 'take' => $take !== null ? (float)$take : null];
        $this->results[$linkId] = ['status' => 'New', 'avg_price' => 0.0, 'filled_qty' => 0.0];
    }

    public function placeMarket(string $symbol, string $side, string $qty, ?string $stop = null, ?string $take = null, bool $reduceOnly = false): void
    {
        $q = (float)$qty;
        if ($reduceOnly) {
            $q = min($q, abs($this->pos[$symbol]['size'] ?? 0));
        }
        $this->trade($symbol, $side, $q, $this->slip($side, $this->prices[$symbol]), self::TAKER_FEE);
        if ($stop !== null) {
            $this->pos[$symbol]['stop'] = (float)$stop;
        }
        if ($take !== null) {
            $this->pos[$symbol]['take'] = (float)$take;
        }
    }

    public function setStopLoss(string $symbol, string $stop): void
    {
        if (isset($this->pos[$symbol])) {
            $this->pos[$symbol]['stop'] = (float)$stop;
        }
    }

    public function cancel(string $symbol, string $linkId): void
    {
        if (isset($this->orders[$symbol][$linkId])) {
            unset($this->orders[$symbol][$linkId]);
            $this->results[$linkId] = ['status' => 'Cancelled', 'avg_price' => 0.0, 'filled_qty' => 0.0];
        }
    }

    public function cancelAll(string $symbol): void
    {
        foreach (array_keys($this->orders[$symbol] ?? []) as $id) {
            $this->cancel($symbol, $id);
        }
    }

    public function openOrderIds(string $symbol): array
    {
        return array_map('strval', array_keys($this->orders[$symbol] ?? []));
    }

    public function orderResult(string $symbol, string $linkId): array
    {
        return $this->results[$linkId] ?? ['status' => 'Unknown', 'avg_price' => 0.0, 'filled_qty' => 0.0];
    }

    public function closedPnl(string $symbol, int $sinceMs): array
    {
        return array_values(array_filter($this->closed[$symbol] ?? [], fn($c) => $c['ts'] >= $sinceMs));
    }

    public function closePosition(string $symbol): ?array
    {
        $p = $this->pos[$symbol] ?? null;
        if (!$p || $p['size'] == 0) {
            return null;
        }
        $side = $p['size'] > 0 ? 'Sell' : 'Buy';
        $price = $this->slip($side, $this->prices[$symbol]);
        $qty = abs($p['size']);
        $this->trade($symbol, $side, $qty, $price, self::TAKER_FEE);
        return [$price, $qty];
    }
}
