"""Liquidation Reversal Bot для Bybit USDT Perpetual (API v5).

Стратегия: после каскада ликвидаций цена уходит далеко от VWAP -> ждём, пока каскад
затихнет и появится отскок -> входим в обратную сторону со стопом за экстремумом и
тейком к VWAP. Подробности — в README.md.

Запуск: python bot.py            (режим берётся из config.json)
"""
import csv
import json
import logging
import os
import sys
import threading
import time
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Dict

from pybit.exceptions import FailedRequestError, InvalidRequestError
from pybit.unified_trading import WebSocket

from exchange import BybitBroker, MarketData, PaperBroker
from strategy import Liquidation, StrategyParams, SymbolState, evaluate, position_size, vwap_atr_from_klines

HERE = os.path.dirname(os.path.abspath(__file__))
log = logging.getLogger("bot")


# ───────────── Настройка ─────────────

def load_config(path: str) -> dict:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def setup_logging() -> None:
    fmt = logging.Formatter("%(asctime)s %(levelname)s %(message)s", "%Y-%m-%d %H:%M:%S")
    log.setLevel(logging.INFO)
    for h in (logging.StreamHandler(sys.stdout), logging.FileHandler(os.path.join(HERE, "bot.log"), encoding="utf-8")):
        h.setFormatter(fmt)
        log.addHandler(h)


def notify(text: str) -> None:
    """Уведомление в Telegram, если заданы TELEGRAM_BOT_TOKEN и TELEGRAM_CHAT_ID."""
    token, chat = os.getenv("TELEGRAM_BOT_TOKEN"), os.getenv("TELEGRAM_CHAT_ID")
    if not token or not chat:
        return
    try:
        data = urllib.parse.urlencode({"chat_id": chat, "text": text}).encode()
        urllib.request.urlopen(f"https://api.telegram.org/bot{token}/sendMessage", data=data, timeout=10)
    except Exception as e:  # уведомления не должны ронять бота
        log.warning("Telegram: %s", e)


def journal(row: dict) -> None:
    path = os.path.join(HERE, "trades.csv")
    new = not os.path.exists(path)
    with open(path, "a", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["time", "event", "symbol", "side", "qty", "price", "stop", "take", "pnl", "note"])
        if new:
            w.writeheader()
        w.writerow({"time": datetime.now(timezone.utc).isoformat(timespec="seconds"), **row})


# ───────────── Риск-менеджмент ─────────────

class RiskManager:
    def __init__(self, cfg: dict):
        self.cfg = cfg
        self.day = None
        self.day_start_equity = 0.0
        self.trades_today = 0
        self.consec_losses = 0
        self.paused_until = 0.0

    def update(self, equity: float) -> None:
        today = datetime.now(timezone.utc).date()
        if today != self.day:
            self.day, self.day_start_equity, self.trades_today = today, equity, 0
            log.info("Новый торговый день (UTC). Депозит на начало дня: %.2f USDT", equity)

    def can_trade(self, now: float, equity: float, open_count: int):
        c = self.cfg
        if now < self.paused_until:
            return False, f"пауза после {c['max_consecutive_losses']} убытков подряд"
        if self.day_start_equity > 0:
            loss_pct = (self.day_start_equity - equity) / self.day_start_equity * 100
            if loss_pct >= c["daily_loss_limit_pct"]:
                return False, f"дневной лимит убытка {c['daily_loss_limit_pct']}% достигнут"
        if self.trades_today >= c["max_trades_per_day"]:
            return False, "лимит сделок на день"
        if open_count >= c["max_open_positions"]:
            return False, "максимум открытых позиций"
        return True, ""

    def on_open(self) -> None:
        self.trades_today += 1

    def on_close(self, pnl: float, now: float) -> None:
        self.consec_losses = self.consec_losses + 1 if pnl < 0 else 0
        if self.consec_losses >= self.cfg["max_consecutive_losses"]:
            self.paused_until = now + self.cfg["pause_hours"] * 3600
            self.consec_losses = 0
            log.warning("%d убытков подряд — пауза %s ч", self.cfg["max_consecutive_losses"], self.cfg["pause_hours"])
            notify(f"⏸ Бот на паузе {self.cfg['pause_hours']} ч после серии убытков")


@dataclass
class OpenTrade:
    side: str
    entry: float
    stop: float
    take: float
    opened: float
    be_done: bool = False
    closing: bool = False


# ───────────── Бот ─────────────

