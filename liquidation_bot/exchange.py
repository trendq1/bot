"""Работа с Bybit API v5 (pybit) и бумажный брокер для режима paper."""
import logging
import time
from dataclasses import dataclass
from decimal import Decimal
from typing import Dict, List

from pybit.exceptions import InvalidRequestError
from pybit.unified_trading import HTTP

from strategy import Instrument, fmt, round_price

log = logging.getLogger("bot")


@dataclass
class Position:
    symbol: str
    side: str      # "Buy" / "Sell"
    qty: float
    entry: float


@dataclass
class ClosedTrade:
    symbol: str
    pnl: float
    exit_price: float


class MarketData:
    """Публичные данные: инструменты и свечи. Ключи не нужны."""

    def __init__(self, testnet: bool):
        self.http = HTTP(testnet=testnet)

    def instruments(self, symbols: List[str]) -> Dict[str, Instrument]:
        result = {}
        for s in symbols:
            info = self.http.get_instruments_info(category="linear", symbol=s)["result"]["list"]
            if not info:
                log.warning("%s: инструмент не найден на Bybit, пропускаю", s)
                continue
            i = info[0]
            lot, price, lev = i["lotSizeFilter"], i["priceFilter"], i["leverageFilter"]
            result[s] = Instrument(
                symbol=s,
                tick_size=price["tickSize"],
                qty_step=lot["qtyStep"],
                min_qty=float(lot["minOrderQty"]),
                max_mkt_qty=float(lot.get("maxMktOrderQty") or lot["maxOrderQty"]),
                min_notional=float(lot.get("minNotionalValue") or 0),
                max_leverage=float(lev["maxLeverage"]),
            )
        return result

    def klines(self, symbol: str, interval: str, limit: int) -> list:
        return self.http.get_kline(category="linear", symbol=symbol, interval=interval, limit=limit)["result"]["list"]


def prices_for_order(side: str, stop: float, take: float, inst: Instrument):
    """Стоп округляем дальше от цены, тейк — ближе к цене."""
    long = side == "Buy"
    sl = round_price(stop, inst.tick_size, up=not long)
    tp = round_price(take, inst.tick_size, up=not long)
    return sl, tp


class BybitBroker:
    """Реальные ордера: demo (демо-счёт Bybit), testnet или live."""

    def __init__(self, mode: str, api_key: str, api_secret: str, instruments: Dict[str, Instrument]):
        self.http = HTTP(testnet=mode == "testnet", demo=mode == "demo", api_key=api_key, api_secret=api_secret)
        self.instruments = instruments

    def prepare(self, leverage: float) -> None:
        try:
            self.http.switch_position_mode(category="linear", coin="USDT", mode=0)
        except InvalidRequestError as e:
            log.info("Режим позиции: %s", e.message)
        for s, inst in self.instruments.items():
            lev = fmt(Decimal(str(min(leverage, inst.max_leverage))))
            try:
                self.http.set_leverage(category="linear", symbol=s, buyLeverage=lev, sellLeverage=lev)
            except InvalidRequestError as e:
                if e.status_code != 110043:  # 110043 = плечо уже такое
                    log.warning("%s: не удалось выставить плечо: %s", s, e.message)

    def equity(self) -> float:
        acc = self.http.get_wallet_balance(accountType="UNIFIED")["result"]["list"][0]
        return float(acc["totalEquity"])

    def positions(self) -> Dict[str, Position]:
        rows = self.http.get_positions(category="linear", settleCoin="USDT")["result"]["list"]
        out = {}
        for r in rows:
            if float(r["size"]) > 0:
                out[r["symbol"]] = Position(r["symbol"], r["side"], float(r["size"]), float(r["avgPrice"]))
        return out

    def open(self, side: str, symbol: str, qty: Decimal, stop: float, take: float) -> None:
        inst = self.instruments[symbol]
        sl, tp = prices_for_order(side, stop, take, inst)
        self.http.place_order(
            category="linear", symbol=symbol, side=side, orderType="Market", qty=fmt(qty),
            stopLoss=fmt(sl), takeProfit=fmt(tp), tpslMode="Full",
            slTriggerBy="LastPrice", tpTriggerBy="LastPrice", positionIdx=0,
        )

    def move_stop(self, symbol: str, side: str, price: float) -> None:
        sl = round_price(price, self.instruments[symbol].tick_size, up=side != "Buy")
        self.http.set_trading_stop(category="linear", symbol=symbol, stopLoss=fmt(sl), tpslMode="Full", positionIdx=0)

    def close(self, symbol: str, pos: Position) -> None:
        self.http.place_order(
            category="linear", symbol=symbol, side="Sell" if pos.side == "Buy" else "Buy",
            orderType="Market", qty=fmt(Decimal(str(pos.qty))), reduceOnly=True, positionIdx=0,
        )

    def poll_closed(self, prices: Dict[str, float], tracked: List[str]) -> List[ClosedTrade]:
        """Для позиций бота, которых больше нет на бирже, берёт итоговый PnL."""
        if not tracked:
            return []
        current = self.positions()
        closed = []
        for s in tracked:
            if s in current:
                continue
            pnl, exit_price = 0.0, prices.get(s, 0.0)
            for _ in range(3):
                rows = self.http.get_closed_pnl(category="linear", symbol=s, limit=1)["result"]["list"]
                if rows:
                    pnl, exit_price = float(rows[0]["closedPnl"]), float(rows[0]["avgExitPrice"])
                    break
                time.sleep(1)
            closed.append(ClosedTrade(s, pnl, exit_price))
        return closed


