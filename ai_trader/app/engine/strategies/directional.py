"""Направленные сделки с коротким тейком: тренд после отката и отскок после ликвидаций."""
from dataclasses import dataclass
from typing import Dict, Optional

from ...exchange.market import SymbolFeed


@dataclass
class Setup:
    strategy: str
    side: str
    entry: float
    stop: float
    take: float

    @property
    def risk(self) -> float:
        return abs(self.entry - self.stop)


def _bounded_stop(entry: float, stop: float, atr: float, d: int, lo_atr: float, hi_atr: float) -> Optional[float]:
    dist = d * (entry - stop)
    if dist > hi_atr * atr:
        return None                              # стоп слишком далеко — пропуск
    dist = max(dist, lo_atr * atr)
    return entry - d * dist


def trend_setup(f: Dict[str, float], regime: str, rr: float) -> Optional[Setup]:
    """Вход по тренду после отката к EMA20 и подтверждающей свечи."""
    atr, price = f["atr"], f["price"]
    if regime == "trend_up":
        pulled = f["low_5"] <= f["ema20_1"] + 0.2 * atr
        confirm = f["c1"] > f["o1"] and f["c1"] > f["ema20_1"] and f["c1"] > f["h2"]
        if pulled and confirm and 45 <= f["rsi"] <= 72 and price - f["ema20"] < 1.5 * atr:
            stop = _bounded_stop(price, f["low_10"] - 0.3 * atr, atr, 1, 0.5, 3.0)
            if stop is not None:
                return Setup("trend", "Buy", price, stop, price + rr * (price - stop))
    if regime == "trend_down":
        pulled = f["high_5"] >= f["ema20_1"] - 0.2 * atr
        confirm = f["c1"] < f["o1"] and f["c1"] < f["ema20_1"] and f["c1"] < f["l2"]
        if pulled and confirm and 28 <= f["rsi"] <= 55 and f["ema20"] - price < 1.5 * atr:
            stop = _bounded_stop(price, f["high_10"] + 0.3 * atr, atr, -1, 0.5, 3.0)
            if stop is not None:
                return Setup("trend", "Sell", price, stop, price - rr * (stop - price))
    return None


LIQ_MIN_USD = {"BTCUSDT": 1_000_000, "ETHUSDT": 500_000, "SOLUSDT": 200_000}
LIQ_DEFAULT_USD = 100_000


def liquidation_setup(feed: SymbolFeed, f: Dict[str, float], threshold_mult: float = 1.0,
                      now: Optional[float] = None) -> Optional[Setup]:
    """Каскад ликвидаций затих, цена далеко от VWAP и начала отскакивать — вход в обратную сторону."""
    atr, price, vwap = f["atr"], feed.price or f["price"], f["vwap_4h"]
    min_usd = LIQ_MIN_USD.get(feed.symbol, LIQ_DEFAULT_USD) * threshold_mult
    for liq_side, d in (("long", 1), ("short", -1)):
        if feed.liq_sum(liq_side, 60, now) < min_usd or feed.last_liq_ago(liq_side, now) < 5:
            continue
        extreme = feed.price_extreme(120, low=d == 1, now=now)
        if extreme is None or d * (vwap - extreme) < 2 * atr or d * (price - extreme) < 0.15 * atr:
            continue
        stop = _bounded_stop(price, extreme - d * 0.5 * atr, atr, d, 0.4, 3.0)
        if stop is None:
            continue
        risk = d * (price - stop)
        reward = min(d * (vwap - price), 2.0 * risk)
        if reward < 1.0 * risk:
            continue
        return Setup("liquidation", "Buy" if d == 1 else "Sell", price, stop, price + d * reward)
    return None
