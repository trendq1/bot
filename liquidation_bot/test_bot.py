"""Оффлайн-тесты логики: python -m unittest test_bot.py"""
import json
import os
import tempfile
import unittest
from decimal import Decimal
from unittest import mock

import strategy as st
from exchange import PaperBroker

INST = st.Instrument("BTCUSDT", tick_size="0.10", qty_step="0.001", min_qty=0.001,
                     max_mkt_qty=100, min_notional=5, max_leverage=100)


def cascade_state(now, usd=2_000_000, side="long", low=97_000.0, price=97_400.0, last_liq_ago=10):
    """VWAP 100000, ATR 1000: лонги ликвидировали, цена упала до low и отскочила до price."""
    s = st.SymbolState("BTCUSDT")
    s.vwap, s.atr = 100_000.0, 1_000.0
    s.add_liquidation(st.Liquidation(now - 30, side, usd / 2, low))
    s.add_liquidation(st.Liquidation(now - last_liq_ago, side, usd / 2, low))
    for i, px in enumerate([99_000.0, low, price]):
        s.add_price(now - 20 + i, px)
    return s


class StrategyTest(unittest.TestCase):
    p = st.StrategyParams()

    def test_long_after_long_liquidations(self):
        sig = st.evaluate(cascade_state(1000.0), 1000.0, self.p, 1_000_000)
        self.assertIsNotNone(sig)
        self.assertEqual(sig.side, "Buy")
        self.assertAlmostEqual(sig.stop, 97_000 - 500)          # экстремум - 0.5 ATR
        self.assertGreaterEqual(sig.rr, self.p.min_rr)
        self.assertLessEqual(sig.take, 100_000)                  # тейк не дальше VWAP

    def test_short_after_short_liquidations(self):
        s = st.SymbolState("BTCUSDT")
        s.vwap, s.atr = 100_000.0, 1_000.0
        s.add_liquidation(st.Liquidation(990, "short", 2_000_000, 103_000))
        for i, px in enumerate([101_000.0, 103_000.0, 102_600.0]):
            s.add_price(980 + i, px)
        sig = st.evaluate(s, 1000.0, self.p, 1_000_000)
        self.assertEqual(sig.side, "Sell")
        self.assertGreater(sig.stop, 103_000)

    def test_small_cascade_ignored(self):
        self.assertIsNone(st.evaluate(cascade_state(1000.0, usd=500_000), 1000.0, self.p, 1_000_000))

    def test_waits_until_cascade_settles(self):
        self.assertIsNone(st.evaluate(cascade_state(1000.0, last_liq_ago=2), 1000.0, self.p, 1_000_000))

    def test_needs_bounce(self):
        self.assertIsNone(st.evaluate(cascade_state(1000.0, price=97_050.0), 1000.0, self.p, 1_000_000))

    def test_needs_distance_from_vwap(self):
        s = cascade_state(1000.0, low=99_000.0, price=99_300.0)
        self.assertIsNone(st.evaluate(s, 1000.0, self.p, 1_000_000))

    def test_wrong_side_liquidations_do_not_trigger_long(self):
        self.assertIsNone(st.evaluate(cascade_state(1000.0, side="short"), 1000.0, self.p, 1_000_000))

    def test_cooldown(self):
        s = cascade_state(1000.0)
        s.cooldown_until = 2000
        self.assertIsNone(st.evaluate(s, 1000.0, self.p, 1_000_000))

    def test_old_liquidations_expire(self):
        s = cascade_state(1000.0)
        self.assertIsNone(st.evaluate(s, 1000.0 + 120, self.p, 1_000_000))


class MathTest(unittest.TestCase):
    def test_vwap_atr(self):
        # новые первыми: [start, open, high, low, close, volume, turnover]
        kl = [[str(i), "100", "102", "98", "100", "10", "1000"] for i in range(20)]
        vwap, atr = st.vwap_atr_from_klines(kl, vwap_bars=10)
        self.assertAlmostEqual(vwap, 100.0)
        self.assertAlmostEqual(atr, 4.0)

    def test_position_size_risk(self):
        qty, why = st.position_size(1000, 1.0, 100_000, 99_000, 10, INST)
        self.assertEqual(why, "")
        self.assertEqual(qty, Decimal("0.010"))   # 10 USDT риска / 1000 стоп = 0.01 BTC

    def test_position_size_leverage_cap(self):
        qty, _ = st.position_size(1000, 1.0, 100_000, 99_990, 5, INST)
        self.assertLessEqual(float(qty) * 100_000, 1000 * 5)

    def test_position_size_too_small(self):
        qty, why = st.position_size(10, 0.5, 100_000, 99_000, 5, INST)
        self.assertEqual(qty, 0)
        self.assertIn("меньше", why)

    def test_price_rounding(self):
        self.assertEqual(st.fmt(st.round_price(123.456, "0.10", up=False)), "123.4")
        self.assertEqual(st.fmt(st.round_price(123.456, "0.10", up=True)), "123.5")
        self.assertEqual(st.fmt(st.floor_step(0.12345, "0.001")), "0.123")


