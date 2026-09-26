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

    private static function setup(string $strategy, string $side, float $entry, float $stop, float $take): array
    {
        return ['strategy' => $strategy, 'side' => $side, 'entry' => $entry, 'stop' => $stop, 'take' => $take, 'risk' => abs($entry - $stop)];
    }

    /** Вход по тренду после отката к EMA20 и подтверждающей свечи. */
    public static function trend(array $f, string $regime, float $rr): ?array
    {
        $atr = $f['atr'];
        $price = $f['price'];
        if ($regime === 'trend_up') {
            $pulled = $f['low_5'] <= $f['ema20_1'] + 0.2 * $atr;
            $confirm = $f['c1'] > $f['o1'] && $f['c1'] > $f['ema20_1'] && $f['c1'] > $f['h2'];
            if ($pulled && $confirm && $f['rsi'] >= 45 && $f['rsi'] <= 72 && $price - $f['ema20'] < 1.5 * $atr) {
                $stop = self::boundedStop($price, $f['low_10'] - 0.3 * $atr, $atr, 1, 0.5, 3.0);
                if ($stop !== null) {
                    return self::setup('trend', 'Buy', $price, $stop, $price + $rr * ($price - $stop));
                }
            }
        }
        if ($regime === 'trend_down') {
            $pulled = $f['high_5'] >= $f['ema20_1'] - 0.2 * $atr;
            $confirm = $f['c1'] < $f['o1'] && $f['c1'] < $f['ema20_1'] && $f['c1'] < $f['l2'];
            if ($pulled && $confirm && $f['rsi'] >= 28 && $f['rsi'] <= 55 && $f['ema20'] - $price < 1.5 * $atr) {
                $stop = self::boundedStop($price, $f['high_10'] + 0.3 * $atr, $atr, -1, 0.5, 3.0);
                if ($stop !== null) {
                    return self::setup('trend', 'Sell', $price, $stop, $price - $rr * ($stop - $price));
                }
            }
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
