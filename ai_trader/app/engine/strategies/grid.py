"""Адаптивная сетка лимитных ордеров (много мелких сделок).

Режим long: покупки на уровнях ниже центра; после покупки на уровне выставляется
продажа на шаг выше (reduce-only). Когда продажа исполнилась — цикл закрыт с
прибылью ≈ шаг − комиссии, и покупка на этом уровне выставляется снова.
Режим short — зеркально. Если цена уходит за нижнюю (верхнюю) границу на 1.5 шага,
вся позиция сетки закрывается по рынку — это стоп сетки.
"""
import itertools
import secrets
from dataclasses import dataclass
from decimal import Decimal
from typing import Dict, List, Optional, Tuple

from ...exchange.base import MAKER_FEE, TAKER_FEE, Exchange, Instrument

MIN_STEP_PCT = 0.2
MAX_STEP_PCT = 2.0
STOP_BUFFER_STEPS = 1.5


@dataclass
class GridOrder:
    link_id: str
    level: int
    kind: str                 # open | close
    side: str
    price: float
    qty: Decimal
    open_price: Optional[float] = None


@dataclass
class GridEvent:
    kind: str                 # cycle | stop
    side: str                 # сторона входа
    qty: float
    entry: float
    exit: float
    pnl: float


@dataclass
class GridPlan:
    mode: str
    center: float
    step_pct: float
    levels: int
    qty: Decimal
    max_loss: float

    def worst_loss(self) -> float:
        return worst_loss_per_qty(self.center, self.step_pct, self.levels) * float(self.qty)


def worst_loss_per_qty(price: float, step_pct: float, levels: int) -> float:
    """Убыток на единицу объёма уровня, если все уровни исполнились и сработал стоп."""
    step = price * step_pct / 100
    return sum(step * (levels + STOP_BUFFER_STEPS - i) for i in range(1, levels + 1))


def plan_grid(equity: float, price: float, atr: float, inst: Instrument, mode: str, step_atr: float,
              levels: int, capital: float, leverage: int, max_loss: float) -> Optional[GridPlan]:
    step_pct = max(MIN_STEP_PCT, min(MAX_STEP_PCT, step_atr * atr / price * 100))
    qty = capital * leverage / levels / price
    per_qty = worst_loss_per_qty(price, step_pct, levels)
    qty = min(qty, max_loss / per_qty) if per_qty > 0 else qty
    q = inst.round_qty(qty)
    if not inst.qty_ok(q, price * (1 - step_pct / 100 * levels)):
        return None
    return GridPlan(mode, price, step_pct, levels, q, max_loss)


