<?php
declare(strict_types=1);

namespace App\Engine;

/**
 * Статистика закрытых сделок — одна и та же для админки и бэктеста. Главные метрики — не Win Rate,
 * а Expectancy, Profit Factor, просадка и то, какую долю всех убытков дают самые крупные из них (tail loss).
 * Сетка оценивается и по сессиям (session_id): вся жизнь одной сетки — одна торговая операция.
 */
final class TradeStats
{
    /**
     * @param list<array{pnl:float|string,r?:float|string,strategy?:string,symbol?:string,regime?:?string,session_id?:?string,kind?:?string,closed_at?:?string}> $trades
     *        в порядке закрытия
     */
    public static function compute(array $trades): array
    {
        $out = self::core(array_map(fn($t) => (float)$t['pnl'], $trades), array_map(fn($t) => (float)($t['r'] ?? 0), $trades));
        foreach (['strategy', 'symbol', 'regime'] as $dim) {
            $groups = [];
            foreach ($trades as $t) {
                $groups[(string)($t[$dim] ?? '—')][] = (float)$t['pnl'];
            }
            ksort($groups);
            $out['by_' . $dim] = array_map(fn($p) => self::brief($p), $groups);
        }
        $hours = [];
        foreach ($trades as $t) {
            if (!empty($t['closed_at'])) {
                $hours[(int)gmdate('G', strtotime($t['closed_at'] . ' UTC'))][] = (float)$t['pnl'];
            }
        }
        ksort($hours);
        $out['by_hour_utc'] = array_map(fn($p) => self::brief($p), $hours);
        $out['grid_sessions'] = self::gridSessions($trades);
        return $out;
    }

    /** @param float[] $pnl @param float[] $r */
    public static function core(array $pnl, array $r = []): array
    {
        $n = count($pnl);
        $wins = array_values(array_filter($pnl, fn($p) => $p > 0));
        $losses = array_values(array_filter($pnl, fn($p) => $p < 0));
        $grossWin = array_sum($wins);
        $grossLoss = -array_sum($losses);
        $net = array_sum($pnl);
        [$maxDd, $peak, $cum] = [0.0, 0.0, 0.0];
        foreach ($pnl as $p) {
            $cum += $p;
            $peak = max($peak, $cum);
            $maxDd = min($maxDd, $cum - $peak);
        }
        $lossAbs = array_map('abs', $losses);
        rsort($lossAbs);
        $share = function (float $frac) use ($lossAbs, $grossLoss): float {
            if (!$lossAbs || $grossLoss <= 0) {
                return 0.0;
            }
            $k = max(1, (int)ceil(count($lossAbs) * $frac));
            return round(array_sum(array_slice($lossAbs, 0, $k)) / $grossLoss * 100, 1);
        };
        $avgWin = $wins ? $grossWin / count($wins) : 0.0;
        $avgLoss = $losses ? $grossLoss / count($losses) : 0.0;
        return [
            'trades' => $n,
            'net_pnl' => round($net, 4),
            'win_rate_pct' => $n ? round(count($wins) / $n * 100, 1) : 0.0,
            'profit_factor' => $grossLoss > 0 ? round($grossWin / $grossLoss, 2) : null,
            'expectancy_usd' => $n ? round($net / $n, 4) : 0.0,
            'expectancy_r' => $r ? round(array_sum($r) / count($r), 3) : null,
            'avg_win' => round($avgWin, 4),
            'avg_loss' => round($avgLoss, 4),
            'win_loss_ratio' => $avgLoss > 0 ? round($avgWin / $avgLoss, 2) : null,
            'largest_win' => $wins ? round(max($wins), 4) : 0.0,
            'largest_loss' => $losses ? round(min($losses), 4) : 0.0,
            'max_drawdown' => round($maxDd, 4),
            'recovery_factor' => $maxDd < 0 ? round($net / -$maxDd, 2) : null,
            // сколько процентов всех убытков дают самые крупные 1% / 5% / 10% убыточных сделок
            'tail_loss_share_pct' => ['top1' => $share(0.01), 'top5' => $share(0.05), 'top10' => $share(0.10)],
            // Sortino по сделкам имеет смысл только на достаточной выборке
            'sortino' => $n >= 30 ? self::sortino($pnl) : null,
        ];
    }

