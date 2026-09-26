"""Общий поток рыночных данных для всех клиентов: цены, свечи, ликвидации.

Одна подписка на символ обслуживает всех пользователей, поэтому нагрузка
и стоимость ИИ-анализа не растут с числом клиентов.
"""
import asyncio
import logging
import time
from collections import deque
from typing import Deque, Dict, List, Optional, Tuple

from pybit.unified_trading import HTTP, WebSocket

from .base import Instrument

log = logging.getLogger("market")


class SymbolFeed:
    def __init__(self, symbol: str):
        self.symbol = symbol
        self.price: Optional[float] = None
        self.funding: Optional[float] = None
        self.klines: List[list] = []                 # старые -> новые: [ts, o, h, l, c, v, turnover]
        self.klines_ts = 0.0
        self.prices: Deque[Tuple[float, float]] = deque(maxlen=3000)
        self.liqs: Deque[Tuple[float, str, float]] = deque(maxlen=5000)   # (ts, "long"|"short", usd)

    def liq_sum(self, side: str, window_sec: float, now: Optional[float] = None) -> float:
        now = now or time.time()
        return sum(u for t, s, u in self.liqs if s == side and now - t <= window_sec)

    def last_liq_ago(self, side: str, now: Optional[float] = None) -> float:
        now = now or time.time()
        for t, s, _ in reversed(self.liqs):
            if s == side:
                return now - t
        return 1e9

    def price_extreme(self, window_sec: float, low: bool, now: Optional[float] = None) -> Optional[float]:
        now = now or time.time()
        vals = [p for t, p in self.prices if now - t <= window_sec]
        if not vals:
            return self.price
        return min(vals) if low else max(vals)


class MarketHub:
    def __init__(self, symbols: List[str]):
        self.symbols = symbols
        self.feeds: Dict[str, SymbolFeed] = {s: SymbolFeed(s) for s in symbols}
        self.instruments: Dict[str, Instrument] = {}
        self.http = HTTP()
        self._ws: Optional[WebSocket] = None
        self._loop: Optional[asyncio.AbstractEventLoop] = None

    # --- загрузка ---

    async def load_instruments(self) -> None:
        for s in self.symbols:
            try:
                r = await asyncio.to_thread(self.http.get_instruments_info, category="linear", symbol=s)
            except Exception as e:  # noqa: BLE001
                log.warning("%s: инструмент не загрузился: %s", s, e)
                continue
            rows = r["result"]["list"]
            if not rows:
                continue
            i = rows[0]
            lot, pf, lev = i["lotSizeFilter"], i["priceFilter"], i["leverageFilter"]
            self.instruments[s] = Instrument(
                s, pf["tickSize"], lot["qtyStep"], float(lot["minOrderQty"]),
                float(lot.get("maxMktOrderQty") or lot["maxOrderQty"]),
                float(lot.get("minNotionalValue") or 0), float(lev["maxLeverage"]))

    async def refresh_klines(self, symbol: str) -> None:
        r = await asyncio.to_thread(self.http.get_kline, category="linear", symbol=symbol, interval="5", limit=300)
        feed = self.feeds[symbol]
        feed.klines = list(reversed(r["result"]["list"]))
        feed.klines_ts = time.time()

    # --- WebSocket (колбэки приходят из потока pybit) ---

    def _on_ticker(self, msg: dict) -> None:
        d = msg.get("data", {})
        feed = self.feeds.get(d.get("symbol"))
        if feed is None:
            return
        if d.get("lastPrice"):
            feed.price = float(d["lastPrice"])
            feed.prices.append((time.time(), feed.price))
        if d.get("fundingRate"):
            feed.funding = float(d["fundingRate"])

    def _on_liq(self, msg: dict) -> None:
        now = time.time()
        for d in msg.get("data", []):
            feed = self.feeds.get(d.get("s"))
            if feed is not None:
                side = "long" if d["S"] == "Buy" else "short"     # Buy = ликвидирован лонг
                feed.liqs.append((now, side, float(d["v"]) * float(d["p"])))

    def start_streams(self) -> None:
        """Блокирующее подключение pybit — вызывать через asyncio.to_thread."""
        ws = WebSocket(testnet=False, channel_type="linear", retries=3)
        for i in range(0, len(self.symbols), 10):
            chunk = self.symbols[i:i + 10]
            ws.ticker_stream(chunk, self._on_ticker)
            ws.all_liquidation_stream(chunk, self._on_liq)
        self._ws = ws

    def ws_connected(self) -> bool:
        try:
            return self._ws is not None and self._ws.is_connected()
        except Exception:  # noqa: BLE001
            return False

    async def _ensure_streams(self) -> None:
        if self.ws_connected():
            return
        try:
            if self._ws is not None:
                await asyncio.to_thread(self._ws.exit)
        except Exception:  # noqa: BLE001
            pass
        self._ws = None
        try:
            await asyncio.to_thread(self.start_streams)
            log.info("WebSocket Bybit подключён")
        except Exception as e:  # noqa: BLE001
            log.warning("WebSocket Bybit недоступен (%s) — цены берём через REST, повтор через минуту", e)

    async def _poll_prices(self) -> None:
        """Запасной канал цен, пока WebSocket не работает."""
        try:
            r = await asyncio.to_thread(self.http.get_tickers, category="linear")
        except Exception as e:  # noqa: BLE001
            log.debug("tickers: %s", e)
            return
        now = time.time()
        for t in r["result"]["list"]:
            feed = self.feeds.get(t["symbol"])
            if feed is not None and t.get("lastPrice"):
                feed.price = float(t["lastPrice"])
                feed.prices.append((now, feed.price))
                if t.get("fundingRate"):
                    feed.funding = float(t["fundingRate"])

    async def run(self) -> None:
        """Инструменты, свечи раз в минуту, контроль WebSocket с переподключением."""
        ws_check = 0.0
        while True:
            if len(self.instruments) < len(self.symbols):
                await self.load_instruments()
            if time.time() - ws_check > 60:
                ws_check = time.time()
                await self._ensure_streams()
            if not self.ws_connected():
                await self._poll_prices()
            for s in self.symbols:
                if time.time() - self.feeds[s].klines_ts > 60:
                    try:
                        await self.refresh_klines(s)
                    except Exception as e:  # noqa: BLE001
                        self.feeds[s].klines_ts = time.time() - 30     # не долбим API при сбое
                        log.warning("%s: свечи не загрузились: %s", s, e)
            await asyncio.sleep(5)

    def prices(self) -> Dict[str, float]:
        return {s: f.price for s, f in self.feeds.items() if f.price}
