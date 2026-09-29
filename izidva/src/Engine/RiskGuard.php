<?php
declare(strict_types=1);

namespace App\Engine;

/** Дневной лимит убытка, пауза после серии убыточных сделок, Adaptive Risk. Состояние переживает рестарт (export/import). */
final class RiskGuard
{
    public ?string $day = null;
    public float $dayStartEquity = 0.0;
    public int $consecLosses = 0;
    public float $pausedUntil = 0.0;
    /** Сколько направленных сделок (тренд/ликвидации) открыто сегодня — сетку не считаем, у неё свой лимит убытка. */
    public int $dayDirectionalTrades = 0;
    /** Пик equity с начала работы воркера — для Adaptive Risk (просадка от пика). */
    public float $peakEquity = 0.0;

    public function __construct(private array $prof) {}

    public function updateEquity(float $equity, ?float $now = null): void
    {
        $today = gmdate('Y-m-d', (int)($now ?? time()));
        if ($today !== $this->day) {
            $this->day = $today;
            $this->dayStartEquity = $equity;
            $this->dayDirectionalTrades = 0;
        }
        $this->peakEquity = max($this->peakEquity, $equity);
    }

    public function directionalTradesLeft(): int
    {
        return max(0, (int)($this->prof['max_directional_trades_per_day'] ?? 999) - $this->dayDirectionalTrades);
    }

    public function recordDirectionalTrade(): void
    {
        $this->dayDirectionalTrades++;
    }

    public function dayPnlPct(float $equity): float
    {
        return $this->dayStartEquity ? ($equity - $this->dayStartEquity) / $this->dayStartEquity * 100 : 0.0;
    }

    /**
     * $floatingPnl — суммарный нереализованный результат по всем открытым сеткам и направленным сделкам
     * (Portfolio Risk Engine): в отличие от dayPnlPct(), это ещё не зафиксированный убыток, но именно он
     * первым сигналит о проблеме, когда несколько позиций одновременно уходят в минус на одном движении рынка.
     * @return array{0:bool,1:string}
     */
    public function allowed(float $equity, float $now, float $floatingPnl = 0.0): array
    {
        if ($now < $this->pausedUntil) {
            return [false, 'пауза после серии убытков'];
        }
        if ($this->dayPnlPct($equity) <= -$this->prof['daily_loss_pct']) {
            return [false, "дневной лимит убытка {$this->prof['daily_loss_pct']}%"];
        }
        $maxFloat = (float)($this->prof['max_floating_loss_pct'] ?? 0);
        if ($maxFloat > 0 && $equity > 0 && $floatingPnl < 0 && -$floatingPnl / $equity * 100 >= $maxFloat) {
            return [false, "плавающий убыток портфеля ≥ {$maxFloat}%"];
        }
        return [true, ''];
    }

    /**
     * Adaptive Risk: чем глубже просадка от пика equity, тем меньше множитель для новых входов (0.4..1.0).
     * Восстанавливается сам по мере роста equity обратно к пику — искусственного «сброса» не требуется.
     */
    public function adaptiveMult(float $equity): float
    {
        if ($this->peakEquity <= 0) {
            return 1.0;
        }
        $dd = max(0.0, ($this->peakEquity - $equity) / $this->peakEquity);
        return max(0.4, 1 - $dd * 2);
    }

    /** Состояние для сохранения в БД: без него рестарт демона обнулял дневной лимит, паузу и пик equity. */
    public function export(): array
    {
        return ['day' => $this->day, 'day_start_equity' => $this->dayStartEquity, 'consec_losses' => $this->consecLosses,
            'paused_until' => $this->pausedUntil, 'day_directional_trades' => $this->dayDirectionalTrades, 'peak_equity' => $this->peakEquity];
    }

    public function import(array $s): void
    {
        $this->day = isset($s['day']) ? (string)$s['day'] : null;
        $this->dayStartEquity = (float)($s['day_start_equity'] ?? 0);
        $this->consecLosses = (int)($s['consec_losses'] ?? 0);
        $this->pausedUntil = (float)($s['paused_until'] ?? 0);
        $this->dayDirectionalTrades = (int)($s['day_directional_trades'] ?? 0);
        $this->peakEquity = (float)($s['peak_equity'] ?? 0);
    }

    /** Возвращает true, если включилась пауза. */
    public function onTrade(float $pnl, float $now, float $pauseSec = 10800): bool
    {
        $this->consecLosses = $pnl < 0 ? $this->consecLosses + 1 : 0;
        if ($this->consecLosses >= $this->prof['max_consecutive_losses']) {
            $this->consecLosses = 0;
            $this->pausedUntil = $now + $pauseSec;
            return true;
        }
        return false;
    }
}
