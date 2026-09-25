"""Общий интерфейс биржи: движок одинаково работает с Bybit и с бумажным счётом."""
from dataclasses import dataclass
from decimal import ROUND_DOWN, ROUND_UP, Decimal
from typing import Dict, List, Optional, Protocol, Set, Tuple

MAKER_FEE = 0.0002   # Bybit USDT Perp, базовый уровень
TAKER_FEE = 0.00055


@dataclass
class Instrument:
    symbol: str
    tick_size: str
    qty_step: str
    min_qty: float
    max_mkt_qty: float
    min_notional: float
    max_leverage: float

    def round_qty(self, qty: float) -> Decimal:
        step = Decimal(self.qty_step)
        return (Decimal(str(qty)) / step).to_integral_value(rounding=ROUND_DOWN) * step

    def round_price(self, price: float, up: bool = False) -> Decimal:
        tick = Decimal(self.tick_size)
        return (Decimal(str(price)) / tick).to_integral_value(rounding=ROUND_UP if up else ROUND_DOWN) * tick

    def qty_ok(self, qty: Decimal, price: float) -> bool:
        return qty >= Decimal(str(self.min_qty)) and float(qty) * price >= self.min_notional


@dataclass
class Position:
    symbol: str
    side: str        # Buy | Sell
    qty: float
    entry: float


@dataclass
class OrderResult:
    status: str      # Filled | Cancelled | New | PartiallyFilled | Unknown
    avg_price: float
    filled_qty: float


@dataclass
class ClosedPnl:
    pnl: float
    exit_price: float
    ts_ms: int


def fmt(d: Decimal) -> str:
    return format(d.normalize(), "f")


class Exchange(Protocol):
    mode: str

    async def equity(self) -> float: ...
    async def positions(self) -> Dict[str, Position]: ...
    async def set_leverage(self, symbol: str, leverage: int) -> None: ...
    async def place_limit(self, symbol: str, side: str, qty: Decimal, price: Decimal,
                          link_id: str, reduce_only: bool = False) -> None: ...
    async def place_market(self, symbol: str, side: str, qty: Decimal, stop: Optional[Decimal] = None,
                           take: Optional[Decimal] = None, reduce_only: bool = False) -> None: ...
    async def cancel(self, symbol: str, link_id: str) -> None: ...
    async def cancel_all(self, symbol: str) -> None: ...
    async def open_order_ids(self, symbol: str) -> Set[str]: ...
    async def order_result(self, symbol: str, link_id: str) -> OrderResult: ...
    async def closed_pnl(self, symbol: str, since_ms: int) -> List[ClosedPnl]: ...
    async def close_position(self, symbol: str) -> Optional[Tuple[float, float]]: ...
