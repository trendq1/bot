<?php
declare(strict_types=1);

namespace App\Web;

use App\DB;

/** Статистика для мини-аппа: календарь доходности, дашборд, сделки дня. tz — смещение клиента в минутах. */
final class Stats
{
    private static function local(string $utc, int $tz): int
    {
        return strtotime($utc . ' UTC') + $tz * 60;
    }

    public static function tradeRow(array $t, int $tz): array
    {
        $ts = self::local($t['closed_at'], $tz);
        return ['id' => (int)$t['id'], 'symbol' => $t['symbol'], 'strategy' => $t['strategy'], 'side' => $t['side'],
            'qty' => (float)$t['qty'], 'entry' => (float)$t['entry'], 'exit' => (float)$t['exit'], 'pnl' => round((float)$t['pnl'], 4),
            'r' => round((float)$t['r'], 2), 'regime' => $t['regime'], 'mode' => $t['mode'],
            'time' => gmdate('H:i', $ts), 'date' => gmdate('d.m', $ts)];
    }

    public static function calendar(int $uid, int $y, int $m, int $tz): array
    {
        $start = gmmktime(0, 0, 0, $m, 1, $y) - $tz * 60;
        $end = gmmktime(0, 0, 0, $m + 1, 1, $y) - $tz * 60;
        $rows = DB::all('SELECT pnl, closed_at FROM trades WHERE user_id = ? AND closed_at >= ? AND closed_at < ?',
            [$uid, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end)]);
        $days = [];
        foreach ($rows as $t) {
            $k = gmdate('Y-m-d', self::local($t['closed_at'], $tz));
            $days[$k] ??= ['pnl' => 0.0, 'trades' => 0, 'wins' => 0];
            $days[$k]['pnl'] += (float)$t['pnl'];
            $days[$k]['trades']++;
            $days[$k]['wins'] += (float)$t['pnl'] > 0 ? 1 : 0;
        }
        foreach ($days as &$d) {
            $d['pnl'] = round($d['pnl'], 2);
        }
        unset($d);
        $pnls = array_column($days, 'pnl');
        $trades = array_sum(array_column($days, 'trades'));
        $wins = array_sum(array_column($days, 'wins'));
        return ['year' => $y, 'month' => $m, 'days' => (object)$days, 'total' => [
            'pnl' => round(array_sum($pnls), 2), 'trades' => $trades, 'winrate' => $trades ? (int)round($wins / $trades * 100) : 0,
            'green_days' => count(array_filter($pnls, fn($p) => $p > 0)), 'red_days' => count(array_filter($pnls, fn($p) => $p < 0)),
            'best_day' => $pnls ? max($pnls) : 0, 'worst_day' => $pnls ? min($pnls) : 0,
        ]];
    }

    public static function dayTrades(int $uid, string $date, int $tz): array
    {
        $start = strtotime($date . ' 00:00:00 UTC') - $tz * 60;
        $rows = DB::all('SELECT * FROM trades WHERE user_id = ? AND closed_at >= ? AND closed_at < ? ORDER BY closed_at DESC',
            [$uid, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $start + 86400)]);
        return array_map(fn($t) => self::tradeRow($t, $tz), $rows);
    }

    public static function recent(int $uid, int $tz, int $limit = 50): array
    {
        $rows = DB::all("SELECT * FROM trades WHERE user_id = ? ORDER BY closed_at DESC, id DESC LIMIT $limit", [$uid]);
        return array_map(fn($t) => self::tradeRow($t, $tz), $rows);
    }

    public static function dashboard(int $uid, int $tz): array
    {
        $since = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $trades = DB::all('SELECT strategy, pnl, closed_at FROM trades WHERE user_id = ? AND closed_at >= ?', [$uid, $since]);
        $snaps = DB::all('SELECT ts, equity FROM equity_snapshots WHERE user_id = ? AND ts >= ? ORDER BY ts', [$uid, $since]);
        $today = gmdate('Y-m-d', time() + $tz * 60);
        $period = function (int $days) use ($trades, $tz, $today) {
            $ts = array_filter($trades, fn($t) => (strtotime($today) - strtotime(gmdate('Y-m-d', self::local($t['closed_at'], $tz)))) / 86400 < $days);
            $wins = count(array_filter($ts, fn($t) => (float)$t['pnl'] > 0));
            return ['pnl' => round(array_sum(array_map(fn($t) => (float)$t['pnl'], $ts)), 2), 'trades' => count($ts),
                'winrate' => $ts ? (int)round($wins / count($ts) * 100) : 0];
        };
        $by = [];
        foreach ($trades as $t) {
            $b = &$by[$t['strategy']];
            $b ??= ['pnl' => 0.0, 'trades' => 0, 'wins' => 0];
            $b['pnl'] += (float)$t['pnl'];
            $b['trades']++;
            $b['wins'] += (float)$t['pnl'] > 0 ? 1 : 0;
            unset($b);
        }
        foreach ($by as &$b) {
            $b['pnl'] = round($b['pnl'], 2);
        }
        unset($b);
        $step = max(1, intdiv(count($snaps), 150));
        $curve = [];
        foreach ($snaps as $i => $s) {
            if ($i % $step === 0) {
                $curve[] = ['t' => gmdate('d.m H:i', self::local($s['ts'], $tz)), 'v' => round((float)$s['equity'], 2)];
            }
        }
        return ['today' => $period(1), 'week' => $period(7), 'month' => $period(30), 'by_strategy' => (object)$by, 'equity_curve' => $curve];
    }
}
