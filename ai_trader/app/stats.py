"""Статистика для мини-аппа: календарь доходности, дашборд, сделки дня."""
from collections import defaultdict
from datetime import date, datetime, timedelta
from typing import Dict, List

from sqlalchemy import select

from .db import EquitySnapshot, Session, Trade, utcnow


def _local(dt: datetime, tz_min: int) -> datetime:
    return dt + timedelta(minutes=tz_min)


def trade_dict(t: Trade, tz_min: int) -> dict:
    return {"id": t.id, "symbol": t.symbol, "strategy": t.strategy, "side": t.side, "qty": t.qty,
            "entry": t.entry, "exit": t.exit, "pnl": round(t.pnl, 4), "r": round(t.r, 2),
            "regime": t.regime, "mode": t.mode, "time": _local(t.closed_at, tz_min).strftime("%H:%M")}


async def calendar(user_id: int, year: int, month: int, tz_min: int) -> dict:
    start = datetime(year, month, 1)
    end = datetime(year + (month == 12), month % 12 + 1, 1)
    async with Session() as s:
        rows = (await s.execute(select(Trade).where(
            Trade.user_id == user_id,
            Trade.closed_at >= start - timedelta(minutes=tz_min),
            Trade.closed_at < end - timedelta(minutes=tz_min)))).scalars().all()
    days: Dict[str, dict] = defaultdict(lambda: {"pnl": 0.0, "trades": 0, "wins": 0})
    for t in rows:
        d = days[_local(t.closed_at, tz_min).strftime("%Y-%m-%d")]
        d["pnl"] += t.pnl
        d["trades"] += 1
        d["wins"] += t.pnl > 0
    out = {k: {**v, "pnl": round(v["pnl"], 2)} for k, v in days.items()}
    total = sum(v["pnl"] for v in out.values())
    trades = sum(v["trades"] for v in out.values())
    wins = sum(v["wins"] for v in out.values())
    green = sum(1 for v in out.values() if v["pnl"] > 0)
    return {"year": year, "month": month, "days": out,
            "total": {"pnl": round(total, 2), "trades": trades,
                      "winrate": round(wins / trades * 100) if trades else 0,
                      "green_days": green, "red_days": sum(1 for v in out.values() if v["pnl"] < 0),
                      "best_day": max(out.values(), key=lambda v: v["pnl"])["pnl"] if out else 0,
                      "worst_day": min(out.values(), key=lambda v: v["pnl"])["pnl"] if out else 0}}


async def day_trades(user_id: int, day: date, tz_min: int) -> List[dict]:
    start = datetime(day.year, day.month, day.day) - timedelta(minutes=tz_min)
    async with Session() as s:
        rows = (await s.execute(select(Trade).where(
            Trade.user_id == user_id, Trade.closed_at >= start, Trade.closed_at < start + timedelta(days=1))
            .order_by(Trade.closed_at.desc()))).scalars().all()
    return [trade_dict(t, tz_min) for t in rows]


async def recent_trades(user_id: int, tz_min: int, limit: int = 50) -> List[dict]:
    async with Session() as s:
        rows = (await s.execute(select(Trade).where(Trade.user_id == user_id)
                                .order_by(Trade.closed_at.desc()).limit(limit))).scalars().all()
    out = []
    for t in rows:
        d = trade_dict(t, tz_min)
        d["date"] = _local(t.closed_at, tz_min).strftime("%d.%m")
        out.append(d)
    return out


async def dashboard(user_id: int, tz_min: int) -> dict:
    now = utcnow()
    async with Session() as s:
        trades = (await s.execute(select(Trade).where(
            Trade.user_id == user_id, Trade.closed_at >= now - timedelta(days=30)))).scalars().all()
        snaps = (await s.execute(select(EquitySnapshot).where(
            EquitySnapshot.user_id == user_id, EquitySnapshot.ts >= now - timedelta(days=30))
            .order_by(EquitySnapshot.ts))).scalars().all()
    today = _local(now, tz_min).date()

    def period(days: int) -> dict:
        ts = [t for t in trades if (today - _local(t.closed_at, tz_min).date()).days < days]
        wins = sum(1 for t in ts if t.pnl > 0)
        return {"pnl": round(sum(t.pnl for t in ts), 2), "trades": len(ts),
                "winrate": round(wins / len(ts) * 100) if ts else 0}

    by_strategy: Dict[str, dict] = defaultdict(lambda: {"pnl": 0.0, "trades": 0, "wins": 0})
    for t in trades:
        b = by_strategy[t.strategy]
        b["pnl"] += t.pnl
        b["trades"] += 1
        b["wins"] += t.pnl > 0
    step = max(1, len(snaps) // 150)
    curve = [{"t": _local(x.ts, tz_min).strftime("%d.%m %H:%M"), "v": round(x.equity, 2)} for x in snaps[::step]]
    return {"today": period(1), "week": period(7), "month": period(30),
            "by_strategy": {k: {**v, "pnl": round(v["pnl"], 2)} for k, v in by_strategy.items()},
            "equity_curve": curve}