class Grid:
    def __init__(self, ex: Exchange, symbol: str, inst: Instrument, plan: GridPlan):
        self.ex, self.symbol, self.inst, self.plan = ex, symbol, inst, plan
        self.tag = "g" + secrets.token_hex(3)
        self.seq = itertools.count(1)
        self.orders: Dict[str, GridOrder] = {}
        self.inventory: Dict[int, float] = {}      # уровень -> цена покупки/продажи на входе
        self.active = False
        self.draining = False

    # --- геометрия ---

    @property
    def dir(self) -> int:
        return 1 if self.plan.mode == "long" else -1

    @property
    def open_side(self) -> str:
        return "Buy" if self.dir == 1 else "Sell"

    @property
    def close_side(self) -> str:
        return "Sell" if self.dir == 1 else "Buy"

    def level_price(self, i: int) -> float:
        return self.plan.center * (1 - self.dir * i * self.plan.step_pct / 100)

    @property
    def stop_price(self) -> float:
        return self.level_price(self.plan.levels + STOP_BUFFER_STEPS)

    @property
    def finished(self) -> bool:
        return not self.active

    def _link(self, kind: str, level: int) -> str:
        return f"{self.tag}{kind[0]}{level}-{next(self.seq)}"

    async def _place(self, kind: str, level: int, price: float, open_price: Optional[float] = None) -> None:
        side = self.open_side if kind == "open" else self.close_side
        # покупку округляем вниз, продажу вверх — не хуже расчётной цены
        p = self.inst.round_price(price, up=side == "Sell")
        link = self._link(kind, level)
        await self.ex.place_limit(self.symbol, side, self.plan.qty, p, link, reduce_only=kind == "close")
        self.orders[link] = GridOrder(link, level, kind, side, float(p), self.plan.qty, open_price)

    # --- жизненный цикл ---

    async def start(self) -> None:
        await self.ex.cancel_all(self.symbol)
        for i in range(1, self.plan.levels + 1):
            await self._place("open", i, self.level_price(i))
        self.active = True

    async def sync(self, price: float) -> List[GridEvent]:
        if not self.active:
            return []
        events: List[GridEvent] = []
        if self.inventory and self.dir * (price - self.stop_price) <= 0:
            ev = await self.stop(price)
            return [ev] if ev else []

        live = await self.ex.open_order_ids(self.symbol)
        for link, o in list(self.orders.items()):
            if link in live:
                continue
            res = await self.ex.order_result(self.symbol, link)
            if res.status in ("New", "Untriggered", "Unknown", "PartiallyFilled"):
                continue                        # ещё не отразилось в истории — проверим позже
            del self.orders[link]
            filled = res.status in ("Filled", "PartiallyFilledCanceled") and res.filled_qty > 0
            fill_price = res.avg_price or o.price
            if o.kind == "open":
                if filled:
                    self.inventory[o.level] = fill_price
                    await self._place("close", o.level, fill_price * (1 + self.dir * self.plan.step_pct / 100),
                                      open_price=fill_price)
                elif not self.draining:
                    await self._place("open", o.level, self.level_price(o.level))
            else:
                if filled:
                    entry = o.open_price or self.inventory.get(o.level, fill_price)
                    self.inventory.pop(o.level, None)
                    qty = float(o.qty)
                    pnl = self.dir * (fill_price - entry) * qty - (entry + fill_price) * qty * MAKER_FEE
                    events.append(GridEvent("cycle", self.open_side, qty, entry, fill_price, pnl))
                    if not self.draining:
                        await self._place("open", o.level, self.level_price(o.level))
                else:
                    # закрывающий ордер отменён извне — выставляем снова, иначе позиция останется без выхода
                    await self._place("close", o.level, o.price, open_price=o.open_price)

        if self.draining and not self.inventory:
            await self.ex.cancel_all(self.symbol)
            self.active = False
        return events

    async def drain(self) -> None:
        """Мягкая остановка: новых покупок нет, открытые уровни закрываются по своим тейкам."""
        self.draining = True
        for link, o in list(self.orders.items()):
            if o.kind == "open":
                await self.ex.cancel(self.symbol, link)
                del self.orders[link]
        if not self.inventory:
            self.active = False

    async def stop(self, price: float) -> Optional[GridEvent]:
        """Жёсткая остановка: отмена всех ордеров и закрытие позиции по рынку."""
        await self.ex.cancel_all(self.symbol)
        self.orders.clear()
        self.active = False
        if not self.inventory:
            return None
        closed = await self.ex.close_position(self.symbol)
        qty = float(self.plan.qty) * len(self.inventory)
        entry = sum(self.inventory.values()) / len(self.inventory)
        self.inventory.clear()
        if not closed:
            return None
        exit_price = closed[0] if self.ex.mode == "paper" else price   # для биржи — последняя цена (оценка)
        pnl = self.dir * (exit_price - entry) * qty - entry * qty * MAKER_FEE - exit_price * qty * TAKER_FEE
        return GridEvent("stop", self.open_side, qty, entry, exit_price, pnl)

    def idle_far(self, price: float) -> bool:
        """Позиции нет, а цена ушла от центра против сетки — пора перестроить сетку вокруг новой цены."""
        return not self.inventory and self.dir * (price - self.plan.center) > 2 * self.plan.center * self.plan.step_pct / 100

    def unit_risk(self) -> float:
        """Риск одного уровня в USDT — для нормализации результата в R."""
        return max(self.plan.max_loss / self.plan.levels, 1e-9)

    def snapshot(self) -> Tuple[str, float, int, int]:
        return self.plan.mode, self.plan.step_pct, len(self.inventory), self.plan.levels
