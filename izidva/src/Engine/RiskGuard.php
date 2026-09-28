<?php
declare(strict_types=1);

namespace App\Engine;

/** Дневной лимит убытка и пауза после серии убыточных сделок. */
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

    public function updateEquity(float $equity): void
    {
        $today = gmdate('Y-m-d');
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
