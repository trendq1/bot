<?php
declare(strict_types=1);

namespace App\Engine;

/** Правила торговли инструментом на Bybit: шаг цены и объёма, минимальный объём. */
final class Instrument
{
    public function __construct(
        public string $symbol,
        public string $tickSize,
        public string $qtyStep,
        public float $minQty,
        public float $maxMktQty,
        public float $minNotional,
        public float $maxLeverage,
    ) {}

    public static function fromBybit(array $i): self
    {
        $lot = $i['lotSizeFilter'];
        return new self($i['symbol'], $i['priceFilter']['tickSize'], $lot['qtyStep'], (float)$lot['minOrderQty'],
            (float)($lot['maxMktOrderQty'] ?? $lot['maxOrderQty']), (float)($lot['minNotionalValue'] ?? 0),
            (float)$i['leverageFilter']['maxLeverage']);
    }

    private static function decimals(string $step): int
    {
        $step = rtrim(rtrim($step, '0'), '.');
        $pos = strpos($step, '.');
        return $pos === false ? 0 : strlen($step) - $pos - 1;
    }

    private static function snap(float $value, string $step, bool $up): string
    {
        $s = (float)$step;
        $n = $value / $s;
        $n = $up ? ceil($n - 1e-9) : floor($n + 1e-9);
        return number_format($n * $s, self::decimals($step), '.', '');
    }

    /** Объём округляем вниз до шага. */
    public function roundQty(float $qty): string
    {
        return self::snap($qty, $this->qtyStep, false);
    }

    public function roundPrice(float $price, bool $up = false): string
    {
        return self::snap($price, $this->tickSize, $up);
    }

    public function qtyOk(string $qty, float $price): bool
    {
        return (float)$qty >= $this->minQty - 1e-12 && (float)$qty * $price >= $this->minNotional;
    }
}