    private static function brief(array $pnl): array
    {
        $c = self::core($pnl);
        return ['trades' => $c['trades'], 'net_pnl' => $c['net_pnl'], 'win_rate_pct' => $c['win_rate_pct'],
            'profit_factor' => $c['profit_factor'], 'expectancy_usd' => $c['expectancy_usd'], 'largest_loss' => $c['largest_loss']];
    }

    private static function sortino(array $pnl): ?float
    {
        $mean = array_sum($pnl) / count($pnl);
        $down = array_map(fn($p) => min(0.0, $p) ** 2, $pnl);
        $dd = sqrt(array_sum($down) / count($pnl));
        return $dd > 0 ? round($mean / $dd, 3) : null;
    }

    /**
     * Сессии сетки: все циклы + финальный выход одной сетки — одна операция. Показывает главное:
     * сколько средних прибыльных циклов стирает один средний стоп.
     */
    public static function gridSessions(array $trades): array
    {
        $sessions = [];
        $cycles = [];
        $stops = [];
        foreach ($trades as $t) {
            if (($t['strategy'] ?? '') !== 'grid') {
                continue;
            }
            $sid = (string)($t['session_id'] ?? '');
            if ($sid !== '') {
                $sessions[$sid] = ($sessions[$sid] ?? 0.0) + (float)$t['pnl'];
            }
            if (($t['kind'] ?? '') === 'cycle') {
                $cycles[] = (float)$t['pnl'];
            } elseif (in_array($t['kind'] ?? '', ['stop', 'reversal', 'regime_exit', 'emergency', 'manual'], true) && (float)$t['pnl'] < 0) {
                $stops[] = -(float)$t['pnl'];
            }
        }
        $core = self::core(array_values($sessions));
        $avgCycle = $cycles ? array_sum($cycles) / count($cycles) : 0.0;
        $avgStop = $stops ? array_sum($stops) / count($stops) : 0.0;
        return [
            'sessions' => $core['trades'], 'net_pnl' => $core['net_pnl'], 'profit_factor' => $core['profit_factor'],
            'win_rate_pct' => $core['win_rate_pct'], 'expectancy_usd' => $core['expectancy_usd'], 'worst_session' => $core['largest_loss'],
            'cycles' => count($cycles), 'stops' => count($stops), 'avg_cycle' => round($avgCycle, 4), 'avg_stop' => round($avgStop, 4),
            'cycles_per_stop' => $avgCycle > 0 && $avgStop > 0 ? round($avgStop / $avgCycle, 1) : null,
        ];
    }

    /**
     * Monte Carlo: случайная перестановка (бутстрэп с возвращением) последовательности сделок — насколько
     * результат и просадка зависят от везения в порядке сделок. Возвращает перцентили и вероятность минуса.
     */
    public static function monteCarlo(array $pnl, int $runs = 1000, int $seed = 42): ?array
    {
        $n = count($pnl);
        if ($n < 10) {
            return null;
        }
        mt_srand($seed);
        $nets = [];
        $dds = [];
        for ($k = 0; $k < $runs; $k++) {
            [$cum, $peak, $dd] = [0.0, 0.0, 0.0];
            for ($i = 0; $i < $n; $i++) {
                $cum += $pnl[mt_rand(0, $n - 1)];
                $peak = max($peak, $cum);
                $dd = min($dd, $cum - $peak);
            }
            $nets[] = $cum;
            $dds[] = $dd;
        }
        sort($nets);
        sort($dds);
        $pct = fn(array $a, float $q) => round($a[(int)floor(($runs - 1) * $q)], 4);
        return ['runs' => $runs, 'net_p5' => $pct($nets, 0.05), 'net_p50' => $pct($nets, 0.5), 'net_p95' => $pct($nets, 0.95),
            'max_dd_p50' => $pct($dds, 0.5), 'max_dd_p5' => $pct($dds, 0.05),
            'prob_loss_pct' => round(count(array_filter($nets, fn($x) => $x < 0)) / $runs * 100, 1)];
    }
}
