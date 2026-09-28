<?php
declare(strict_types=1);

namespace App\Engine;

use App\DB;

/**
 * Обучение на результатах: связка монета|стратегия|режим -> экспоненциальное среднее результата в R
 * по сделкам всех клиентов. Итоговый вес стратегии = вес ИИ × множитель обучения.
 */
final class Learner
{
    public const ALPHA = 0.1;
    public const MIN_TRADES = 8;

    /** @var array<string,array{n:int,wins:int,ewma_r:float,sum_pnl:float,gross_win_r:float,gross_loss_r:float,worst_r:float}> */
    public array $cache = [];

    /** false — не писать в БД (бэктест: прогон по истории не должен попадать в прод-статистику обучения). */
    public function __construct(public bool $persist = true) {}

    public static function multiplier(float $ewmaR, int $n): float
    {
        $raw = max(0.4, min(1.6, 1 + $ewmaR));
        $trust = min(1.0, $n / self::MIN_TRADES);
        return 1 + ($raw - 1) * $trust;
    }

    public function load(): void
    {
        foreach (DB::all('SELECT `key`, n, wins, ewma_r, sum_pnl, gross_win_r, gross_loss_r, worst_r FROM strategy_stats') as $r) {
            $this->cache[$r['key']] = ['n' => (int)$r['n'], 'wins' => (int)$r['wins'], 'ewma_r' => (float)$r['ewma_r'],
                'sum_pnl' => (float)$r['sum_pnl'], 'gross_win_r' => (float)$r['gross_win_r'], 'gross_loss_r' => (float)$r['gross_loss_r'],
                'worst_r' => (float)$r['worst_r']];
        }
    }

    public function mult(string $symbol, string $strategy, string $regime): float
    {
        $s = $this->cache["$symbol|$strategy|$regime"] ?? null;
        return $s ? self::multiplier($s['ewma_r'], $s['n']) : 1.0;
    }

    /**
     * Не только Win Rate: Profit Factor, Expectancy (в R и в $), средний выигрыш/проигрыш в R, худший R (tail loss).
     * profit_factor/avg_win_r/avg_loss_r — null, если делить не на что (нет проигрышей/выигрышей в выборке).
     */
    public function statsFor(string $symbol): array
    {
        $out = [];
        foreach ($this->cache as $k => $s) {
            [$sym, $strat, $regime] = explode('|', $k);
            if ($sym === $symbol && $s['n']) {
                $losses = $s['n'] - $s['wins'];
                $out["$strat/$regime"] = [
                    'trades' => $s['n'], 'winrate' => round($s['wins'] / $s['n'], 2), 'avg_r' => round($s['ewma_r'], 2),
                    'expectancy_r' => round(($s['gross_win_r'] - $s['gross_loss_r']) / $s['n'], 3),
                    'expectancy_usd' => round($s['sum_pnl'] / $s['n'], 4),
                    'profit_factor' => $s['gross_loss_r'] > 0 ? round($s['gross_win_r'] / $s['gross_loss_r'], 2) : null,
                    'avg_win_r' => $s['wins'] ? round($s['gross_win_r'] / $s['wins'], 3) : null,
                    'avg_loss_r' => $losses ? round($s['gross_loss_r'] / $losses, 3) : null,
                    'worst_r' => round($s['worst_r'], 2),
                ];
            }
        }
        return $out;
    }

    /**
     * $r не клэмпится тут для gross_win_r/gross_loss_r/worst_r (в отличие от ewma_r) — иначе теряется реальный
     * масштаб хвостового убытка, а именно его и нужно видеть в Profit Factor/Tail Loss.
     */
    public function record(string $symbol, string $strategy, string $regime, float $r, float $pnl = 0.0): void
    {
        $rClamped = max(-10.0, min(3.0, $r));
        $k = "$symbol|$strategy|$regime";
        $s = $this->cache[$k] ?? ['n' => 0, 'wins' => 0, 'ewma_r' => 0.0, 'sum_pnl' => 0.0, 'gross_win_r' => 0.0, 'gross_loss_r' => 0.0, 'worst_r' => 0.0];
        $s['ewma_r'] = $s['n'] === 0 ? $rClamped : (1 - self::ALPHA) * $s['ewma_r'] + self::ALPHA * $rClamped;
        $s['n']++;
        $s['wins'] += $r > 0 ? 1 : 0;
        $s['sum_pnl'] += $pnl;
        if ($r > 0) {
            $s['gross_win_r'] += $r;
        } else {
            $s['gross_loss_r'] += -$r;
        }
        $s['worst_r'] = min($s['worst_r'], $r);
        $this->cache[$k] = $s;
        if (!$this->persist) {
            return;
        }
        DB::q('INSERT INTO strategy_stats (`key`, n, wins, ewma_r, sum_pnl, gross_win_r, gross_loss_r, worst_r, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE n = VALUES(n), wins = VALUES(wins), ewma_r = VALUES(ewma_r), sum_pnl = VALUES(sum_pnl),
               gross_win_r = VALUES(gross_win_r), gross_loss_r = VALUES(gross_loss_r), worst_r = VALUES(worst_r), updated_at = VALUES(updated_at)',
            [$k, $s['n'], $s['wins'], $s['ewma_r'], $s['sum_pnl'], $s['gross_win_r'], $s['gross_loss_r'], $s['worst_r'], DB::now()]);
    }
}
