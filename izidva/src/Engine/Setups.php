<?php
declare(strict_types=1);

namespace App\Engine;

/** Направленные сделки с коротким тейком: тренд после отката и отскок после ликвидаций. */
final class Setups
{
    public const LIQ_MIN_USD = ['BTCUSDT' => 1_000_000, 'ETHUSDT' => 500_000, 'SOLUSDT' => 200_000];
    public const LIQ_DEFAULT_USD = 100_000;

    private static function boundedStop(float $entry, float $stop, float $atr, int $d, float $loAtr, float $hiAtr): ?float
    {
        $dist = $d * ($entry - $stop);
        if ($dist > $hiAtr * $atr) {
            return null;                           // стоп слишком далеко — пропуск
        }
        return $entry - $d * max($dist, $loAtr * $atr);
    }

    private static function setup(string $strategy, string $side, float $entry, float $stop, ?float $take): array
    {
        return ['strategy' => $strategy, 'side' => $side, 'entry' => $entry, 'stop' => $stop, 'take' => $take, 'risk' => abs($entry - $stop)];
    }

    /** Какую долю риска (R) съедает комиссия тейкера за вход и выход — главный фильтр «есть ли смысл в сделке». */
    public static function feeR(float $entry, float $risk): float
    {
        return $risk > 0 ? 2 * ExchangeInterface::TAKER_FEE * $entry / $risk : INF;
    }

    /**
     * Вход по тренду после отката к EMA20 и подтверждающей свечи. $f — признаки таймфрейма сигнала (в боте — 1h).
     * $minStopAtr — стоп не ближе стольких ATR: на 5м стоп в 0.5 ATR был ~0.1% цены при комиссии 0.11% за круг.
     */
    public static function trend(array $f, string $regime, float $rr, float $minStopAtr = 0.5): ?array
    {
        $atr = $f['atr'];
        $price = $f['price'];
        if ($regime === 'trend_up') {
            $pulled = $f['low_5'] <= $f['ema20_1'] + 0.2 * $atr;
            $confirm = $f['c1'] > $f['o1'] && $f['c1'] > $f['ema20_1'] && $f['c1'] > $f['h2'];
            if ($pulled && $confirm && $f['rsi'] >= 45 && $f['rsi'] <= 72 && $price - $f['ema20'] < 1.5 * $atr) {
                $stop = self::boundedStop($price, $f['low_10'] - 0.3 * $atr, $atr, 1, $minStopAtr, 3.0);
                if ($stop !== null) {
                    return self::setup('trend', 'Buy', $price, $stop, $price + $rr * ($price - $stop));
                }
            }
        }
        if ($regime === 'trend_down') {
            $pulled = $f['high_5'] >= $f['ema20_1'] - 0.2 * $atr;
            $confirm = $f['c1'] < $f['o1'] && $f['c1'] < $f['ema20_1'] && $f['c1'] < $f['l2'];
            if ($pulled && $confirm && $f['rsi'] >= 28 && $f['rsi'] <= 55 && $f['ema20'] - $price < 1.5 * $atr) {
                $stop = self::boundedStop($price, $f['high_10'] + 0.3 * $atr, $atr, -1, $minStopAtr, 3.0);
                if ($stop !== null) {
                    return self::setup('trend', 'Sell', $price, $stop, $price - $rr * ($stop - $price));
                }
            }
        }
        return null;
    }

    /** Пробой канала: сколько закрытых 4h-свечей в канале, EMA-фильтр направления, стоп в ATR, не догонять дальше ATR. */
    public const BREAKOUT_LOOKBACK = 20;
    public const BREAKOUT_TREND_EMA = 50;
    public const BREAKOUT_STOP_ATR = 2.0;
    public const BREAKOUT_MAX_CHASE_ATR = 1.0;

    /**
     * Пробой канала Дончиана на 4h (классика трендследования, параметры «черепах» — не подобраны под историю бота):
     * закрытие последней 4h-свечи выше максимума 20 предыдущих и выше EMA50 — лонг (ниже минимума и ниже EMA50 — шорт).
     * Стоп — 2 ATR(4h), фиксированного тейка нет: прибыль ведёт трейлинг-стоп (Worker::applyChandelier), чтобы редкие
     * большие движения окупали частые небольшие стопы — «маленькие контролируемые убытки + большие прибыльные сделки».
     * @param array $k4h только закрытые 4h-свечи (Indicators::closedBars)
     */
    public static function breakout(array $k4h, float $price): ?array
    {
        $n = count($k4h);
        if ($n < self::BREAKOUT_TREND_EMA + 5) {
            return null;
        }
        $h = array_map(fn($x) => (float)$x[2], $k4h);
        $l = array_map(fn($x) => (float)$x[3], $k4h);
        $c = array_map(fn($x) => (float)$x[4], $k4h);
        $atrS = Indicators::wilder(Indicators::trueRanges($h, $l, $c), 14);
        $atr = (float)end($atrS);
        $ema = Indicators::ema($c, self::BREAKOUT_TREND_EMA);
        $e = (float)end($ema);
        $close = $c[$n - 1];
        $hi = max(array_slice($h, $n - 1 - self::BREAKOUT_LOOKBACK, self::BREAKOUT_LOOKBACK));
        $lo = min(array_slice($l, $n - 1 - self::BREAKOUT_LOOKBACK, self::BREAKOUT_LOOKBACK));
        if ($atr <= 0) {
            return null;
        }
        if ($close > $hi && $close > $e && $price > $hi && $price - $hi <= self::BREAKOUT_MAX_CHASE_ATR * $atr) {
            return self::setup('breakout', 'Buy', $price, $price - self::BREAKOUT_STOP_ATR * $atr, null) + ['atr' => $atr];
        }
        if ($close < $lo && $close < $e && $price < $lo && $lo - $price <= self::BREAKOUT_MAX_CHASE_ATR * $atr) {
            return self::setup('breakout', 'Sell', $price, $price + self::BREAKOUT_STOP_ATR * $atr, null) + ['atr' => $atr];
        }
        return null;
    }

    /** Каскад ликвидаций затих, цена далеко от VWAP и начала отскакивать — вход в обратную сторону. */
    public static function liquidation(SymbolFeed $feed, array $f, float $thresholdMult = 1.0, ?float $now = null): ?array
    {
        $atr = $f['atr'];
        $price = $feed->price ?? $f['price'];
        $vwap = $f['vwap_4h'];
        $minUsd = (self::LIQ_MIN_USD[$feed->symbol] ?? self::LIQ_DEFAULT_USD) * $thresholdMult;
        foreach ([['long', 1], ['short', -1]] as [$liqSide, $d]) {
            if ($feed->liqSum($liqSide, 60, $now) < $minUsd || $feed->lastLiqAgo($liqSide, $now) < 5) {
                continue;
            }
            $extreme = $feed->priceExtreme(120, $d === 1, $now);
            if ($extreme === null || $d * ($vwap - $extreme) < 2 * $atr || $d * ($price - $extreme) < 0.15 * $atr) {
                continue;
            }
            $stop = self::boundedStop($price, $extreme - $d * 0.5 * $atr, $atr, $d, 0.4, 3.0);
            if ($stop === null) {
                continue;
            }
            $risk = $d * ($price - $stop);
            $reward = min($d * ($vwap - $price), 2.0 * $risk);
            if ($reward < 1.0 * $risk) {
                continue;
            }
            return self::setup('liquidation', $d === 1 ? 'Buy' : 'Sell', $price, $stop, $price + $d * $reward);
        }
        return null;
    }
}