class Bot:
    def __init__(self, cfg: dict):
        self.cfg = cfg
        self.mode = cfg["mode"]
        self.symbols = cfg["symbols"]
        self.params = StrategyParams(**cfg["strategy"])
        self.risk = RiskManager(cfg["risk"])
        self.lock = threading.Lock()
        self.states: Dict[str, SymbolState] = {s: SymbolState(s) for s in self.symbols}
        self.trades: Dict[str, OpenTrade] = {}
        self.equity = 0.0

        self.market = MarketData(testnet=self.mode == "testnet")
        self.instruments = self.market.instruments(self.symbols)
        self.symbols = [s for s in self.symbols if s in self.instruments]

        if self.mode == "paper":
            self.broker = PaperBroker(cfg["paper"]["balance"], cfg["paper"]["fee_pct"], self.instruments)
        else:
            key, secret = os.getenv("BYBIT_API_KEY"), os.getenv("BYBIT_API_SECRET")
            if not key or not secret:
                sys.exit("Не заданы переменные окружения BYBIT_API_KEY и BYBIT_API_SECRET")
            self.broker = BybitBroker(self.mode, key, secret, self.instruments)
        self.broker.prepare(cfg["risk"]["max_leverage"])

    # --- потоки данных (вызываются из потока WebSocket) ---

    def on_liquidation(self, msg: dict) -> None:
        received = time.time()  # локальное время: не зависим от расхождения часов с биржей
        with self.lock:
            for d in msg.get("data", []):
                st = self.states.get(d["s"])
                if st is None:
                    continue
                side = "long" if d["S"] == "Buy" else "short"   # Buy = ликвидирован лонг
                usd = float(d["v"]) * float(d["p"])
                st.add_liquidation(Liquidation(received, side, usd, float(d["p"])))
                if usd >= self.cfg["log_liquidations_from_usd"]:
                    log.info("Ликвидация %s %s на %.0f USDT", d["s"], "ЛОНГ" if side == "long" else "ШОРТ", usd)

    def on_ticker(self, msg: dict) -> None:
        d = msg.get("data", {})
        price = d.get("lastPrice")
        if price is None:
            return
        with self.lock:
            st = self.states.get(d.get("symbol"))
            if st is not None:
                st.add_price(time.time(), float(price))

    # --- основной цикл ---

    def refresh_indicators(self, now: float) -> None:
        interval = self.cfg["indicators"]["interval"]
        vwap_bars = self.cfg["indicators"]["vwap_bars"]
        due = [s for s in self.symbols if now - self.states[s].indicators_ts > 60][:5]
        for s in due:
            try:
                kl = self.market.klines(s, interval, max(vwap_bars, 15) + 1)
            except (FailedRequestError, InvalidRequestError, OSError) as e:
                log.warning("%s: свечи не загрузились: %s", s, e)
                continue
            vwap, atr = vwap_atr_from_klines(kl, vwap_bars)
            with self.lock:
                st = self.states[s]
                st.vwap, st.atr, st.indicators_ts = vwap, atr, now

    def manage_open(self, now: float, prices: Dict[str, float]) -> None:
        r = self.cfg["risk"]
        positions = self.broker.positions()
        for s, t in list(self.trades.items()):
            pos, price = positions.get(s), prices.get(s)
            if pos is None or price is None:
                continue
            d = 1 if t.side == "Buy" else -1
            risk = abs(t.entry - t.stop)
            if r["breakeven_at_r"] > 0 and not t.be_done and d * (price - t.entry) >= r["breakeven_at_r"] * risk:
                self.broker.move_stop(s, t.side, t.entry)
                t.be_done = True
                log.info("%s: стоп перенесён в безубыток %.6g", s, t.entry)
            if r["max_hold_minutes"] > 0 and not t.closing and now - t.opened > r["max_hold_minutes"] * 60:
                log.info("%s: вышло время удержания — закрываю по рынку", s)
                self.broker.close(s, pos)
                t.closing = True

    def process_closed(self, now: float, prices: Dict[str, float]) -> None:
        tracked = [s for s, t in self.trades.items() if now - t.opened > 3]
        for c in self.broker.poll_closed(prices, tracked):
            t = self.trades.pop(c.symbol, None)
            self.risk.on_close(c.pnl, now)
            log.info("Сделка %s закрыта: PnL %.2f USDT", c.symbol, c.pnl)
            journal({"event": "close", "symbol": c.symbol, "side": t.side if t else "", "price": c.exit_price, "pnl": round(c.pnl, 4)})
            notify(f"{'✅' if c.pnl >= 0 else '❌'} {c.symbol} закрыта: {c.pnl:+.2f} USDT")

    def try_signals(self, now: float) -> None:
        with self.lock:
            signals = []
            for s in self.symbols:
                min_usd = self.cfg["min_cascade_usd"].get(s, self.cfg["min_cascade_usd"]["default"])
                sig = evaluate(self.states[s], now, self.params, min_usd)
                if sig:
                    self.states[s].reset_cascade()
                    self.states[s].cooldown_until = now + self.cfg["risk"]["symbol_cooldown_minutes"] * 60
                    signals.append(sig)

        for sig in signals:
            log.info("СИГНАЛ %s %s: вход %.6g стоп %.6g тейк %.6g (RR %.1f, каскад %.0f USDT, VWAP %.6g)",
                     sig.symbol, sig.side, sig.entry, sig.stop, sig.take, sig.rr, sig.cascade_usd, sig.vwap)
            positions = self.broker.positions()
            if sig.symbol in positions:
                log.info("%s: позиция уже открыта — пропуск", sig.symbol)
                continue
            ok, reason = self.risk.can_trade(now, self.equity, len(positions))
            if not ok:
                log.info("Сигнал пропущен: %s", reason)
                continue
            r = self.cfg["risk"]
            inst = self.instruments[sig.symbol]
            qty, why = position_size(self.equity, r["risk_per_trade_pct"], sig.entry, sig.stop,
                                     min(r["max_leverage"], inst.max_leverage), inst)
            if qty <= 0:
                log.info("Сигнал пропущен: %s", why)
                continue
            try:
                self.broker.open(sig.side, sig.symbol, qty, sig.stop, sig.take)
            except (InvalidRequestError, FailedRequestError) as e:
                log.error("%s: ордер отклонён: %s", sig.symbol, e)
                continue
            self.trades[sig.symbol] = OpenTrade(sig.side, sig.entry, sig.stop, sig.take, now)
            self.risk.on_open()
            journal({"event": "open", "symbol": sig.symbol, "side": sig.side, "qty": str(qty), "price": sig.entry,
                     "stop": sig.stop, "take": sig.take, "note": f"каскад {sig.cascade_usd:.0f} USDT, RR {sig.rr:.1f}"})
            notify(f"{'🟢 LONG' if sig.side == 'Buy' else '🔴 SHORT'} {sig.symbol}\nВход {sig.entry:.6g}\n"
                   f"SL {sig.stop:.6g}\nTP {sig.take:.6g}\nОбъём {qty}\nКаскад {sig.cascade_usd:,.0f} USDT")

    def run(self) -> None:
        log.info("Старт в режиме %s. Символы: %s", self.mode.upper(), ", ".join(self.symbols))
        notify(f"🤖 Бот запущен ({self.mode})")
        # Публичные данные: для demo — основная сеть (у демо-счёта те же цены), для testnet — тестовая
        ws = WebSocket(testnet=self.mode == "testnet", channel_type="linear")
        for i in range(0, len(self.symbols), 10):
            chunk = self.symbols[i:i + 10]
            ws.all_liquidation_stream(chunk, self.on_liquidation)
            ws.ticker_stream(chunk, self.on_ticker)

        last_equity = last_sync = 0.0
        while True:
            now = time.time()
            try:
                self.refresh_indicators(now)
                with self.lock:
                    prices = {s: st.last_price for s, st in self.states.items() if st.last_price}
                if isinstance(self.broker, PaperBroker):
                    self.broker.update_prices(prices)
                if now - last_equity > 30:
                    self.equity = self.broker.equity()
                    self.risk.update(self.equity)
                    last_equity = now
                if now - last_sync > (1 if self.mode == "paper" else 5):
                    self.process_closed(now, prices)
                    self.manage_open(now, prices)
                    last_sync = now
                self.try_signals(now)
            except (InvalidRequestError, FailedRequestError, OSError) as e:
                log.error("Ошибка API: %s — повтор через 5 с", e)
                time.sleep(5)
            time.sleep(1)


def main() -> None:
    setup_logging()
    cfg = load_config(os.path.join(HERE, "config.json"))
    mode = cfg["mode"]
    if mode not in ("paper", "demo", "testnet", "live"):
        sys.exit(f"Неизвестный режим {mode}. Допустимо: paper, demo, testnet, live")
    if mode == "live" and not cfg.get("i_understand_live_risk"):
        sys.exit("Режим live заблокирован. Сначала протестируй в paper/demo, затем поставь "
                 "\"i_understand_live_risk\": true в config.json")
    try:
        Bot(cfg).run()
    except KeyboardInterrupt:
        log.info("Остановлено пользователем")


if __name__ == "__main__":
    main()
