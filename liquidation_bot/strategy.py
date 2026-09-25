"""Логика стратегии: каскад ликвидаций + отклонение от VWAP -> вход на отскок.

Модуль не ходит в сеть и не знает о бирже, поэтому его легко тестировать.
"""
from collections import deque
from dataclasses import dataclass
from decimal import Decimal, ROUND_DOWN, ROUND_UP
from typing import Deque, List, Optional, Tuple


@dataclass
class Liquidation:
    ts: float    # время, секунды
    side: str    # "long" — ликвидировали лонг, "short" — ликвидировали шорт
    usd: float   # объём ликвидации в USDT
    price: float


@dataclass
class Signal:
    symbol: str
    side: str          # "Buy" или "Sell"
    entry: float
    stop: float
    take: float
    cascade_usd: float
    vwap: float
    atr: float

    @property
    def risk(self) -> float:
        return abs(self.entry - self.stop)

    @property
    def rr(self) -> float:
        return abs(self.take - self.entry) / self.risk


@dataclass
class StrategyParams:
    cascade_window_sec: float = 60      # окно суммирования ликвидаций
    settle_sec: float = 5               # сколько секунд без новых ликвидаций ждать (каскад затих)
    vwap_dev_atr: float = 2.0           # насколько цена должна уйти от VWAP, в ATR
    bounce_atr: float = 0.15            # подтверждение отскока от экстремума, в ATR
    sl_buffer_atr: float = 0.5          # запас стопа за экстремумом, в ATR
    min_sl_pct: float = 0.3             # стоп не ближе, % от цены
    max_sl_pct: float = 3.0             # стоп дальше — сделку пропускаем
    min_rr: float = 1.5                 # минимальное соотношение прибыль/риск до VWAP
    max_rr: float = 3.0                 # тейк не дальше этого R


class SymbolState:
    """Потоковые данные по одному символу."""

    def __init__(self, symbol: str):
        self.symbol = symbol
        self.liqs: Deque[Liquidation] = deque()
        self.prices: Deque[Tuple[float, float]] = deque()
        self.last_price: Optional[float] = None
        self.vwap: Optional[float] = None
        self.atr: Optional[float] = None
        self.indicators_ts: float = 0.0
        self.cooldown_until: float = 0.0

    def add_liquidation(self, liq: Liquidation) -> None:
        self.liqs.append(liq)

    def add_price(self, ts: float, price: float) -> None:
        self.last_price = price
        self.prices.append((ts, price))

    def prune(self, now: float, window: float) -> None:
        while self.liqs and now - self.liqs[0].ts > window:
            self.liqs.popleft()
        while self.prices and now - self.prices[0][0] > window:
            self.prices.popleft()

    def reset_cascade(self) -> None:
        self.liqs.clear()


def evaluate(state: SymbolState, now: float, p: StrategyParams, min_cascade_usd: float) -> Optional[Signal]:
    """Возвращает сигнал, если по символу есть сетап, иначе None."""
    if state.last_price is None or state.vwap is None or not state.atr:
        return None
    if now < state.cooldown_until:
        return None
    state.prune(now, p.cascade_window_sec)
    price, vwap, atr = state.last_price, state.vwap, state.atr
    window_prices = [px for _, px in state.prices] or [price]

    # Ликвидировали лонги -> цену продавили вниз -> ищем лонг на отскок. И наоборот.
    for liq_side, direction in (("long", 1), ("short", -1)):
        events = [e for e in state.liqs if e.side == liq_side]
        if not events:
            continue
        total = sum(e.usd for e in events)
        if total < min_cascade_usd:
            continue
        if now - events[-1].ts < p.settle_sec:
            continue  # каскад ещё идёт — не ловим падающий нож

        extreme = min(window_prices) if direction == 1 else max(window_prices)
        if direction * (vwap - extreme) < p.vwap_dev_atr * atr:
            continue  # цена недостаточно далеко от VWAP
        if direction * (price - extreme) < p.bounce_atr * atr:
            continue  # отскока ещё нет

        stop = extreme - direction * p.sl_buffer_atr * atr
        risk = direction * (price - stop)
        min_risk = price * p.min_sl_pct / 100
        max_risk = price * p.max_sl_pct / 100
        if risk > max_risk:
            continue
        if risk < min_risk:
            risk = min_risk
            stop = price - direction * risk

        reward = direction * (vwap - price)
        if reward < p.min_rr * risk:
            continue
        reward = min(reward, p.max_rr * risk)

        return Signal(
            symbol=state.symbol,
            side="Buy" if direction == 1 else "Sell",
            entry=price,
            stop=stop,
            take=price + direction * reward,
            cascade_usd=total,
            vwap=vwap,
            atr=atr,
        )
    return None


def vwap_atr_from_klines(klines: List[list], vwap_bars: int, atr_len: int = 14) -> Tuple[Optional[float], Optional[float]]:
    """Klines в формате Bybit v5 (новые первыми): [start, open, high, low, close, volume, turnover]."""
    bars = [(float(k[2]), float(k[3]), float(k[4]), float(k[5]), float(k[6])) for k in reversed(klines)]
    if len(bars) < atr_len + 1:
        return None, None
    recent = bars[-vwap_bars:]
    volume = sum(b[3] for b in recent)
    vwap = sum(b[4] for b in recent) / volume if volume > 0 else None

    trs = []
    for i in range(len(bars) - atr_len, len(bars)):
        high, low, _, _, _ = bars[i]
        prev_close = bars[i - 1][2]
        trs.append(max(high - low, abs(high - prev_close), abs(low - prev_close)))
    atr = sum(trs) / len(trs)
    return vwap, atr


# ───────────── Округление под правила биржи ─────────────

def floor_step(value: float, step: str) -> Decimal:
    d_step = Decimal(step)
    return (Decimal(str(value)) / d_step).to_integral_value(rounding=ROUND_DOWN) * d_step


def round_price(value: float, tick: str, up: bool) -> Decimal:
    d_tick = Decimal(tick)
    rounding = ROUND_UP if up else ROUND_DOWN
    return (Decimal(str(value)) / d_tick).to_integral_value(rounding=rounding) * d_tick


def fmt(d: Decimal) -> str:
    return format(d.normalize(), "f")


@dataclass
class Instrument:
    symbol: str
    tick_size: str
    qty_step: str
    min_qty: float
    max_mkt_qty: float
    min_notional: float
    max_leverage: float


def position_size(equity: float, risk_pct: float, entry: float, stop: float,
                  max_leverage: float, inst: Instrument) -> Tuple[Decimal, str]:
    """Объём так, чтобы убыток по стопу = risk_pct% депозита, с ограничением по плечу."""
    dist = abs(entry - stop)
    if dist <= 0 or equity <= 0:
        return Decimal(0), "некорректный стоп или депозит"
    qty = equity * risk_pct / 100 / dist
    qty = min(qty, equity * max_leverage * 0.95 / entry, inst.max_mkt_qty)
    q = floor_step(qty, inst.qty_step)
    if q < Decimal(str(inst.min_qty)):
        return Decimal(0), f"объём {q} меньше минимального {inst.min_qty}"
    if float(q) * entry < inst.min_notional:
        return Decimal(0), f"сумма сделки меньше минимальной {inst.min_notional} USDT"
    return q, ""