class PaperBroker:
    """Виртуальный счёт: сделки не отправляются на биржу, SL/TP проверяются по ценам потока."""

    def __init__(self, balance: float, fee_pct: float, instruments: Dict[str, Instrument]):
        self.balance = balance
        self.fee = fee_pct / 100
        self.instruments = instruments
        self.pos: Dict[str, dict] = {}
        self._closed: List[ClosedTrade] = []
        self._prices: Dict[str, float] = {}

    def prepare(self, leverage: float) -> None:
        pass

    def equity(self) -> float:
        unrealized = sum(
            (1 if p["side"] == "Buy" else -1) * (self._prices.get(s, p["entry"]) - p["entry"]) * p["qty"]
            for s, p in self.pos.items()
        )
        return self.balance + unrealized

    def positions(self) -> Dict[str, Position]:
        return {s: Position(s, p["side"], p["qty"], p["entry"]) for s, p in self.pos.items()}

    def open(self, side: str, symbol: str, qty: Decimal, stop: float, take: float) -> None:
        inst = self.instruments[symbol]
        sl, tp = prices_for_order(side, stop, take, inst)
        entry = self._prices[symbol]
        self.balance -= float(qty) * entry * self.fee
        self.pos[symbol] = {"side": side, "qty": float(qty), "entry": entry, "sl": float(sl), "tp": float(tp)}

    def move_stop(self, symbol: str, side: str, price: float) -> None:
        if symbol in self.pos:
            self.pos[symbol]["sl"] = price

    def close(self, symbol: str, pos: Position) -> None:
        self._exit(symbol, self._prices.get(symbol, pos.entry))

    def _exit(self, symbol: str, price: float) -> None:
        p = self.pos.pop(symbol)
        d = 1 if p["side"] == "Buy" else -1
        pnl = d * (price - p["entry"]) * p["qty"] - (p["entry"] + price) * p["qty"] * self.fee
        self.balance += pnl + p["entry"] * p["qty"] * self.fee  # вход. комиссию уже списали
        self._closed.append(ClosedTrade(symbol, pnl, price))

    def update_prices(self, prices: Dict[str, float]) -> None:
        self._prices.update(prices)
        for s in list(self.pos):
            p, price = self.pos[s], prices.get(s)
            if price is None:
                continue
            if p["side"] == "Buy":
                if price <= p["sl"]:
                    self._exit(s, p["sl"])
                elif price >= p["tp"]:
                    self._exit(s, p["tp"])
            else:
                if price >= p["sl"]:
                    self._exit(s, p["sl"])
                elif price <= p["tp"]:
                    self._exit(s, p["tp"])

    def poll_closed(self, prices: Dict[str, float], tracked: List[str]) -> List[ClosedTrade]:
        out, self._closed = self._closed, []
        return out
