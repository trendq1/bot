"""Оркестратор: общий рынок + ИИ-анализ + торговые процессы всех клиентов."""
import asyncio
import logging
import time
from collections import defaultdict
from datetime import datetime, timedelta, timezone
from typing import Awaitable, Callable, Dict, List, Optional, Tuple

from sqlalchemy import select

from ..config import settings
from ..db import AIInsight, BotSettings, EquitySnapshot, ExchangeAccount, Lesson, Session, SymbolTuning, Trade, User, utcnow
from ..exchange.bybit import BybitExchange
from ..exchange.market import MarketHub
from ..exchange.paper import PaperExchange
from ..security import decrypt
from .ai import AIAnalyst, Insight, apply_tuning, rule_insight
from .indicators import features
from .learner import Learner
from .risk import profile
from .worker import Brain, UserWorker

log = logging.getLogger("manager")

Notifier = Callable[[int, str], Awaitable[None]]


async def _noop_notify(user_id: int, text: str) -> None:
    pass


class EngineManager:
    def __init__(self, notify: Notifier = _noop_notify):
        self.hub = MarketHub(settings.symbols)
        self.ai = AIAnalyst()
        self.brain = Brain(features={}, insights={}, tuning={}, learner=Learner())
        self.lessons: List[str] = []
        self.notify = notify
        self.workers: Dict[int, Tuple[UserWorker, asyncio.Task]] = {}
        self._ai_ts: Dict[str, float] = {}
        self._review_day: Optional[str] = None

    # --- запуск ---

    async def run(self) -> None:
        await self.brain.learner.load()
        await self._load_memory()
        asyncio.create_task(self.hub.run())
        while True:
            try:
                self._refresh_features()
                await self._run_ai()
                await self.sync_workers()
                await self._daily_review()
            except Exception as e:  # noqa: BLE001
                log.exception("manager: %s", e)
            await asyncio.sleep(10)

    async def _load_memory(self) -> None:
        async with Session() as s:
            self.lessons = [l.text for l in (await s.execute(select(Lesson).order_by(Lesson.id.desc()).limit(10))).scalars()][::-1]
            for t in (await s.execute(select(SymbolTuning))).scalars():
                self.brain.tuning[t.symbol] = t.params

    def _refresh_features(self) -> None:
        for sym, feed in self.hub.feeds.items():
            f = features(feed.klines, feed.price)
            if f:
                self.brain.features[sym] = f
                if sym not in self.brain.insights:
                    self.brain.insights[sym] = rule_insight(f, self.brain.tuning.get(sym))

    # --- ИИ ---

    async def _run_ai(self) -> None:
        now = time.time()
        for sym, f in list(self.brain.features.items()):
            if now - self._ai_ts.get(sym, 0) < settings.ai_interval_min * 60:
                continue
            self._ai_ts[sym] = now
            feed = self.hub.feeds[sym]
            ins = await self.ai.analyze(
                sym, f, self.brain.learner.stats_for(sym), self.lessons, self.brain.tuning.get(sym, {}),
                {"longs_liquidated": feed.liq_sum("long", 3600), "shorts_liquidated": feed.liq_sum("short", 3600)},
                feed.funding)
            self.brain.insights[sym] = ins
            async with Session() as s:
                s.add(AIInsight(symbol=sym, source=ins.source, regime=ins.regime, payload=ins.to_dict()))
                await s.commit()

    async def _daily_review(self) -> None:
        """Раз в сутки (после 00:05 UTC): уроки и подстройка параметров + итог дня клиентам."""
        now = datetime.now(timezone.utc)
        today = now.strftime("%Y-%m-%d")
        if self._review_day == today or now.hour == 0 and now.minute < 5:
            return
        if self._review_day is None:
            self._review_day = today   # после перезапуска не повторяем разбор сразу
            return
        self._review_day = today
        since = utcnow() - timedelta(days=1)
        async with Session() as s:
            trades = (await s.execute(select(Trade).where(Trade.closed_at >= since))).scalars().all()
        await self._send_day_summaries(trades)
        if not trades:
            return
        agg: Dict[str, dict] = defaultdict(lambda: {"trades": 0, "wins": 0, "pnl": 0.0, "sum_r": 0.0})
        for t in trades:
            a = agg[f"{t.symbol}|{t.strategy}|{t.regime}"]
            a["trades"] += 1
            a["wins"] += t.pnl > 0
            a["pnl"] = round(a["pnl"] + t.pnl, 2)
            a["sum_r"] = round(a["sum_r"] + t.r, 2)
        result = await self.ai.review_day(dict(agg), self.lessons)
        if not result:
            return
        async with Session() as s:
            for text in result.get("lessons", [])[:5]:
                s.add(Lesson(text=text[:500]))
                self.lessons.append(text[:500])
            for tune in result.get("tuning", []):
                sym = tune.get("symbol")
                if sym not in self.hub.feeds:
                    continue
                params = apply_tuning(self.brain.tuning.get(sym, {}), tune.get("param"), float(tune.get("value", 0)))
                self.brain.tuning[sym] = params
                row = await s.get(SymbolTuning, sym)
                if row is None:
                    s.add(SymbolTuning(symbol=sym, params=params))
                else:
                    row.params, row.updated_at = params, utcnow()
            await s.commit()
        self.lessons = self.lessons[-10:]

    async def _send_day_summaries(self, trades: List[Trade]) -> None:
        per_user: Dict[int, List[Trade]] = defaultdict(list)
        for t in trades:
            per_user[t.user_id].append(t)
        for uid, ts in per_user.items():
            pnl = sum(t.pnl for t in ts)
            wins = sum(1 for t in ts if t.pnl > 0)
            await self.notify(uid, f"📊 Итог дня: {pnl:+.2f} USDT · сделок {len(ts)} · прибыльных {wins}")

    # --- клиенты ---

    async def sync_workers(self) -> None:
        """Запускает/останавливает процессы клиентов по состоянию в базе."""
        async with Session() as s:
            rows = (await s.execute(
                select(BotSettings, User, ExchangeAccount)
                .join(User, User.id == BotSettings.user_id)
                .outerjoin(ExchangeAccount, ExchangeAccount.user_id == BotSettings.user_id)
            )).all()
        should_run = {}
        for bs, user, acc in rows:
            ok = bs.running and not user.blocked and (
                bs.trading_mode == "paper" or (acc is not None and user.has_subscription()))
            if ok:
                should_run[user.id] = (bs, acc)
        for uid in list(self.workers):
            if uid not in should_run:
                await self.stop_worker(uid)
        for uid, (bs, acc) in should_run.items():
            if uid not in self.workers:
                await self.start_worker(uid, bs, acc)

    async def start_worker(self, uid: int, bs: BotSettings, acc: Optional[ExchangeAccount]) -> None:
        if bs.trading_mode == "paper":
            ex = PaperExchange(bs.paper_balance)
        else:
            ex = BybitExchange(decrypt(acc.api_key_enc), decrypt(acc.api_secret_enc), acc.mode)
            try:
                await ex.prepare()
            except Exception as e:  # noqa: BLE001
                await self._set_status(uid, f"ошибка подключения к Bybit: {e}", running=False)
                return
        w = UserWorker(uid, ex, self.hub, self.brain, profile(bs.risk_profile),
                       bs.symbols or settings.default_symbols, bs.strategies or {},
                       self.record_trade, self.notify, self.save_snapshot)
        self.workers[uid] = (w, asyncio.create_task(w.run()))
        log.info("user %s: запущен (%s, %s)", uid, bs.trading_mode, bs.risk_profile)

    async def stop_worker(self, uid: int) -> None:
        w, task = self.workers.pop(uid)
        try:
            await w.shutdown()
        finally:
            task.cancel()
        log.info("user %s: остановлен", uid)

    async def restart_worker(self, uid: int) -> None:
        if uid in self.workers:
            await self.stop_worker(uid)
        await self.sync_workers()

    def force_ai(self) -> None:
        """Анализ всех монет на следующем цикле (кнопка в админ-панели)."""
        self._ai_ts.clear()

    def engine_state(self) -> dict:
        return {
            "workers": len(self.workers),
            "symbols": [{"symbol": s, "price": f.price, "has_klines": bool(f.klines),
                         "regime": self.brain.insights[s].regime if s in self.brain.insights else None,
                         "source": self.brain.insights[s].source if s in self.brain.insights else None,
                         "ai_age_min": round((time.time() - self._ai_ts[s]) / 60) if s in self._ai_ts else None}
                        for s, f in self.hub.feeds.items()],
            "ai_enabled": self.ai.enabled,
            "lessons": len(self.lessons),
        }

    def status(self, uid: int) -> Optional[str]:
        w = self.workers.get(uid)
        return w[0].status if w else None

    def open_state(self, uid: int) -> dict:
        w = self.workers.get(uid)
        if not w:
            return {"grids": [], "directional": [], "equity": None}
        wk = w[0]
        return {
            "equity": wk.equity,
            "day_pnl_pct": round(wk.guard.day_pnl_pct(wk.equity), 2) if wk.equity else 0.0,
            "grids": [{"symbol": s, "mode": g.plan.mode, "step_pct": round(g.plan.step_pct, 3),
                       "filled": len(g.inventory), "levels": g.plan.levels} for s, g in wk.grids.items()],
            "directional": [{"symbol": s, "strategy": d.strategy, "side": d.side, "entry": d.entry, "stop": d.stop}
                            for s, d in wk.directional.items()],
        }

    # --- запись ---

    async def record_trade(self, uid: int, t: dict) -> None:
        async with Session() as s:
            s.add(Trade(user_id=uid, symbol=t["symbol"], strategy=t["strategy"], side=t["side"], qty=t["qty"],
                        entry=t["entry"], exit=t["exit"], pnl=t["pnl"], r=t["r"], regime=t["regime"],
                        mode=t["mode"], opened_at=utcnow(), closed_at=utcnow()))
            if "paper_balance" in t:
                bs = await s.get(BotSettings, uid)
                if bs:
                    bs.paper_balance = t["paper_balance"]
            await s.commit()
        await self.brain.learner.record(t["symbol"], t["strategy"], t["regime"] or "range", t["r"])

    async def save_snapshot(self, uid: int, equity: float) -> None:
        async with Session() as s:
            s.add(EquitySnapshot(user_id=uid, equity=equity))
            await s.commit()

    async def _set_status(self, uid: int, text: str, running: Optional[bool] = None) -> None:
        async with Session() as s:
            bs = await s.get(BotSettings, uid)
            if bs:
                bs.status_text = text
                if running is not None:
                    bs.running = running
                await s.commit()
        await self.notify(uid, f"⚠️ {text}")

    def market_view(self) -> List[dict]:
        out = []
        for sym in settings.symbols:
            ins: Optional[Insight] = self.brain.insights.get(sym)
            f = self.brain.features.get(sym)
            if ins and f:
                out.append({"symbol": sym, "price": self.hub.feeds[sym].price or f["price"], **ins.to_dict(),
                            "change_24h": round(f["ret_24h"], 2)})
        return out
