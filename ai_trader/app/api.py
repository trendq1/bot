"""HTTP API для Telegram Mini App. Авторизация — подпись Telegram initData."""
from datetime import date, datetime
from typing import Dict, List, Literal, Optional

from fastapi import APIRouter, Depends, Header, HTTPException, Request
from pydantic import BaseModel, Field
from sqlalchemy import select

from .config import settings
from .db import AIInsight, BotSettings, ExchangeAccount, Lesson, Session, User
from .engine.risk import PROFILES
from .exchange.bybit import KeyCheckError, verify_keys
from .security import encrypt, validate_init_data
from . import stats

router = APIRouter(prefix="/api")


# ───────────── авторизация ─────────────

async def current_user(x_init_data: str = Header(default="")) -> User:
    tg = validate_init_data(x_init_data, settings.bot_token)
    if tg is None and settings.dev_user_id and not settings.bot_token:   # только локальная отладка
        tg = {"id": settings.dev_user_id, "first_name": "Dev"}
    if tg is None:
        raise HTTPException(401, "Откройте приложение из Telegram")
    return await ensure_user(int(tg["id"]), tg.get("username"), tg.get("first_name"))


async def ensure_user(uid: int, username: Optional[str], first_name: Optional[str]) -> User:
    async with Session() as s:
        user = await s.get(User, uid)
        if user is None:
            user = User(id=uid, username=username, first_name=first_name)
            s.add(user)
            s.add(BotSettings(user_id=uid, symbols=list(settings.default_symbols),
                              paper_balance=settings.paper_start_balance,
                              strategies={"grid": True, "trend": True, "liquidation": True}))
            await s.commit()
        return user


def manager(request: Request):
    return request.app.state.manager


# ───────────── профиль ─────────────

@router.get("/me")
async def me(request: Request, user: User = Depends(current_user)):
    async with Session() as s:
        bs = await s.get(BotSettings, user.id)
        acc = await s.get(ExchangeAccount, user.id)
    mgr = manager(request)
    return {
        "user": {"id": user.id, "name": user.first_name or user.username or "",
                 "subscription_until": user.sub_until.isoformat() if user.sub_until else None,
                 "has_subscription": user.has_subscription()},
        "exchange": None if acc is None else {"mode": acc.mode, "uid": acc.bybit_uid, "referral_ok": acc.referral_ok},
        "settings": {"running": bs.running, "trading_mode": bs.trading_mode, "risk_profile": bs.risk_profile,
                     "symbols": bs.symbols, "strategies": bs.strategies, "paper_balance": round(bs.paper_balance, 2)},
        "status": (mgr.status(user.id) if mgr else None) or bs.status_text or ("остановлен" if not bs.running else "запуск"),
        "live": mgr.open_state(user.id) if mgr else {},
        "options": {
            "symbols": settings.symbols,
            "profiles": [{"code": p.code, "title": p.title, "risk_pct": p.risk_pct, "leverage": p.leverage,
                          "daily_loss_pct": p.daily_loss_pct, "grid_levels": p.grid_levels} for p in PROFILES.values()],
            "plans": [{"code": p.code, "title": p.title, "days": p.days, "stars": p.stars} for p in settings.plans],
            "referral_link": settings.referral_link,
        },
    }


# ───────────── биржа ─────────────

class ExchangeIn(BaseModel):
    api_key: str = Field(min_length=10, max_length=64)
    api_secret: str = Field(min_length=10, max_length=128)
    mode: Literal["demo", "live"] = "demo"


@router.post("/exchange")
async def connect_exchange(body: ExchangeIn, request: Request, user: User = Depends(current_user)):
    try:
        info = await verify_keys(body.api_key.strip(), body.api_secret.strip(), body.mode)
    except KeyCheckError as e:
        raise HTTPException(400, str(e))
    async with Session() as s:
        acc = await s.get(ExchangeAccount, user.id) or ExchangeAccount(user_id=user.id)
        acc.api_key_enc, acc.api_secret_enc = encrypt(body.api_key.strip()), encrypt(body.api_secret.strip())
        acc.mode, acc.bybit_uid, acc.referral_ok, acc.last_error = body.mode, info["uid"], info["referral_ok"], None
        s.add(acc)
        await s.commit()
    await _restart(request, user.id)
    return {"ok": True, "uid": info["uid"]}


@router.delete("/exchange")
async def disconnect_exchange(request: Request, user: User = Depends(current_user)):
    async with Session() as s:
        bs = await s.get(BotSettings, user.id)
        if bs.trading_mode == "exchange":
            bs.running, bs.trading_mode = False, "paper"
        acc = await s.get(ExchangeAccount, user.id)
        if acc:
            await s.delete(acc)
        await s.commit()
    await _restart(request, user.id)
    return {"ok": True}


# ───────────── настройки и запуск ─────────────

