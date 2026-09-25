"""Обучение на результатах: какие стратегии работают на какой монете в каком режиме рынка.

Статистика общая для всех клиентов (больше данных — быстрее обучение). Для каждой
связки symbol|strategy|regime хранится экспоненциальное среднее результата в R.
Итоговый вес стратегии = вес от ИИ × множитель обучения.
"""
from typing import Dict

from sqlalchemy import select

from ..db import Session, StrategyStat, utcnow

ALPHA = 0.1          # скорость забывания старых сделок
MIN_TRADES = 8       # до этого числа сделок множитель плавно тянется к 1


def key(symbol: str, strategy: str, regime: str) -> str:
    return f"{symbol}|{strategy}|{regime}"


def multiplier(ewma_r: float, n: int) -> float:
    """0.4..1.6: стратегии, которые теряют, получают меньше капитала и реже выбираются."""
    raw = max(0.4, min(1.6, 1 + ewma_r))
    trust = min(1.0, n / MIN_TRADES)
    return 1 + (raw - 1) * trust


class Learner:
    def __init__(self):
        self.cache: Dict[str, StrategyStat] = {}

    async def load(self) -> None:
        async with Session() as s:
            for row in (await s.execute(select(StrategyStat))).scalars():
                self.cache[row.key] = row

    def mult(self, symbol: str, strategy: str, regime: str) -> float:
        st = self.cache.get(key(symbol, strategy, regime))
        return multiplier(st.ewma_r, st.n) if st else 1.0

    def stats_for(self, symbol: str) -> Dict[str, dict]:
        out = {}
        for k, st in self.cache.items():
            sym, strat, regime = k.split("|")
            if sym == symbol and st.n:
                out[f"{strat}/{regime}"] = {"trades": st.n, "winrate": round(st.wins / st.n, 2),
                                            "avg_r": round(st.ewma_r, 2)}
        return out

    async def record(self, symbol: str, strategy: str, regime: str, r: float) -> None:
        r = max(-3.0, min(3.0, r))
        k = key(symbol, strategy, regime)
        async with Session() as s:
            st = await s.get(StrategyStat, k)
            if st is None:
                st = StrategyStat(key=k, n=0, wins=0, ewma_r=0.0)
                s.add(st)
            st.ewma_r = r if st.n == 0 else (1 - ALPHA) * st.ewma_r + ALPHA * r
            st.n += 1
            st.wins += 1 if r > 0 else 0
            st.updated_at = utcnow()
            await s.commit()
            self.cache[k] = st
