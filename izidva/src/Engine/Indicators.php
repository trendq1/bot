<?php
declare(strict_types=1);

namespace App\Engine;

/** Индикаторы и признаки рынка по 5-минутным свечам. */
final class Indicators
{
    public const REGIMES = ['trend_up', 'trend_down', 'range', 'high_volatility'];

    public static function ema(array $v, int $n): array
    {
        if (!$v) {
            return [];
        }
        $k = 2 / ($n + 1);
        $out = [$v[0]];
        for ($i = 1, $c = count($v); $i < $c; $i++) {
            $out[] = $v[$i] * $k + $out[$i - 1] * (1 - $k);
        }
        return $out;
    }

    public static function trueRanges(array $h, array $l, array $c): array
    {
        $tr = [$h[0] - $l[0]];
        for ($i = 1, $n = count($c); $i < $n; $i++) {
            $tr[] = max($h[$i] - $l[$i], abs($h[$i] - $c[$i - 1]), abs($l[$i] - $c[$i - 1]));
        }
        return $tr;
    }

    public static function wilder(array $v, int $n): array
    {
        $out = [];
        $acc = 0.0;
        foreach ($v as $i => $x) {
            if ($i < $n) {
                $acc += $x;
                $out[] = $acc / ($i + 1);
            } else {
                $out[] = ($out[$i - 1] * ($n - 1) + $x) / $n;
            }
        }
        return $out;
    }

    public static function rsi(array $c, int $n = 14): float
    {
        $g = $lo = [];
        for ($i = 1, $m = count($c); $i < $m; $i++) {
            $g[] = max($c[$i] - $c[$i - 1], 0);
            $lo[] = max($c[$i - 1] - $c[$i], 0);
        }
        $wg = self::wilder($g, $n);
        $wl = self::wilder($lo, $n);
        $ag = (float)end($wg);
        $al = (float)end($wl);
        return $al == 0.0 ? 100.0 : 100 - 100 / (1 + $ag / $al);
    }

    public static function adx(array $h, array $l, array $c, int $n = 14): float
    {
        $pdm = [0.0];
        $mdm = [0.0];
        for ($i = 1, $m = count($c); $i < $m; $i++) {
            $up = $h[$i] - $h[$i - 1];
            $down = $l[$i - 1] - $l[$i];
            $pdm[] = ($up > $down && $up > 0) ? $up : 0.0;
            $mdm[] = ($down > $up && $down > 0) ? $down : 0.0;
        }
        $tr = self::wilder(self::trueRanges($h, $l, $c), $n);
        $wp = self::wilder($pdm, $n);
        $wm = self::wilder($mdm, $n);
        $dx = [];
        foreach ($tr as $i => $t) {
            $p = $t ? 100 * $wp[$i] / $t : 0;
            $mi = $t ? 100 * $wm[$i] / $t : 0;
            $dx[] = ($p + $mi) ? 100 * abs($p - $mi) / ($p + $mi) : 0;
        }
        $w = self::wilder($dx, $n);
        return (float)end($w);
    }

    /**
     * @param array $k свечи от старых к новым: [ts, open, high, low, close, volume, turnover]
     *                 (последняя может быть незакрытой)
     */
    public static function features(array $k, ?float $price = null): ?array
    {
        $n = count($k);
        if ($n < 210) {
            return null;
        }
        $o = array_map(fn($x) => (float)$x[1], $k);
        $h = array_map(fn($x) => (float)$x[2], $k);
        $l = array_map(fn($x) => (float)$x[3], $k);
        $c = array_map(fn($x) => (float)$x[4], $k);
        $vol = array_map(fn($x) => (float)$x[5], $k);
        $turn = array_map(fn($x) => (float)$x[6], $k);
        $price ??= $c[$n - 1];

        $e20 = self::ema($c, 20);
        $e50 = self::ema($c, 50);
        $e200 = self::ema($c, 200);
        $atrS = self::wilder(self::trueRanges($h, $l, $c), 14);
        $atr = $atrS[$n - 1];
        $atrPcts = [];
        for ($i = max(0, $n - 200); $i < $n; $i++) {
            $atrPcts[] = $atrS[$i] / $c[$i];
        }
        sort($atrPcts);
        $mid = intdiv(count($atrPcts), 2);
        $median = count($atrPcts) % 2 ? $atrPcts[$mid] : ($atrPcts[$mid - 1] + $atrPcts[$mid]) / 2;
        $w = array_slice($c, -20);
        $mean = array_sum($w) / 20;
        $std = sqrt(array_sum(array_map(fn($x) => ($x - $mean) ** 2, $w)) / 20);
        $v48 = array_sum(array_slice($vol, -48));
        $vwap = $v48 ? array_sum(array_slice($turn, -48)) / $v48 : $price;

        return [
            'price' => $price,
            'ema20' => $e20[$n - 1], 'ema50' => $e50[$n - 1], 'ema200' => $e200[$n - 1],
            'atr' => $atr, 'atr_pct' => $atr / $price * 100, 'atr_pct_median' => $median * 100,
            'adx' => self::adx($h, $l, $c), 'rsi' => self::rsi($c),
            'bb_width_pct' => 4 * $std / $mean * 100,
            'ret_1h' => ($price / $c[$n - 13] - 1) * 100,
            'ret_4h' => ($price / $c[$n - 49] - 1) * 100,
            'ret_24h' => $n >= 289 ? ($price / $c[$n - 289] - 1) * 100 : 0.0,
            'vwap_4h' => $vwap,
            'dist_vwap_atr' => $atr ? ($price - $vwap) / $atr : 0.0,
            // точка входа — по последней закрытой свече
            'last_closed_ts' => (float)$k[$n - 2][0],
            'c1' => $c[$n - 2], 'o1' => $o[$n - 2], 'h2' => $h[$n - 3], 'l2' => $l[$n - 3],
            'low_5' => min(array_slice($l, -7, 6)), 'high_5' => max(array_slice($h, -7, 6)),
            'low_10' => min(array_slice($l, -12, 11)), 'high_10' => max(array_slice($h, -12, 11)),
            'ema20_1' => $e20[$n - 2],
        ];
    }

    public static function ruleRegime(array $f): string
    {
        if ($f['atr_pct'] > 2.2 * $f['atr_pct_median']) {
            return 'high_volatility';
        }
        if ($f['adx'] >= 23 && $f['ema50'] > $f['ema200'] && $f['price'] > $f['ema50']) {
            return 'trend_up';
        }
        if ($f['adx'] >= 23 && $f['ema50'] < $f['ema200'] && $f['price'] < $f['ema50']) {
            return 'trend_down';
        }
        return 'range';
    }
}
