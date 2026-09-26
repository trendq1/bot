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

    public function __construct(private array $prof) {}

    public function updateEquity(float $equity): void
    {
        $today = gmdate('Y-m-d');
        if ($today !== $this->day) {
            $this->day = $today;
            $this->dayStartEquity = $equity;
        }
    }

    public function dayPnlPct(float $equity): float
    {
        return $this->dayStartEquity ? ($equity - $this->dayStartEquity) / $this->dayStartEquity * 100 : 0.0;
    }

    /** @return array{0:bool,1:string} */
    public function allowed(float $equity, float $now): array
    {
        if ($now < $this->pausedUntil) {
            return [false, 'пауза после серии убытков'];
        }
        if ($this->dayPnlPct($equity) <= -$this->prof['daily_loss_pct']) {
            return [false, "дневной лимит убытка {$this->prof['daily_loss_pct']}%"];
        }
        return [true, ''];
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
