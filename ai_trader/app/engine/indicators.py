"""Индикаторы и признаки рынка по 5-минутным свечам (без внешних библиотек)."""
from statistics import median
from typing import Dict, List, Optional


def ema(values: List[float], n: int) -> List[float]:
    if not values:
        return []
    k = 2 / (n + 1)
    out = [values[0]]
    for v in values[1:]:
        out.append(v * k + out[-1] * (1 - k))
    return out


def true_ranges(h: List[float], l: List[float], c: List[float]) -> List[float]:
    tr = [h[0] - l[0]]
    for i in range(1, len(c)):
        tr.append(max(h[i] - l[i], abs(h[i] - c[i - 1]), abs(l[i] - c[i - 1])))
    return tr


def wilder(values: List[float], n: int) -> List[float]:
    out, acc = [], None
    for i, v in enumerate(values):
        if i < n:
            acc = (acc or 0) + v
            out.append(acc / (i + 1))
        else:
            out.append((out[-1] * (n - 1) + v) / n)
    return out


def rsi(c: List[float], n: int = 14) -> float:
    gains = [max(c[i] - c[i - 1], 0) for i in range(1, len(c))]
    losses = [max(c[i - 1] - c[i], 0) for i in range(1, len(c))]
    g, lo = wilder(gains, n)[-1], wilder(losses, n)[-1]
    return 100.0 if lo == 0 else 100 - 100 / (1 + g / lo)


def adx(h: List[float], l: List[float], c: List[float], n: int = 14) -> float:
    plus_dm, minus_dm = [0.0], [0.0]
    for i in range(1, len(c)):
        up, down = h[i] - h[i - 1], l[i - 1] - l[i]
        plus_dm.append(up if up > down and up > 0 else 0.0)
        minus_dm.append(down if down > up and down > 0 else 0.0)
    tr = wilder(true_ranges(h, l, c), n)
    pdi = [100 * p / t if t else 0 for p, t in zip(wilder(plus_dm, n), tr)]
    mdi = [100 * m / t if t else 0 for m, t in zip(wilder(minus_dm, n), tr)]
    dx = [100 * abs(p - m) / (p + m) if p + m else 0 for p, m in zip(pdi, mdi)]
    return wilder(dx, n)[-1]


def features(klines: List[list], price: Optional[float] = None) -> Optional[Dict[str, float]]:
    """klines: старые -> новые, [ts, open, high, low, close, volume, turnover]. Последняя свеча может быть незакрытой."""
    if len(klines) < 210:
        return None
    o = [float(k[1]) for k in klines]
    h = [float(k[2]) for k in klines]
    l = [float(k[3]) for k in klines]
    c = [float(k[4]) for k in klines]
    vol = [float(k[5]) for k in klines]
    turn = [float(k[6]) for k in klines]
    price = price or c[-1]

    e20, e50, e200 = ema(c, 20), ema(c, 50), ema(c, 200)
    tr = true_ranges(h, l, c)
    atr_series = wilder(tr, 14)
    atr = atr_series[-1]
    atr_pcts = [a / cl for a, cl in zip(atr_series[-200:], c[-200:])]
    w = c[-20:]
    mean20 = sum(w) / 20
    std20 = (sum((x - mean20) ** 2 for x in w) / 20) ** 0.5
    v48 = sum(vol[-48:])
    vwap4h = sum(turn[-48:]) / v48 if v48 else price

    return {
        "price": price,
        "ema20": e20[-1], "ema50": e50[-1], "ema200": e200[-1],
        "atr": atr, "atr_pct": atr / price * 100,
        "atr_pct_median": median(atr_pcts) * 100,
        "adx": adx(h, l, c),
        "rsi": rsi(c),
        "bb_width_pct": 4 * std20 / mean20 * 100,
        "ret_1h": (price / c[-13] - 1) * 100,
        "ret_4h": (price / c[-49] - 1) * 100,
        "ret_24h": (price / c[-289] - 1) * 100 if len(c) >= 289 else 0.0,
        "vwap_4h": vwap4h,
        "dist_vwap_atr": (price - vwap4h) / atr if atr else 0.0,
        # для точки входа трендовой стратегии (по последней ЗАКРЫТОЙ свече)
        "last_closed_ts": float(klines[-2][0]),
        "c1": c[-2], "o1": o[-2], "h2": h[-3], "l2": l[-3],
        "low_5": min(l[-7:-1]), "high_5": max(h[-7:-1]),
        "low_10": min(l[-12:-1]), "high_10": max(h[-12:-1]),
        "ema20_1": e20[-2],
    }


REGIMES = ("trend_up", "trend_down", "range", "high_volatility")


def rule_regime(f: Dict[str, float]) -> str:
    """Запасной классификатор режима, если ИИ недоступен."""
    if f["atr_pct"] > 2.2 * f["atr_pct_median"]:
        return "high_volatility"
    if f["adx"] >= 23 and f["ema50"] > f["ema200"] and f["price"] > f["ema50"]:
        return "trend_up"
    if f["adx"] >= 23 and f["ema50"] < f["ema200"] and f["price"] < f["ema50"]:
        return "trend_down"
    return "range"
