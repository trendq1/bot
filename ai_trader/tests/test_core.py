import asyncio
import hashlib
import hmac
import json
import time
from decimal import Decimal
from urllib.parse import urlencode

from app.engine.ai import Insight, apply_tuning, rule_insight
from app.engine.indicators import features
from app.engine.learner import multiplier
from app.engine.risk import RiskGuard, profile
from app.engine.strategies.directional import liquidation_setup, trend_setup
from app.engine.strategies.grid import Grid, plan_grid, worst_loss_per_qty
from app.exchange.base import Instrument
from app.exchange.market import SymbolFeed
from app.exchange.paper import PaperExchange
from app.security import decrypt, encrypt, validate_init_data

INST = Instrument("BTCUSDT", "0.1", "0.001", 0.001, 100, 5, 100)


def run(coro):
    return asyncio.run(coro)


# ───────── безопасность ─────────

def signed_init_data(token, user, auth_date=None):
    fields = {"auth_date": str(auth_date or int(time.time())), "query_id": "q1", "user": json.dumps(user)}
    check = "\n".join(f"{k}={v}" for k, v in sorted(fields.items()))
    secret = hmac.new(b"WebAppData", token.encode(), hashlib.sha256).digest()
    fields["hash"] = hmac.new(secret, check.encode(), hashlib.sha256).hexdigest()
    return urlencode(fields)


def test_init_data_valid_and_tampered():
    data = signed_init_data("123:ABC", {"id": 42, "first_name": "Artem"})
    assert validate_init_data(data, "123:ABC")["id"] == 42
    assert validate_init_data(data, "123:WRONG") is None
    assert validate_init_data(data.replace("42", "43"), "123:ABC") is None
    old = signed_init_data("123:ABC", {"id": 42}, auth_date=int(time.time()) - 200000)
    assert validate_init_data(old, "123:ABC") is None


def test_encryption_roundtrip():
    token = encrypt("secret-key")
    assert token != "secret-key" and decrypt(token) == "secret-key"


# ───────── бумажная биржа ─────────

def test_paper_limit_fill_and_pnl():
    ex = PaperExchange(1000)
    ex.update_prices({"BTCUSDT": 100_000})
    run(ex.place_limit("BTCUSDT", "Buy", Decimal("0.01"), Decimal("99000"), "a"))
    ex.update_prices({"BTCUSDT": 99_500})
    assert run(ex.positions()) == {}
    ex.update_prices({"BTCUSDT": 98_900})
    pos = run(ex.positions())["BTCUSDT"]
    assert pos.qty == 0.01 and pos.entry == 99_000
    run(ex.place_limit("BTCUSDT", "Sell", Decimal("0.01"), Decimal("100000"), "b", reduce_only=True))
    ex.update_prices({"BTCUSDT": 100_100})
    assert run(ex.positions()) == {}
    # +10 USDT минус maker-комиссии обеих сторон
    assert abs(ex.balance - (1000 + 10 - (990 + 1000) * 0.0002)) < 1e-6


def test_paper_market_stop():
    ex = PaperExchange(1000)
    ex.update_prices({"BTCUSDT": 100_000})
    run(ex.place_market("BTCUSDT", "Sell", Decimal("0.01"), stop=Decimal("101000"), take=Decimal("98000")))
    ex.update_prices({"BTCUSDT": 101_500})
    assert run(ex.positions()) == {}
    closed = run(ex.closed_pnl("BTCUSDT", 0))
    assert len(closed) == 1 and closed[0].pnl < -10


def test_reduce_only_does_not_open():
    ex = PaperExchange(1000)
    ex.update_prices({"BTCUSDT": 100_000})
    run(ex.place_limit("BTCUSDT", "Sell", Decimal("0.01"), Decimal("100500"), "x", reduce_only=True))
    ex.update_prices({"BTCUSDT": 101_000})
    assert run(ex.positions()) == {}
    assert run(ex.order_result("BTCUSDT", "x")).status == "Cancelled"


# ───────── сетка ─────────

def test_grid_plan_respects_max_loss():
    plan = plan_grid(5000, 100_000, 500, INST, "long", 0.6, 8, capital=750, leverage=5, max_loss=125)
    assert plan is not None
    assert plan.worst_loss() <= 125 + 1e-6
    assert plan.step_pct >= 0.2
    # маленький депозит: минимальный лот BTC не помещается в бюджет — сетка не строится
    assert plan_grid(1000, 100_000, 500, INST, "long", 0.6, 8, capital=150, leverage=5, max_loss=25) is None


