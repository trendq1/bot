"""Бумажная биржа: виртуальный счёт на реальных ценах, с комиссиями Bybit.

Лимитные ордера исполняются, когда цена их пересекает; стоп и тейк позиции
проверяются на каждом обновлении цены. Позиция одна на символ (one-way режим).
"""
import time
from dataclasses import dataclass
from decimal import Decimal
from typing import Dict, List, Optional, Set, Tuple

from .base import MAKER_FEE, TAKER_FEE, ClosedPnl, OrderResult, Position


@dataclass
class _Order:
    link_id: str
    side: str
    qty: float
    price: float
    reduce_only: bool


@dataclass
class _Pos:
    size: float = 0.0      # >0 лонг, <0 шорт
    entry: float = 0.0
    stop: Optional[float] = None
    take: Optional[float] = None


class PaperExchange:
    mode = "paper"

    def __init__(self, balance: float):
        self.balance = balance
        self.prices: Dict[str, float] = {}
        self.orders: Dict[str, Dict[str, _Order]] = {}
        self.results: Dict[str, OrderResult] = {}
        self.pos: Dict[str, _Pos] = {}
        self.closed: Dict[str, List[ClosedPnl]] = {}

    # --- движок цен ---

    def update_prices(self, prices: Dict[str, float]) -> None:
        for symbol, price in prices.items():
            self.prices[symbol] = price
            for o in list(self.orders.get(symbol, {}).values()):
                hit = price <= o.price if o.side == "Buy" else price >= o.price
                if hit:
                    self._fill_order(symbol, o)
            p = self.pos.get(symbol)
            if p and p.size:
                long = p.size > 0
                if p.stop is not None and ((long and price <= p.stop) or (not long and price >= p.stop)):
                    self._trade(symbol, "Sell" if long else "Buy", abs(p.size), p.stop, TAKER_FEE)
                elif p.take is not None and ((long and price >= p.take) or (not long and price <= p.take)):
                    self._trade(symbol, "Sell" if long else "Buy", abs(p.size), p.take, TAKER_FEE)

    def _fill_order(self, symbol: str, o: _Order) -> None:
        del self.orders[symbol][o.link_id]
        p = self.pos.get(symbol, _Pos())
        reduces = (p.size > 0 and o.side == "Sell") or (p.size < 0 and o.side == "Buy")
        if o.reduce_only and not reduces:
            self.results[o.link_id] = OrderResult("Cancelled", 0.0, 0.0)
            return
        qty = min(o.qty, abs(p.size)) if o.reduce_only else o.qty
        self._trade(symbol, o.side, qty, o.price, MAKER_FEE)
        self.results[o.link_id] = OrderResult("Filled", o.price, qty)

    def _trade(self, symbol: str, side: str, qty: float, price: float, fee_rate: float) -> None:
        p = self.pos.setdefault(symbol, _Pos())
        signed = qty if side == "Buy" else -qty
        self.balance -= qty * price * fee_rate
        if p.size == 0 or (p.size > 0) == (signed > 0):
            new_size = p.size + signed
            p.entry = (p.entry * abs(p.size) + price * qty) / abs(new_size)
            p.size = new_size
            return
        closing = min(qty, abs(p.size))
        pnl = closing * (price - p.entry) * (1 if p.size > 0 else -1)
        self.balance += pnl
        self.closed.setdefault(symbol, []).append(
            ClosedPnl(pnl - closing * price * fee_rate - closing * p.entry * fee_rate, price, int(time.time() * 1000)))
        rest = qty - closing
        p.size += signed if rest == 0 else (-p.size)
        if p.size == 0:
            p.entry, p.stop, p.take = 0.0, None, None
        if rest > 0:
            p.size, p.entry = (rest if side == "Buy" else -rest), price

    # --- интерфейс Exchange ---

    async def equity(self) -> float:
        unreal = sum(p.size * (self.prices.get(s, p.entry) - p.entry) for s, p in self.pos.items() if p.size)
        return self.balance + unreal

    async def positions(self) -> Dict[str, Position]:
        return {s: Position(s, "Buy" if p.size > 0 else "Sell", abs(p.size), p.entry)
                for s, p in self.pos.items() if p.size}

    async def set_leverage(self, symbol: str, leverage: int) -> None:
        pass

    async def place_limit(self, symbol, side, qty: Decimal, price: Decimal, link_id, reduce_only=False) -> None:
        self.orders.setdefault(symbol, {})[link_id] = _Order(link_id, side, float(qty), float(price), reduce_only)
        self.results[link_id] = OrderResult("New", 0.0, 0.0)

    async def place_market(self, symbol, side, qty: Decimal, stop=None, take=None, reduce_only=False) -> None:
        price = self.prices[symbol]
        p = self.pos.get(symbol, _Pos())
        q = float(qty)
        if reduce_only:
            q = min(q, abs(p.size))
        self._trade(symbol, side, q, price, TAKER_FEE)
        p = self.pos[symbol]
        if stop is not None:
            p.stop = float(stop)
        if take is not None:
            p.take = float(take)

    async def cancel(self, symbol, link_id) -> None:
        if self.orders.get(symbol, {}).pop(link_id, None):
            self.results[link_id] = OrderResult("Cancelled", 0.0, 0.0)

    async def cancel_all(self, symbol) -> None:
        for link_id in list(self.orders.get(symbol, {})):
            await self.cancel(symbol, link_id)

    async def open_order_ids(self, symbol) -> Set[str]:
        return set(self.orders.get(symbol, {}))

    async def order_result(self, symbol, link_id) -> OrderResult:
        return self.results.get(link_id, OrderResult("Unknown", 0.0, 0.0))

    async def closed_pnl(self, symbol, since_ms) -> List[ClosedPnl]:
        return [c for c in self.closed.get(symbol, []) if c.ts_ms >= since_ms]

    async def close_position(self, symbol) -> Optional[Tuple[float, float]]:
        p = self.pos.get(symbol)
        if not p or not p.size:
            return None
        price = self.prices[symbol]
        qty = abs(p.size)
        self._trade(symbol, "Sell" if p.size > 0 else "Buy", qty, price, TAKER_FEE)
        return price, qty
