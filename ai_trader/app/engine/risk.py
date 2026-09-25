"""Риск-профили и защита депозита."""
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Optional, Tuple


@dataclass(frozen=True)
class RiskProfile:
    code: str
    title: str
    risk_pct: float          # риск на направленную сделку, % депозита
    rr: float                # тейк в R — маленький, чтобы сделки закрывались чаще
    leverage: int
    grid_alloc: float        # доля депозита под все сетки
    grid_levels: int
    grid_max_loss_pct: float # максимальный убыток одной сетки при стопе, % депозита
    max_grids: int
    max_directional: int
    daily_loss_pct: float
    max_consecutive_losses: int


PROFILES = {
    "conservative": RiskProfile("conservative", "Консервативный", 0.3, 1.2, 3, 0.30, 6, 1.5, 2, 1, 2.0, 3),
    "balanced":     RiskProfile("balanced", "Сбалансированный", 0.5, 1.2, 5, 0.45, 8, 2.5, 3, 2, 3.0, 4),
    "aggressive":   RiskProfile("aggressive", "Агрессивный", 1.0, 1.0, 8, 0.60, 10, 4.0, 4, 3, 5.0, 5),
}


def profile(code: str) -> RiskProfile:
    return PROFILES.get(code, PROFILES["balanced"])


class RiskGuard:
    """Дневной лимит убытка и пауза после серии убыточных сделок."""

    def __init__(self, prof: RiskProfile):
        self.prof = prof
        self.day: Optional[str] = None
        self.day_start_equity = 0.0
        self.consec_losses = 0
        self.paused_until = 0.0

    def update_equity(self, equity: float) -> None:
        today = datetime.now(timezone.utc).strftime("%Y-%m-%d")
        if today != self.day:
            self.day, self.day_start_equity = today, equity

    def day_pnl_pct(self, equity: float) -> float:
        if not self.day_start_equity:
            return 0.0
        return (equity - self.day_start_equity) / self.day_start_equity * 100

    def allowed(self, equity: float, now: float) -> Tuple[bool, str]:
        if now < self.paused_until:
            return False, "пауза после серии убытков"
        if self.day_pnl_pct(equity) <= -self.prof.daily_loss_pct:
            return False, f"дневной лимит убытка {self.prof.daily_loss_pct}%"
        return True, ""

    def on_trade(self, pnl: float, now: float, pause_sec: float = 3 * 3600) -> bool:
        """Возвращает True, если включилась пауза."""
        self.consec_losses = self.consec_losses + 1 if pnl < 0 else 0
        if self.consec_losses >= self.prof.max_consecutive_losses:
            self.consec_losses = 0
            self.paused_until = now + pause_sec
            return True
        return False