def test_grid_full_cycle_and_stop():
    ex = PaperExchange(10_000)
    ex.update_prices({"BTCUSDT": 100_000})
    plan = plan_grid(10_000, 100_000, 500, INST, "long", 1.0, 4, capital=2000, leverage=5, max_loss=500)
    g = Grid(ex, "BTCUSDT", INST, plan)
    run(g.start())
    assert len(g.orders) == 4
    lvl1 = g.level_price(1)
    ex.update_prices({"BTCUSDT": lvl1 - 1})
    assert run(g.sync(lvl1 - 1)) == []
    assert 1 in g.inventory and any(o.kind == "close" for o in g.orders.values())
    up = lvl1 * (1 + plan.step_pct / 100) + 5
    ex.update_prices({"BTCUSDT": up})
    events = run(g.sync(up))
    assert len(events) == 1 and events[0].kind == "cycle" and events[0].pnl > 0
    assert not g.inventory and len([o for o in g.orders.values() if o.kind == "open"]) == 4
    # обвал ниже стопа -> позиция закрывается
    for i in range(1, 5):
        p = g.level_price(i) - 1
        ex.update_prices({"BTCUSDT": p})
        run(g.sync(p))
    crash = g.stop_price - 10
    ex.update_prices({"BTCUSDT": crash})
    events = run(g.sync(crash))
    assert events and events[0].kind == "stop" and events[0].pnl < 0
    assert not g.active and run(ex.positions()) == {}
    assert abs(events[0].pnl) <= 500 * 1.1   # убыток в пределах бюджета (+комиссии/проскальзывание)


def test_grid_drain_finishes_when_flat():
    ex = PaperExchange(10_000)
    ex.update_prices({"BTCUSDT": 100_000})
    g = Grid(ex, "BTCUSDT", INST, plan_grid(10_000, 100_000, 500, INST, "short", 1.0, 4, 2000, 5, 500))
    run(g.start())
    run(g.drain())
    assert not g.active and run(ex.open_order_ids("BTCUSDT")) == set()


def test_worst_loss_formula():
    assert abs(worst_loss_per_qty(100, 1.0, 2) - (1 * 1.5 + 1 * 2.5)) < 1e-9


# ───────── сигналы ─────────

def make_klines(n=300, start=100.0, drift=0.0):
    out, p = [], start
    for i in range(n):
        o = p
        p = p * (1 + drift) + (0.3 if i % 2 else -0.3)
        h, l = max(o, p) + 0.2, min(o, p) - 0.2
        out.append([str(i * 300_000), o, h, l, p, 10.0, 10.0 * p])
    return out


def test_features_and_rule_regime():
    f = features(make_klines(drift=0.002))
    assert f and f["ema50"] > f["ema200"]
    ins = rule_insight(f)
    assert ins.regime in ("trend_up", "high_volatility", "range")
    flat = features(make_klines(drift=0.0))
    assert rule_insight(flat).regime == "range"


def test_trend_setup_long():
    f = {"atr": 1.0, "price": 101.0, "ema20": 100.5, "ema20_1": 100.4, "low_5": 100.3, "c1": 100.9, "o1": 100.5,
         "h2": 100.8, "l2": 100.0, "rsi": 58, "low_10": 99.5, "high_10": 102, "high_5": 101.5}
    s = trend_setup(f, "trend_up", 1.2)
    assert s and s.side == "Buy" and s.stop < 99.5 and abs((s.take - s.entry) / s.risk - 1.2) < 1e-9
    assert trend_setup(f, "range", 1.2) is None


def test_liquidation_setup():
    now = 1000.0
    feed = SymbolFeed("BTCUSDT")
    feed.price = 97_400
    feed.liqs.extend([(now - 30, "long", 700_000), (now - 20, "long", 600_000)])
    feed.prices.extend([(now - 40, 99_000), (now - 25, 97_000), (now - 1, 97_400)])
    f = {"atr": 1000.0, "price": 97_400, "vwap_4h": 100_000}
    s = liquidation_setup(feed, f, now=now)
    assert s and s.side == "Buy" and s.stop < 97_000 and s.take <= 100_000
    feed.liqs.append((now - 1, "long", 10_000))          # каскад ещё идёт
    assert liquidation_setup(feed, f, now=now) is None


# ───────── обучение и риск ─────────

def test_learner_multiplier_bounds():
    assert multiplier(0.0, 50) == 1.0
    assert multiplier(-5, 50) == 0.4 and multiplier(5, 50) == 1.6
    assert 1.0 < multiplier(0.5, 2) < 1.5          # мало сделок — осторожнее


def test_risk_guard_daily_limit_and_pause():
    g = RiskGuard(profile("balanced"))
    g.update_equity(1000)
    assert g.allowed(990, 0)[0]
    assert not g.allowed(965, 0)[0]
    for _ in range(3):
        assert not g.on_trade(-1, 0)
    assert g.on_trade(-1, 0) and not g.allowed(1000, 10)[0]


def test_ai_clamp_and_tuning():
    ins = Insight.clamp({"regime": "moon", "w_grid": 5, "risk_mult": 9, "grid_mode": "x", "grid_step_atr": 0.01}, "ai")
    assert ins.regime == "range" and ins.w_grid == 1 and ins.risk_mult == 1.2 and ins.grid_mode == "off"
    assert ins.grid_step_atr == 0.3
    t = apply_tuning({"grid_step_atr": 0.6}, "grid_step_atr", 1.5)
    assert t["grid_step_atr"] == 0.72                # не больше +20% за раз
    assert apply_tuning({}, "unknown", 1) == {}
