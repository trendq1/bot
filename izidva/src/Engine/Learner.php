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

    /** @var array<string,array{n:int,wins:int,ewma_r:float}> */
    public array $cache = [];

    public static function multiplier(float $ewmaR, int $n): float
    {
        $raw = max(0.4, min(1.6, 1 + $ewmaR));
        $trust = min(1.0, $n / self::MIN_TRADES);
        return 1 + ($raw - 1) * $trust;
    }

    public function load(): void
    {
        foreach (DB::all('SELECT `key`, n, wins, ewma_r FROM strategy_stats') as $r) {
            $this->cache[$r['key']] = ['n' => (int)$r['n'], 'wins' => (int)$r['wins'], 'ewma_r' => (float)$r['ewma_r']];
        }
    }

    public function mult(string $symbol, string $strategy, string $regime): float
    {
        $s = $this->cache["$symbol|$strategy|$regime"] ?? null;
        return $s ? self::multiplier($s['ewma_r'], $s['n']) : 1.0;
    }

    public function statsFor(string $symbol): array
    {
        $out = [];
        foreach ($this->cache as $k => $s) {
            [$sym, $strat, $regime] = explode('|', $k);
            if ($sym === $symbol && $s['n']) {
                $out["$strat/$regime"] = ['trades' => $s['n'], 'winrate' => round($s['wins'] / $s['n'], 2), 'avg_r' => round($s['ewma_r'], 2)];
            }
        }
        return $out;
    }

    public function record(string $symbol, string $strategy, string $regime, float $r): void
    {
        $r = max(-10.0, min(3.0, $r));
        $k = "$symbol|$strategy|$regime";
        $s = $this->cache[$k] ?? ['n' => 0, 'wins' => 0, 'ewma_r' => 0.0];
        $s['ewma_r'] = $s['n'] === 0 ? $r : (1 - self::ALPHA) * $s['ewma_r'] + self::ALPHA * $r;
        $s['n']++;
        $s['wins'] += $r > 0 ? 1 : 0;
        $this->cache[$k] = $s;
        DB::q('INSERT INTO strategy_stats (`key`, n, wins, ewma_r, updated_at) VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE n = VALUES(n), wins = VALUES(wins), ewma_r = VALUES(ewma_r), updated_at = VALUES(updated_at)',
            [$k, $s['n'], $s['wins'], $s['ewma_r'], DB::now()]);
    }
}
