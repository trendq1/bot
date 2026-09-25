"""Торговый процесс одного клиента.

Каждые несколько секунд:
1. обновляет депозит и проверяет дневной лимит убытка;
2. по каждой монете выбирает стратегию: вес от ИИ × множитель обучения × настройки клиента;
3. ведёт сетки, открывает направленные сделки, фиксирует закрытые сделки.
На одной монете одновременно работает только одна стратегия (one-way режим позиций).
"""
import asyncio
import logging
import time
from dataclasses import dataclass
from typing import Awaitable, Callable, Dict, List, Optional

from ..exchange.base import Exchange, Position
from ..exchange.market import MarketHub
from ..exchange.paper import PaperExchange
from .ai import TUNABLE, Insight
from .learner import Learner
from .risk import RiskGuard, RiskProfile
from .strategies.directional import Setup, liquidation_setup, trend_setup
from .strategies.grid import Grid, GridEvent, plan_grid

log = logging.getLogger("worker")

MAX_HOLD_SEC = 3 * 3600
LIQ_COOLDOWN_SEC = 20 * 60


@dataclass
class Brain:
    """Общее знание о рынке, которое manager обновляет для всех клиентов."""
    features: Dict[str, dict]
    insights: Dict[str, Insight]
    tuning: Dict[str, dict]
    learner: Learner


@dataclass
class OpenDirectional:
    strategy: str
    side: str
    entry: float
    stop: float
    qty: float
    regime: str
    opened_ms: int
    risk_usd: float


Recorder = Callable[[int, dict], Awaitable[None]]