class PaperBrokerTest(unittest.TestCase):
    def test_stop_and_take(self):
        b = PaperBroker(1000, 0.0, {"BTCUSDT": INST})
        b.update_prices({"BTCUSDT": 100_000})
        b.open("Buy", "BTCUSDT", Decimal("0.01"), 99_000, 102_000)
        b.update_prices({"BTCUSDT": 102_500})
        closed = b.poll_closed({}, [])
        self.assertEqual(len(closed), 1)
        self.assertAlmostEqual(closed[0].pnl, 20.0)
        self.assertAlmostEqual(b.equity(), 1020.0)

        b.open("Sell", "BTCUSDT", Decimal("0.01"), 103_500, 100_000)
        b.update_prices({"BTCUSDT": 104_000})
        self.assertAlmostEqual(b.poll_closed({}, [])[0].pnl, -10.0)

    def test_fees(self):
        b = PaperBroker(1000, 0.1, {"BTCUSDT": INST})
        b.update_prices({"BTCUSDT": 100_000})
        b.open("Buy", "BTCUSDT", Decimal("0.01"), 99_000, 101_000)
        b.update_prices({"BTCUSDT": 101_000})
        pnl = b.poll_closed({}, [])[0].pnl
        self.assertAlmostEqual(pnl, 10.0 - (1000 + 1010) * 0.001)
        self.assertAlmostEqual(b.equity(), 1000 + pnl)


class BotSmokeTest(unittest.TestCase):
    """Полный цикл бота в режиме paper с подменённой биржей."""

    def test_signal_opens_paper_trade(self):
        import bot as botmod

        with open(os.path.join(os.path.dirname(__file__), "config.json"), encoding="utf-8") as f:
            cfg = json.load(f)
        cfg["symbols"] = ["BTCUSDT"]
        fake_market = mock.MagicMock()
        fake_market.instruments.return_value = {"BTCUSDT": INST}

        with tempfile.TemporaryDirectory() as tmp, \
                mock.patch.object(botmod, "MarketData", return_value=fake_market), \
                mock.patch.object(botmod, "HERE", tmp):
            b = botmod.Bot(cfg)
            now = 1000.0
            st_ = b.states["BTCUSDT"]
            st_.vwap, st_.atr, st_.indicators_ts = 100_000.0, 1_000.0, now
            with mock.patch.object(botmod.time, "time", return_value=now - 20):
                b.on_liquidation({"data": [{"T": 0, "s": "BTCUSDT", "S": "Buy", "v": "15", "p": "97000"}]})
            for i, px in enumerate(["99000", "97000", "97400"]):
                st_.add_price(now - 15 + i, float(px))
            b.equity = b.broker.equity()
            b.risk.update(b.equity)
            b.broker.update_prices({"BTCUSDT": 97_400.0})
            b.try_signals(now)

            self.assertIn("BTCUSDT", b.trades)
            pos = b.broker.positions()["BTCUSDT"]
            self.assertEqual(pos.side, "Buy")
            # риск 0.5% от 1000 = 5 USDT; стоп 96500 -> 900 пунктов -> ~0.005 BTC
            self.assertAlmostEqual(pos.qty, 0.005, places=3)
            self.assertTrue(os.path.exists(os.path.join(tmp, "trades.csv")))

            # цена дошла до тейка -> сделка закрыта и учтена
            b.broker.update_prices({"BTCUSDT": 100_000.0})
            b.process_closed(now + 60, {"BTCUSDT": 100_000.0})
            self.assertNotIn("BTCUSDT", b.trades)
            self.assertGreater(b.broker.equity(), 1000)


if __name__ == "__main__":
    unittest.main()
