import asyncio

from fastapi.testclient import TestClient

from app.db import AdminUser, AppSetting, Session, init_db
from app.main import app
from app.security import hash_password

H = {"X-Admin": "1"}


def make_admin():
    async def go():
        await init_db()
        async with Session() as s:
            if await s.get(AdminUser, 1) is None:
                s.add(AdminUser(id=1, username="boss", password_hash=hash_password("supersecret1")))
                await s.commit()
    asyncio.run(go())


def test_admin_requires_login():
    with TestClient(app) as c:
        assert c.get("/admin/api/overview").status_code == 401
        assert c.post("/admin/api/login", json={"username": "boss", "password": "wrong"}).status_code == 401


def test_admin_full_flow():
    make_admin()
    with TestClient(app) as c:
        assert c.get("/admin").status_code == 200
        r = c.post("/admin/api/login", json={"username": "boss", "password": "supersecret1"})
        assert r.status_code == 200
        # мутирующие запросы без X-Admin отклоняются (защита от CSRF)
        assert c.post("/admin/api/finance", json={"kind": "expense", "category": "Сервер", "amount_usd": 20}).status_code == 403

        c.get("/api/me")                                       # появляется клиент 777 (dev)
        ov = c.get("/admin/api/overview?days=30").json()
        assert ov["kpi"]["users_total"] >= 1 and len(ov["series"]) == 30

        users = c.get("/admin/api/users?q=777").json()
        assert users and users[0]["id"] == 777
        assert c.post("/admin/api/users/777/action", json={"action": "extend", "days": 10}, headers=H).status_code == 200
        detail = c.get("/admin/api/users/777").json()
        assert detail["user"]["has_sub"] is True

        async def seed():
            from app.db import Trade, User, utcnow
            async with Session() as s:
                s.add(User(id=888, first_name="Stats"))
                await s.flush()
                for pnl in (5.0, -2.0, 3.5):
                    s.add(Trade(user_id=888, symbol="BTCUSDT", strategy="grid", side="Buy", qty=0.01, entry=1,
                                exit=1, pnl=pnl, opened_at=utcnow(), closed_at=utcnow()))
                await s.commit()
        asyncio.run(seed())
        totals = c.get("/admin/api/users/888").json()["totals"]
        assert totals["trades"] == 3 and totals["wins"] == 2      # регрессия: SUM(bool) в MySQL
        assert c.post("/admin/api/users/777/action", json={"action": "block"}, headers=H).status_code == 200
        assert c.get("/api/me").status_code == 403            # заблокированный клиент не может войти
        assert c.post("/admin/api/users/777/action", json={"action": "unblock"}, headers=H).status_code == 200
        assert c.post("/admin/api/users/777/action", json={"action": "profile", "value": "nope"}, headers=H).status_code == 400

        # финансы
        assert c.post("/admin/api/finance", json={"kind": "expense", "category": "Сервер", "amount_usd": 20}, headers=H).status_code == 200
        assert c.post("/admin/api/finance", json={"kind": "income", "category": "Реферальные Bybit", "amount_usd": 55.5}, headers=H).status_code == 200
        fin = c.get("/admin/api/finance?days=30").json()
        assert fin["totals"]["expense_other"] == 20 and fin["totals"]["income_other"] == 55.5
        assert fin["totals"]["profit"] == 35.5

        # настройки: секрет шифруется в БД и возвращается маской
        r = c.put("/admin/api/settings", json={"anthropic_api_key": "sk-ant-test-1234", "ai_interval_min": 15,
                                               "require_referral": "false", "symbols": "btcusdt, ethusdt"}, headers=H)
        assert r.status_code == 200 and r.json()["restart_required"]
        fields = {f["key"]: f for f in c.get("/admin/api/settings").json()}
        assert fields["anthropic_api_key"]["value"].endswith("1234") and fields["anthropic_api_key"]["value"].startswith("•")
        assert fields["ai_interval_min"]["value"] == 15 and fields["require_referral"]["value"] is False
        assert fields["symbols"]["value"] == "BTCUSDT,ETHUSDT"

        async def stored():
            async with Session() as s:
                return (await s.get(AppSetting, "anthropic_api_key")).value
        assert "sk-ant" not in asyncio.run(stored())           # в базе — только шифр
        # маска при повторном сохранении не затирает ключ
        c.put("/admin/api/settings", json={"anthropic_api_key": fields["anthropic_api_key"]["value"]}, headers=H)
        from app.config import settings
        assert settings.anthropic_api_key == "sk-ant-test-1234"
        assert c.put("/admin/api/settings", json={"ai_interval_min": 1}, headers=H).status_code == 400

        # ИИ, журнал, экспорт, админы
        assert c.post("/admin/api/ai/lessons", json={"text": "Не запускать сетку в новостях"}, headers=H).status_code == 200
        assert any("новостях" in x["text"] for x in c.get("/admin/api/ai").json()["lessons"])
        assert c.put("/admin/api/ai/tuning/SOLUSDT", json={"grid_step_atr": 99}, headers=H).json()["params"]["grid_step_atr"] == 1.5
        assert c.get("/admin/api/logs").json()["audit"]
        csv = c.get("/admin/api/export/users.csv")
        assert csv.status_code == 200 and "username" in csv.text
        assert c.post("/admin/api/admins", json={"username": "helper", "password": "12345678"}, headers=H).status_code == 200
        assert c.post("/admin/api/admins/password", json={"old_password": "bad", "new_password": "newpass123"}, headers=H).status_code == 400
        assert c.post("/admin/api/broadcast", json={"text": "hi"}, headers=H).status_code == 400   # бот не запущен
        settings.anthropic_api_key = ""