class UserWorker:
    def __init__(self, user_id: int, ex: Exchange, hub: MarketHub, brain: Brain, prof: RiskProfile,
                 symbols: List[str], strategies: Dict[str, bool], record: Recorder,
                 notify: Callable[[int, str], Awaitable[None]], snapshot: Callable[[int, float], Awaitable[None]]):
        self.user_id, self.ex, self.hub, self.brain = user_id, ex, hub, brain
        self.prof = prof
        self.symbols = [s for s in symbols if s in hub.feeds]
        self.enabled = strategies
        self.record, self.notify, self.snapshot = record, notify, snapshot
        self.guard = RiskGuard(prof)
        self.grids: Dict[str, Grid] = {}
        self.directional: Dict[str, OpenDirectional] = {}
        self.last_trend_bar: Dict[str, float] = {}
        self.last_liq: Dict[str, float] = {}
        self.equity = 0.0
        self._equity_ts = 0.0
        self._snap_ts = 0.0
        self._stopping = False
        self.status = "запуск"
        self._leverage_set: set = set()

    # --- цикл ---

    async def run(self) -> None:
        while not self._stopping:
            try:
                await self.step(time.time())
            except asyncio.CancelledError:
                raise
            except Exception as e:  # noqa: BLE001 — сбой одного шага не должен останавливать клиента
                log.exception("user %s: ошибка шага: %s", self.user_id, e)
                self.status = f"ошибка: {e}"
                await asyncio.sleep(10)
            await asyncio.sleep(3)

    async def step(self, now: float) -> None:
        if isinstance(self.ex, PaperExchange):
            self.ex.update_prices(self.hub.prices())
        if now - self._equity_ts > 30 or not self.equity:
            self.equity = await self.ex.equity()
            self.guard.update_equity(self.equity)
            self._equity_ts = now
        if now - self._snap_ts > 900:
            await self.snapshot(self.user_id, self.equity)
            self._snap_ts = now

        allowed, reason = self.guard.allowed(self.equity, now)
        positions = await self.ex.positions()
        await self._check_directional(positions, now)
        for sym in self.symbols:
            await self._manage_symbol(sym, positions, allowed, now)

        grids = ", ".join(f"{s}:{g.plan.mode}" for s, g in self.grids.items())
        self.status = ("⏸ " + reason) if not allowed else f"работает · сетки: {grids or 'нет'} · сделки: {len(self.directional)}"

    # --- выбор стратегии ---

    def weights(self, sym: str, ins: Insight) -> Dict[str, float]:
        m = self.brain.learner.mult
        return {
            "grid": ins.w_grid * m(sym, "grid", ins.regime) if self.enabled.get("grid", True) else 0.0,
            "trend": ins.w_trend * m(sym, "trend", ins.regime) if self.enabled.get("trend", True) else 0.0,
            "liquidation": ins.w_liquidation * m(sym, "liquidation", ins.regime) if self.enabled.get("liquidation", True) else 0.0,
        }

    async def _manage_symbol(self, sym: str, positions: Dict[str, Position], allowed: bool, now: float) -> None:
        feed, inst = self.hub.feeds[sym], self.hub.instruments.get(sym)
        f, ins = self.brain.features.get(sym), self.brain.insights.get(sym)
        if not (feed.price and inst and f and ins):
            return
        if sym not in self._leverage_set:
            await self.ex.set_leverage(sym, int(min(self.prof.leverage, inst.max_leverage)))
            self._leverage_set.add(sym)
        w = self.weights(sym, ins)
        tuning = self.brain.tuning.get(sym, {})

        grid = self.grids.get(sym)
        if grid:
            for ev in await grid.sync(feed.price):
                await self._on_grid_event(sym, grid, ev, ins.regime)
            want = allowed and ins.grid_mode == grid.plan.mode and w["grid"] >= 0.4
            if grid.active and not grid.draining and not want:
                await grid.drain()
            elif grid.active and grid.idle_far(feed.price):
                await grid.stop(feed.price)
            if not grid.active:
                del self.grids[sym]
            return

        if not allowed or sym in self.directional or sym in positions:
            return

        best = max(w, key=w.get)
        if best == "grid" and w["grid"] >= 0.5 and ins.grid_mode != "off" and len(self.grids) < self.prof.max_grids:
            await self._start_grid(sym, f, ins, tuning)
            return

        if len(self.directional) >= self.prof.max_directional:
            return
        setup: Optional[Setup] = None
        if w["trend"] >= 0.5 and self.last_trend_bar.get(sym) != f["last_closed_ts"]:
            self.last_trend_bar[sym] = f["last_closed_ts"]
            rr = tuning.get("trend_rr", self.prof.rr)
            setup = trend_setup({**f, "price": feed.price}, ins.regime, rr)
        if setup is None and w["liquidation"] >= 0.4 and now - self.last_liq.get(sym, 0) > LIQ_COOLDOWN_SEC:
            setup = liquidation_setup(feed, f, tuning.get("liq_threshold_mult", TUNABLE["liq_threshold_mult"][2]), now)
            if setup:
                self.last_liq[sym] = now
        if setup:
            await self._open_directional(sym, setup, ins, w[setup.strategy])

    # --- сетка ---

    async def _start_grid(self, sym: str, f: dict, ins: Insight, tuning: dict) -> None:
        inst, price = self.hub.instruments[sym], self.hub.feeds[sym].price
        capital = self.equity * self.prof.grid_alloc / self.prof.max_grids * ins.risk_mult
        max_loss = self.equity * self.prof.grid_max_loss_pct / 100 * ins.risk_mult
        plan = plan_grid(self.equity, price, f["atr"], inst, ins.grid_mode,
                         tuning.get("grid_step_atr", ins.grid_step_atr), self.prof.grid_levels,
                         capital, int(min(self.prof.leverage, inst.max_leverage)), max_loss)
        if plan is None:
            return
        grid = Grid(self.ex, sym, inst, plan)
        await grid.start()
        self.grids[sym] = grid
        log.info("user %s: сетка %s %s шаг %.2f%% x%d, объём уровня %s",
                 self.user_id, sym, plan.mode, plan.step_pct, plan.levels, plan.qty)

    async def _on_grid_event(self, sym: str, grid: Grid, ev: GridEvent, regime: str) -> None:
        r = ev.pnl / grid.unit_risk()
        await self._record(sym, "grid", ev.side, ev.qty, ev.entry, ev.exit, ev.pnl, r, regime,
                           notify=ev.kind == "stop")

    # --- направленные сделки ---

    async def _open_directional(self, sym: str, s: Setup, ins: Insight, weight: float) -> None:
        inst = self.hub.instruments[sym]
        risk_usd = self.equity * self.prof.risk_pct / 100 * ins.risk_mult * min(1.2, max(0.5, weight))
        qty = min(risk_usd / s.risk, self.equity * self.prof.leverage * 0.9 / s.entry, inst.max_mkt_qty)
        q = inst.round_qty(qty)
        if not inst.qty_ok(q, s.entry):
            return
        long = s.side == "Buy"
        stop = inst.round_price(s.stop, up=not long)
        take = inst.round_price(s.take, up=not long)
        await self.ex.place_market(sym, s.side, q, stop=stop, take=take)
        self.directional[sym] = OpenDirectional(s.strategy, s.side, s.entry, float(stop), float(q), ins.regime,
                                                int(time.time() * 1000), float(q) * s.risk)
        name = "Тренд" if s.strategy == "trend" else "Отскок после ликвидаций"
        await self.notify(self.user_id, f"{'🟢 LONG' if long else '🔴 SHORT'} {sym} · {name}\n"
                                        f"Вход {s.entry:.6g} · SL {float(stop):.6g} · TP {float(take):.6g}")

    async def _check_directional(self, positions: Dict[str, Position], now: float) -> None:
        now_ms = int(now * 1000)
        for sym, d in list(self.directional.items()):
            if sym in positions:
                if now_ms - d.opened_ms > MAX_HOLD_SEC * 1000:
                    await self.ex.close_position(sym)
                continue
            if now_ms - d.opened_ms < 3000:
                continue
            closed = await self.ex.closed_pnl(sym, d.opened_ms - 1000)
            pnl = sum(c.pnl for c in closed)
            exit_price = closed[-1].exit_price if closed else d.entry
            del self.directional[sym]
            await self._record(sym, d.strategy, d.side, d.qty, d.entry, exit_price, pnl,
                               pnl / d.risk_usd if d.risk_usd else 0.0, d.regime, notify=True)

    # --- запись результата ---

    async def _record(self, sym, strategy, side, qty, entry, exit_price, pnl, r, regime, notify: bool) -> None:
        trade = {"symbol": sym, "strategy": strategy, "side": side, "qty": qty, "entry": entry,
                 "exit": exit_price, "pnl": pnl, "r": r, "regime": regime, "mode": self.ex.mode}
        if isinstance(self.ex, PaperExchange):
            trade["paper_balance"] = self.ex.balance
        await self.record(self.user_id, trade)
        if self.guard.on_trade(pnl, time.time()):
            await self.notify(self.user_id, f"⏸ {self.prof.max_consecutive_losses} убытка подряд — пауза 3 часа")
        if notify:
            await self.notify(self.user_id, f"{'✅' if pnl >= 0 else '❌'} {sym} · {strategy}: {pnl:+.2f} USDT")

    # --- остановка ---

    async def shutdown(self) -> None:
        """Остановка клиентом: отменяем ордера сеток и закрываем их позиции.
        Направленные сделки остаются со своими стопом и тейком на бирже."""
        self._stopping = True
        for sym, grid in list(self.grids.items()):
            ev = await grid.stop(self.hub.feeds[sym].price or grid.plan.center)
            if ev:
                ins = self.brain.insights.get(sym)
                await self._on_grid_event(sym, grid, ev, ins.regime if ins else "range")
        self.grids.clear()
