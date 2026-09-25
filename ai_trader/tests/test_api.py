import asyncio
from datetime import timedelta

from fastapi.testclient import TestClient

from app.db import Session, Trade, utcnow
from app.main import app


def test_full_user_flow():
    with TestClient(app) as c:
        me = c.get("/api/me").json()
        assert me["user"]["id"] == 777 and me["settings"]["trading_mode"] == "paper"
        assert c.put("/api/settings", json={"risk_profile": "aggressive", "symbols": ["BTCUSDT", "FAKE"]}).status_code == 200
        me = c.get("/api/me").json()
        assert me["settings"]["risk_profile"] == "aggressive" and me["settings"]["symbols"] == ["BTCUSDT"]
        # биржа без подключения и подписки недоступна
        assert c.put("/api/settings", json={"trading_mode": "exchange"}).status_code == 400
        assert c.put("/api/settings", json={"strategies": {"grid": False}}).status_code == 400
        assert c.post("/api/bot/start").status_code == 200
        assert c.get("/api/me").json()["settings"]["running"] is True

        async def seed():
            async with Session() as s:
                now = utcnow()
                for pnl, days in ((5.0, 0), (-2.0, 0), (3.5, 1)):
                    s.add(Trade(user_id=777, symbol="BTCUSDT", strategy="grid", side="Buy", qty=0.01,
                                entry=100, exit=101, pnl=pnl, r=0.2, regime="range", mode="paper",
                                opened_at=now, closed_at=now - timedelta(days=days)))
                await s.commit()
        asyncio.run(seed())

        d = c.get("/api/dashboard").json()
        assert d["today"]["trades"] == 2 and d["today"]["pnl"] == 3.0 and d["week"]["pnl"] == 6.5
        month = utcnow().strftime("%Y-%m")
        cal = c.get(f"/api/calendar?month={month}").json()
        assert cal["total"]["trades"] >= 2
        assert c.get("/api/calendar?month=bad").status_code == 400
        today = utcnow().date().isoformat()
        assert len(c.get(f"/api/day?d={today}").json()["trades"]) == 2
        assert len(c.get("/api/trades").json()) == 3
        assert c.get("/api/market").status_code == 200
        assert c.post("/api/pay", json={"plan": "month"}).status_code == 503   # бот не запущен в тестах
        assert c.get("/app/").status_code == 200


def test_requires_valid_telegram_signature(monkeypatch):
    from dataclasses import replace

    from app import api
    monkeypatch.setattr(api, "settings", replace(api.settings, dev_user_id=0, bot_token="123:ABC"))
    with TestClient(app) as c:
        assert c.get("/api/me").status_code == 401
        assert c.get("/api/me", headers={"X-Init-Data": "user=%7B%22id%22%3A1%7D&hash=bad"}).status_code == 401
