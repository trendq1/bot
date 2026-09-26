<?php
declare(strict_types=1);

namespace App\Engine;

/** Поток данных по одной монете: цена, свечи, ликвидации. */
final class SymbolFeed
{
    public ?float $price = null;
    public ?float $funding = null;
    /** свечи 5м от старых к новым */
    public array $klines = [];
    public float $klinesTs = 0.0;
    /** [ts, price] */
    public array $prices = [];
    /** [ts, 'long'|'short', usd] */
    public array $liqs = [];

    public function __construct(public string $symbol) {}

    public function addPrice(float $price, ?float $ts = null): void
    {
        $this->price = $price;
        $this->prices[] = [$ts ?? microtime(true), $price];
        if (count($this->prices) > 600) {
            $this->prices = array_slice($this->prices, -400);
        }
    }

    public function addLiq(string $side, float $usd, ?float $ts = null): void
    {
        $this->liqs[] = [$ts ?? microtime(true), $side, $usd];
        if (count($this->liqs) > 3000) {
            $this->liqs = array_slice($this->liqs, -2000);
        }
    }

    public function liqSum(string $side, float $window, ?float $now = null): float
    {
        $now ??= microtime(true);
        $sum = 0.0;
        foreach ($this->liqs as [$t, $s, $u]) {
            if ($s === $side && $now - $t <= $window) {
                $sum += $u;
            }
        }
        return $sum;
    }

    public function lastLiqAgo(string $side, ?float $now = null): float
    {
        $now ??= microtime(true);
        for ($i = count($this->liqs) - 1; $i >= 0; $i--) {
            if ($this->liqs[$i][1] === $side) {
                return $now - $this->liqs[$i][0];
            }
        }
        return 1e9;
    }

    public function priceExtreme(float $window, bool $low, ?float $now = null): ?float
    {
        $now ??= microtime(true);
        $vals = [];
        foreach ($this->prices as [$t, $p]) {
            if ($now - $t <= $window) {
                $vals[] = $p;
            }
        }
        if (!$vals) {
            return $this->price;
        }
        return $low ? min($vals) : max($vals);
    }
}
