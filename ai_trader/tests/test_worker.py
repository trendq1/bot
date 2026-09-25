import asyncio

from sqlalchemy import select

from app.db import BotSettings, Session, StrategyStat, Trade, User, init_db
from app.engine.ai import Insight
from app.engine.learner import Learner
from app.engine.manager import EngineManager
from app.engine.risk import profile
from app.engine.worker import Brain, UserWorker
from app.exchange.base import Instrument
from app.exchange.market import SymbolFeed
from app.exchange.paper import PaperExchange

INST = Instrument("SOLUSDT", "0.01", "0.1", 0.1, 10000, 5, 50)


class FakeHub:
    def __init__(self):
        self.feeds = {"SOLUSDT": SymbolFeed("SOLUSDT")}
        self.instruments = {"SOLUSDT": INST}

    def prices(self):
        return {s: f.price for s, f in self.feeds.items() if f.price}


def features_at(price):
    return {"price": price, "atr": 1.0, "ema20": price, "ema20_1": price, "last_closed_ts": 1.0, "vwap_4h": price}


def test_worker_runs_grid_and_records_trades():
    async def scenario():
        await init_db()
        async with Session() as s:
            s.add(User(id=555, first_name="T"))
            s.add(BotSettings(user_id=555, symbols=["SOLUSDT"], paper_balance=10_000))
            await s.commit()
        mgr = EngineManager()
        hub = FakeHub()
        hub.feeds["SOLUSDT"].price = 100.0
        brain = Brain(features={"SOLUSDT": features_at(100.0)},
                      insights={"SOLUSDT": Insight("range", 0.8, 1.0, 0.1, 0.1, "long", 0.6, 1.0, "боковик", "ai")},
                      tuning={}, learner=Learner())
        mgr.brain = brain
        notes = []

        async def notify(uid, text):
            notes.append(text)

        ex = PaperExchange(10_000)
        w = UserWorker(555, ex, hub, brain, profile("balanced"), ["SOLUSDT"], {"grid": True, "trend": True, "liquidation": True},
                       mgr.record_trade, notify, mgr.save_snapshot)
        await w.step(1000.0)
        assert "SOLUSDT" in w.grids
        g = w.grids["SOLUSDT"]
        step = g.plan.step_pct / 100
        for price in (g.level_price(1) - 0.01, g.level_price(1) * (1 + step) + 0.01):
            hub.feeds["SOLUSDT"].price = price
            await w.step(1001.0)
        async with Session() as s:
            trades = (await s.execute(select(Trade).where(Trade.user_id == 555))).scalars().all()
            stat = await s.get(StrategyStat, "SOLUSDT|grid|range")
            bs = await s.get(BotSettings, 555)
        assert len(trades) == 1 and trades[0].strategy == "grid" and trades[0].pnl > 0
        assert stat is not None and stat.n == 1 and stat.ewma_r > 0
        assert bs.paper_balance > 10_000

        # ИИ переключил режим на волатильность -> сетка мягко останавливается
        brain.insights["SOLUSDT"] = Insight("high_volatility", 0.8, 0.0, 0.2, 0.9, "off", 0.6, 0.5, "", "ai")
        await w.step(1002.0)
        assert "SOLUSDT" not in w.grids
        await w.shutdown()
    asyncio.run(scenario())