class SettingsIn(BaseModel):
    trading_mode: Optional[Literal["paper", "exchange"]] = None
    risk_profile: Optional[Literal["conservative", "balanced", "aggressive"]] = None
    symbols: Optional[List[str]] = None
    strategies: Optional[Dict[str, bool]] = None


@router.put("/settings")
async def update_settings(body: SettingsIn, request: Request, user: User = Depends(current_user)):
    async with Session() as s:
        bs = await s.get(BotSettings, user.id)
        if body.trading_mode == "exchange":
            if await s.get(ExchangeAccount, user.id) is None:
                raise HTTPException(400, "Сначала подключите Bybit")
            if not user.has_subscription():
                raise HTTPException(402, "Торговля на бирже доступна по подписке")
        if body.trading_mode:
            bs.trading_mode = body.trading_mode
        if body.risk_profile:
            bs.risk_profile = body.risk_profile
        if body.symbols is not None:
            syms = [x for x in body.symbols if x in settings.symbols][:8]
            if not syms:
                raise HTTPException(400, "Выберите хотя бы одну монету")
            bs.symbols = syms
        if body.strategies is not None:
            st = {k: bool(body.strategies.get(k, False)) for k in ("grid", "trend", "liquidation")}
            if not any(st.values()):
                raise HTTPException(400, "Включите хотя бы одну стратегию")
            bs.strategies = st
        await s.commit()
    await _restart(request, user.id)
    return {"ok": True}


@router.post("/bot/{action}")
async def bot_action(action: Literal["start", "stop"], request: Request, user: User = Depends(current_user)):
    async with Session() as s:
        bs = await s.get(BotSettings, user.id)
        if action == "start" and bs.trading_mode == "exchange" and not user.has_subscription():
            raise HTTPException(402, "Подписка закончилась — продлите её, чтобы торговать на бирже")
        bs.running = action == "start"
        bs.status_text = None
        await s.commit()
    await _restart(request, user.id)
    return {"ok": True}


@router.post("/paper/reset")
async def paper_reset(request: Request, user: User = Depends(current_user)):
    async with Session() as s:
        bs = await s.get(BotSettings, user.id)
        bs.paper_balance = settings.paper_start_balance
        await s.commit()
    await _restart(request, user.id)
    return {"ok": True}


async def _restart(request: Request, uid: int) -> None:
    mgr = manager(request)
    if mgr:
        await mgr.restart_worker(uid)


# ───────────── статистика ─────────────

@router.get("/dashboard")
async def get_dashboard(tz: int = 0, user: User = Depends(current_user)):
    return await stats.dashboard(user.id, tz)


@router.get("/calendar")
async def get_calendar(month: str, tz: int = 0, user: User = Depends(current_user)):
    try:
        y, m = (int(x) for x in month.split("-"))
        datetime(y, m, 1)
    except ValueError:
        raise HTTPException(400, "month=YYYY-MM")
    return await stats.calendar(user.id, y, m, tz)


@router.get("/day")
async def get_day(d: date, tz: int = 0, user: User = Depends(current_user)):
    return {"date": d.isoformat(), "trades": await stats.day_trades(user.id, d, tz)}


@router.get("/trades")
async def get_trades(tz: int = 0, user: User = Depends(current_user)):
    return await stats.recent_trades(user.id, tz)


@router.get("/market")
async def get_market(request: Request, user: User = Depends(current_user)):
    mgr = manager(request)
    async with Session() as s:
        lessons = [l.text for l in (await s.execute(select(Lesson).order_by(Lesson.id.desc()).limit(5))).scalars()]
        if mgr is None:
            rows = (await s.execute(select(AIInsight).order_by(AIInsight.id.desc()).limit(20))).scalars().all()
            seen, market = set(), []
            for r in rows:
                if r.symbol not in seen:
                    seen.add(r.symbol)
                    market.append({"symbol": r.symbol, **r.payload})
        else:
            market = mgr.market_view()
    return {"market": market, "lessons": lessons, "ai_enabled": bool(settings.anthropic_api_key)}


# ───────────── оплата ─────────────

class PayIn(BaseModel):
    plan: str


@router.post("/pay")
async def create_invoice(body: PayIn, request: Request, user: User = Depends(current_user)):
    bot = getattr(request.app.state, "bot", None)
    if bot is None:
        raise HTTPException(503, "Оплата временно недоступна")
    try:
        plan = settings.plan(body.plan)
    except KeyError:
        raise HTTPException(400, "Неизвестный тариф")
    from aiogram.types import LabeledPrice
    link = await bot.create_invoice_link(
        title=f"Аренда AI-бота · {plan.title}",
        description="Автоторговля фьючерсами Bybit: сетка + тренд + ликвидации под управлением ИИ",
        payload=f"sub:{plan.code}:{user.id}", currency="XTR",
        prices=[LabeledPrice(label=plan.title, amount=plan.stars)])
    return {"link": link}
