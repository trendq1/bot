"""API браузерной админ-панели (/admin). Вход по логину и паролю, сессия в подписанной cookie."""
import asyncio
import csv
import io
import os
import time
from collections import defaultdict
from datetime import datetime, timedelta
from typing import Dict, List, Literal, Optional

from fastapi import APIRouter, Cookie, Depends, HTTPException, Query, Request, Response
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field
from sqlalchemy import case, delete, func, or_, select

from .config import BASE_DIR, FIELDS, FIELD_MAP, settings
from .db import (AdminUser, AIInsight, AIUsage, AuditLog, BotSettings, EquitySnapshot, ExchangeAccount,
                 FinanceEntry, Lesson, Payment, Session, StrategyStat, SymbolTuning, Trade, User,
                 save_app_settings, utcnow)
from .engine.ai import TUNABLE
from .engine.risk import PROFILES
from .security import check_password, hash_password, make_session_token, read_session_token

router = APIRouter(prefix="/admin/api")
COOKIE = "aitrader_admin"
LOG_FILE = os.path.join(BASE_DIR, "logs", "app.log")
RESTART_CODE = 3
_login_attempts: Dict[str, List[float]] = defaultdict(list)


# ───────────── авторизация ─────────────

async def current_admin(request: Request, aitrader_admin: Optional[str] = Cookie(default=None)) -> AdminUser:
    admin_id = read_session_token(aitrader_admin or "")
    if admin_id is None:
        raise HTTPException(401, "Требуется вход")
    # защита от CSRF: изменяющие запросы только из нашего JS с этим заголовком
    if request.method != "GET" and request.headers.get("X-Admin") != "1":
        raise HTTPException(403, "Некорректный запрос")
    async with Session() as s:
        admin = await s.get(AdminUser, admin_id)
    if admin is None:
        raise HTTPException(401, "Требуется вход")
    return admin


async def audit(actor: str, action: str, details: str = "") -> None:
    async with Session() as s:
        s.add(AuditLog(actor=actor, action=action, details=details[:2000]))
        await s.commit()


class LoginIn(BaseModel):
    username: str
    password: str


@router.post("/login")
async def login(body: LoginIn, request: Request, response: Response):
    ip = request.client.host if request.client else "?"
    now = time.time()
    _login_attempts[ip] = [t for t in _login_attempts[ip] if now - t < 900]
    if len(_login_attempts[ip]) >= 8:
        raise HTTPException(429, "Слишком много попыток. Подождите 15 минут.")
    async with Session() as s:
        admin = (await s.execute(select(AdminUser).where(AdminUser.username == body.username.strip()))).scalar_one_or_none()
        if admin is None or not check_password(body.password, admin.password_hash):
            _login_attempts[ip].append(now)
            raise HTTPException(401, "Неверный логин или пароль")
        admin.last_login = utcnow()
        await s.commit()
    secure = request.headers.get("x-forwarded-proto", request.url.scheme) == "https"
    response.set_cookie(COOKIE, make_session_token(admin.id), httponly=True, samesite="strict",
                        secure=secure, max_age=12 * 3600, path="/admin")
    await audit(admin.username, "login", ip)
    return {"ok": True, "username": admin.username}


@router.post("/logout")
async def logout(response: Response):
    response.delete_cookie(COOKIE, path="/admin")
    return {"ok": True}


@router.get("/me")
async def me(admin: AdminUser = Depends(current_admin)):
    return {"username": admin.username}


def mgr(request: Request):
    return request.app.state.manager


# ───────────── обзор и финансы ─────────────

async def finance_series(days: int) -> dict:
    """Доходы (подписки + ручные) и расходы (ИИ + ручные) по дням."""
    since = (utcnow() - timedelta(days=days - 1)).replace(hour=0, minute=0, second=0, microsecond=0)
    series: Dict[str, dict] = {}
    for i in range(days):
        d = (since + timedelta(days=i)).strftime("%Y-%m-%d")
        series[d] = {"date": d, "subs": 0.0, "income_other": 0.0, "ai": 0.0, "expense_other": 0.0,
                     "new_users": 0, "trades": 0, "clients_pnl": 0.0}
    async with Session() as s:
        for p in (await s.execute(select(Payment).where(Payment.created_at >= since))).scalars():
            series[p.created_at.strftime("%Y-%m-%d")]["subs"] += p.usd
        for e in (await s.execute(select(FinanceEntry).where(FinanceEntry.date >= since))).scalars():
            key = e.date.strftime("%Y-%m-%d")
            if key in series:
                series[key]["income_other" if e.kind == "income" else "expense_other"] += e.amount_usd
        for u in (await s.execute(select(AIUsage.ts, AIUsage.cost_usd).where(AIUsage.ts >= since))).all():
            series[u.ts.strftime("%Y-%m-%d")]["ai"] += u.cost_usd
        for u in (await s.execute(select(User.created_at).where(User.created_at >= since))).all():
            series[u.created_at.strftime("%Y-%m-%d")]["new_users"] += 1
        for t in (await s.execute(select(Trade.closed_at, Trade.pnl).where(Trade.closed_at >= since))).all():
            row = series[t.closed_at.strftime("%Y-%m-%d")]
            row["trades"] += 1
            row["clients_pnl"] += t.pnl
    rows = []
    for r in series.values():
        income, expense = r["subs"] + r["income_other"], r["ai"] + r["expense_other"]
        rows.append({**{k: round(v, 2) if isinstance(v, float) else v for k, v in r.items()},
                     "income": round(income, 2), "expense": round(expense, 2), "profit": round(income - expense, 2)})
    return {"rows": rows}


def _sum(rows: List[dict], key: str, last: Optional[int] = None) -> float:
    part = rows[-last:] if last else rows
    return round(sum(r[key] for r in part), 2)


@router.get("/overview")
async def overview(request: Request, days: int = Query(30, ge=7, le=365), admin: AdminUser = Depends(current_admin)):
    fin = await finance_series(days)
    rows = fin["rows"]
    now = utcnow()
    async with Session() as s:
        users_total = await s.scalar(select(func.count()).select_from(User))
        subs_active = await s.scalar(select(func.count()).select_from(User).where(User.sub_until > now))
        running = await s.scalar(select(func.count()).select_from(BotSettings).where(BotSettings.running.is_(True)))
        on_exchange = await s.scalar(select(func.count()).select_from(BotSettings).where(
            BotSettings.running.is_(True), BotSettings.trading_mode == "exchange"))
        connected = await s.scalar(select(func.count()).select_from(ExchangeAccount))
        referrals = await s.scalar(select(func.count()).select_from(ExchangeAccount).where(ExchangeAccount.referral_ok.is_(True)))
        revenue_all = await s.scalar(select(func.coalesce(func.sum(Payment.usd), 0.0)))
        stars_all = await s.scalar(select(func.coalesce(func.sum(Payment.stars), 0)))
        ai_all = await s.scalar(select(func.coalesce(func.sum(AIUsage.cost_usd), 0.0)))
        recent_pay = (await s.execute(select(Payment, User).join(User, User.id == Payment.user_id)
                                      .order_by(Payment.id.desc()).limit(8))).all()
        recent_audit = (await s.execute(select(AuditLog).order_by(AuditLog.id.desc()).limit(8))).scalars().all()
    m = mgr(request)
    return {
        "kpi": {
            "users_total": users_total, "users_new_7d": sum(r["new_users"] for r in rows[-7:]),
            "subs_active": subs_active, "bots_running": running, "bots_on_exchange": on_exchange,
            "exchange_connected": connected, "referrals": referrals,
            "income_today": _sum(rows, "income", 1), "income_period": _sum(rows, "income"),
            "expense_today": _sum(rows, "expense", 1), "expense_period": _sum(rows, "expense"),
            "profit_period": _sum(rows, "profit"), "ai_cost_period": _sum(rows, "ai"),
            "revenue_all": round(revenue_all, 2), "stars_all": stars_all, "ai_cost_all": round(ai_all, 2),
            "clients_pnl_period": _sum(rows, "clients_pnl"), "trades_period": sum(r["trades"] for r in rows),
        },
        "series": rows,
        "engine": m.engine_state() if m else None,
        "recent_payments": [{"user": u.first_name or u.username or u.id, "user_id": u.id, "plan": p.plan,
                             "stars": p.stars, "usd": p.usd, "at": p.created_at.isoformat()} for p, u in recent_pay],
        "recent_audit": [{"actor": a.actor, "action": a.action, "details": a.details, "at": a.ts.isoformat()}
                         for a in recent_audit],
    }


# ───────────── клиенты ─────────────

def user_row(u: User, bs: Optional[BotSettings], acc: Optional[ExchangeAccount], pnl30: float, trades30: int, paid: float) -> dict:
    return {"id": u.id, "username": u.username, "name": u.first_name, "created_at": u.created_at.isoformat(),
            "last_seen": u.last_seen.isoformat() if u.last_seen else None,
            "sub_until": u.sub_until.isoformat() if u.sub_until else None, "has_sub": u.has_subscription(),
            "blocked": u.blocked, "running": bool(bs and bs.running),
            "mode": bs.trading_mode if bs else None, "profile": bs.risk_profile if bs else None,
            "exchange": acc.mode if acc else None, "referral": bool(acc and acc.referral_ok),
            "pnl_30d": round(pnl30, 2), "trades_30d": trades30, "paid_usd": round(paid, 2)}


@router.get("/users")
async def users(q: str = "", filter: str = "all", admin: AdminUser = Depends(current_admin)):
    since = utcnow() - timedelta(days=30)
    async with Session() as s:
        stmt = (select(User, BotSettings, ExchangeAccount)
                .outerjoin(BotSettings, BotSettings.user_id == User.id)
                .outerjoin(ExchangeAccount, ExchangeAccount.user_id == User.id)
                .order_by(User.created_at.desc()).limit(500))
        if q.strip():
            like = f"%{q.strip()}%"
            conds = [User.username.like(like), User.first_name.like(like)]
            if q.strip().isdigit():
                conds.append(User.id == int(q.strip()))
            stmt = stmt.where(or_(*conds))
        now = utcnow()
        if filter == "subscribed":
            stmt = stmt.where(User.sub_until > now)
        elif filter == "running":
            stmt = stmt.where(BotSettings.running.is_(True))
        elif filter == "exchange":
            stmt = stmt.where(ExchangeAccount.user_id.is_not(None))
        elif filter == "blocked":
            stmt = stmt.where(User.blocked.is_(True))
        rows = (await s.execute(stmt)).all()
        stats = {r.user_id: (r.pnl, r.n) for r in (await s.execute(
            select(Trade.user_id, func.sum(Trade.pnl).label("pnl"), func.count().label("n"))
            .where(Trade.closed_at >= since).group_by(Trade.user_id))).all()}
        paid = {r.user_id: r.usd for r in (await s.execute(
            select(Payment.user_id, func.sum(Payment.usd).label("usd")).group_by(Payment.user_id))).all()}
    return [user_row(u, bs, acc, *(stats.get(u.id) or (0.0, 0)), paid.get(u.id, 0.0)) for u, bs, acc in rows]


@router.get("/users/{uid}")
async def user_detail(uid: int, request: Request, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        u = await s.get(User, uid)
        if u is None:
            raise HTTPException(404, "Клиент не найден")
        bs, acc = await s.get(BotSettings, uid), await s.get(ExchangeAccount, uid)
        trades = (await s.execute(select(Trade).where(Trade.user_id == uid).order_by(Trade.id.desc()).limit(100))).scalars().all()
        pays = (await s.execute(select(Payment).where(Payment.user_id == uid).order_by(Payment.id.desc()))).scalars().all()
        agg = (await s.execute(select(func.coalesce(func.sum(Trade.pnl), 0.0), func.count(),
                                      func.coalesce(func.sum(case((Trade.pnl > 0, 1), else_=0)), 0)).where(Trade.user_id == uid))).one()
        eq = (await s.execute(select(EquitySnapshot).where(EquitySnapshot.user_id == uid)
                              .order_by(EquitySnapshot.id.desc()).limit(1))).scalar_one_or_none()
    m = mgr(request)
    return {
        "user": user_row(u, bs, acc, 0.0, 0, sum(p.usd for p in pays)) | {"notes": u.notes},
        "settings": None if bs is None else {"symbols": bs.symbols, "strategies": bs.strategies,
                                             "paper_balance": round(bs.paper_balance, 2), "status": bs.status_text},
        "exchange": None if acc is None else {"uid": acc.bybit_uid, "mode": acc.mode, "referral_ok": acc.referral_ok,
                                              "connected_at": acc.created_at.isoformat(), "last_error": acc.last_error},
        "totals": {"pnl": round(float(agg[0]), 2), "trades": agg[1], "wins": int(agg[2] or 0),
                   "equity": eq.equity if eq else None},
        "live": m.open_state(uid) if m else None,
        "live_status": m.status(uid) if m else None,
        "trades": [{"id": t.id, "symbol": t.symbol, "strategy": t.strategy, "side": t.side, "entry": t.entry,
                    "exit": t.exit, "pnl": round(t.pnl, 4), "mode": t.mode, "at": t.closed_at.isoformat()} for t in trades],
        "payments": [{"plan": p.plan, "stars": p.stars, "usd": p.usd, "at": p.created_at.isoformat()} for p in pays],
    }


class UserAction(BaseModel):
    action: Literal["extend", "cancel_sub", "start", "stop", "block", "unblock", "profile", "notes",
                    "disconnect_exchange", "reset_paper", "message"]
    days: Optional[int] = Field(default=None, ge=-3650, le=3650)
    value: Optional[str] = None


@router.post("/users/{uid}/action")
async def user_action(uid: int, body: UserAction, request: Request, admin: AdminUser = Depends(current_admin)):
    a = body.action
    async with Session() as s:
        u, bs = await s.get(User, uid), await s.get(BotSettings, uid)
        if u is None or bs is None:
            raise HTTPException(404, "Клиент не найден")
        if a == "extend":
            base = u.sub_until if u.sub_until and u.sub_until > utcnow() else utcnow()
            u.sub_until = base + timedelta(days=body.days or 30)
        elif a == "cancel_sub":
            u.sub_until = None
            if bs.trading_mode == "exchange":
                bs.running = False
        elif a in ("start", "stop"):
            bs.running = a == "start"
        elif a in ("block", "unblock"):
            u.blocked = a == "block"
            if u.blocked:
                bs.running = False
        elif a == "profile":
            if body.value not in PROFILES:
                raise HTTPException(400, "Неизвестный профиль")
            bs.risk_profile = body.value
        elif a == "notes":
            u.notes = (body.value or "")[:2000]
        elif a == "disconnect_exchange":
            acc = await s.get(ExchangeAccount, uid)
            if acc:
                await s.delete(acc)
            if bs.trading_mode == "exchange":
                bs.running, bs.trading_mode = False, "paper"
        elif a == "reset_paper":
            bs.paper_balance = settings.paper_start_balance
        await s.commit()
    if a == "message":
        bot = request.app.state.bot
        if bot is None or not body.value:
            raise HTTPException(400, "Бот не запущен или пустое сообщение")
        await bot.send_message(uid, body.value[:4000])
    m = mgr(request)
    if m and a not in ("notes", "message", "extend"):
        await m.restart_worker(uid)
    await audit(admin.username, f"user.{a}", f"user={uid} days={body.days} value={(body.value or '')[:100]}")
    return {"ok": True}


# ───────────── платежи, сделки, экспорт ─────────────

@router.get("/payments")
async def payments(admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        rows = (await s.execute(select(Payment, User).join(User, User.id == Payment.user_id)
                                .order_by(Payment.id.desc()).limit(500))).all()
    return [{"id": p.id, "user_id": u.id, "user": u.first_name or u.username or str(u.id), "plan": p.plan,
             "stars": p.stars, "usd": p.usd, "charge_id": p.charge_id, "at": p.created_at.isoformat()} for p, u in rows]


@router.get("/trades")
async def trades(user_id: Optional[int] = None, symbol: str = "", strategy: str = "", mode: str = "",
                 admin: AdminUser = Depends(current_admin)):
    stmt = select(Trade).order_by(Trade.id.desc()).limit(500)
    if user_id:
        stmt = stmt.where(Trade.user_id == user_id)
    if symbol:
        stmt = stmt.where(Trade.symbol == symbol.upper())
    if strategy:
        stmt = stmt.where(Trade.strategy == strategy)
    if mode:
        stmt = stmt.where(Trade.mode == mode)
    async with Session() as s:
        rows = (await s.execute(stmt)).scalars().all()
    return [{"id": t.id, "user_id": t.user_id, "symbol": t.symbol, "strategy": t.strategy, "side": t.side,
             "qty": t.qty, "entry": t.entry, "exit": t.exit, "pnl": round(t.pnl, 4), "r": round(t.r, 2),
             "regime": t.regime, "mode": t.mode, "at": t.closed_at.isoformat()} for t in rows]


@router.get("/export/{what}.csv")
async def export(what: Literal["users", "trades", "payments", "finance"], admin: AdminUser = Depends(current_admin)):
    model = {"users": User, "trades": Trade, "payments": Payment, "finance": FinanceEntry}[what]
    async with Session() as s:
        rows = (await s.execute(select(model))).scalars().all()
    cols = [c.name for c in model.__table__.columns]
    buf = io.StringIO()
    w = csv.writer(buf)
    w.writerow(cols)
    for r in rows:
        w.writerow([getattr(r, c) for c in cols])
    await audit(admin.username, "export", what)
    return StreamingResponse(iter(["﻿" + buf.getvalue()]), media_type="text/csv",
                             headers={"Content-Disposition": f'attachment; filename="{what}.csv"'})


# ───────────── ручные доходы и расходы ─────────────

class FinanceIn(BaseModel):
    kind: Literal["income", "expense"]
    category: str = Field(min_length=1, max_length=32)
    amount_usd: float = Field(gt=0, le=10_000_000)
    note: str = Field(default="", max_length=255)
    date: Optional[datetime] = None


@router.get("/finance")
async def finance(days: int = Query(30, ge=1, le=365), admin: AdminUser = Depends(current_admin)):
    since = utcnow() - timedelta(days=days)
    async with Session() as s:
        entries = (await s.execute(select(FinanceEntry).where(FinanceEntry.date >= since)
                                   .order_by(FinanceEntry.date.desc()))).scalars().all()
        ai = (await s.execute(select(AIUsage.model, AIUsage.kind, func.count(), func.sum(AIUsage.input_tokens),
                                     func.sum(AIUsage.output_tokens), func.sum(AIUsage.cost_usd))
                              .where(AIUsage.ts >= since).group_by(AIUsage.model, AIUsage.kind))).all()
    series = (await finance_series(days))["rows"]
    return {
        "entries": [{"id": e.id, "date": e.date.isoformat(), "kind": e.kind, "category": e.category,
                     "amount_usd": e.amount_usd, "note": e.note, "by": e.created_by} for e in entries],
        "ai_usage": [{"model": r[0], "kind": r[1], "calls": r[2], "input_tokens": int(r[3] or 0),
                      "output_tokens": int(r[4] or 0), "cost_usd": round(r[5] or 0, 2)} for r in ai],
        "totals": {k: _sum(series, k) for k in ("subs", "income_other", "ai", "expense_other", "income", "expense", "profit")},
        "series": series,
    }


@router.post("/finance")
async def finance_add(body: FinanceIn, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        s.add(FinanceEntry(kind=body.kind, category=body.category, amount_usd=body.amount_usd, note=body.note,
                           date=body.date.replace(tzinfo=None) if body.date else utcnow(), created_by=admin.username))
        await s.commit()
    await audit(admin.username, "finance.add", f"{body.kind} {body.category} {body.amount_usd}")
    return {"ok": True}


@router.delete("/finance/{entry_id}")
async def finance_delete(entry_id: int, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        await s.execute(delete(FinanceEntry).where(FinanceEntry.id == entry_id))
        await s.commit()
    await audit(admin.username, "finance.delete", str(entry_id))
    return {"ok": True}


# ───────────── настройки и API-ключи ─────────────

def _mask(v: str) -> str:
    return "" if not v else "•" * 8 + v[-4:]


@router.get("/settings")
async def get_settings(admin: AdminUser = Depends(current_admin)):
    out = []
    for f in FIELDS:
        v = getattr(settings, f.key)
        out.append({"key": f.key, "label": f.label, "group": f.group, "type": f.type.__name__, "secret": f.secret,
                    "restart": f.restart, "help": f.help, "is_set": bool(v),
                    "value": _mask(v) if f.secret else (",".join(v) if isinstance(v, list) else v)})
    return out


@router.put("/settings")
async def put_settings(body: Dict[str, object], admin: AdminUser = Depends(current_admin)):
    changes = {}
    for k, v in body.items():
        f = FIELD_MAP.get(k)
        if f is None:
            continue
        if f.secret and isinstance(v, str) and v.startswith("•"):
            continue                                  # маска — значение не меняли
        try:
            from .config import coerce
            value = coerce(f, v)
        except (TypeError, ValueError):
            raise HTTPException(400, f"Неверное значение: {f.label}")
        if k == "ai_interval_min" and not 5 <= value <= 1440:
            raise HTTPException(400, "Интервал ИИ: от 5 до 1440 минут")
        if k in ("price_week_stars", "price_month_stars", "price_quarter_stars") and not 1 <= value <= 100000:
            raise HTTPException(400, "Цена в звёздах: от 1 до 100000")
        changes[k] = value
    await save_app_settings(changes)
    restart = [FIELD_MAP[k].label for k in changes if FIELD_MAP[k].restart]
    await audit(admin.username, "settings.update", ", ".join(changes))
    return {"ok": True, "restart_required": restart}


@router.post("/system/restart")
async def restart(admin: AdminUser = Depends(current_admin)):
    await audit(admin.username, "system.restart")
    asyncio.get_running_loop().call_later(1.0, os._exit, RESTART_CODE)
    return {"ok": True}


# ───────────── ИИ и рынок ─────────────

@router.get("/ai")
async def ai_state(request: Request, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        lessons = (await s.execute(select(Lesson).order_by(Lesson.id.desc()).limit(50))).scalars().all()
        tuning = (await s.execute(select(SymbolTuning))).scalars().all()
        stats = (await s.execute(select(StrategyStat).order_by(StrategyStat.n.desc()).limit(200))).scalars().all()
        insights = (await s.execute(select(AIInsight).order_by(AIInsight.id.desc()).limit(60))).scalars().all()
    m = mgr(request)
    return {
        "market": m.market_view() if m else [],
        "engine": m.engine_state() if m else None,
        "lessons": [{"id": x.id, "text": x.text, "at": x.ts.isoformat()} for x in lessons],
        "tuning": [{"symbol": t.symbol, "params": t.params} for t in tuning],
        "tunable": {k: {"min": v[0], "max": v[1], "default": v[2]} for k, v in TUNABLE.items()},
        "stats": [{"key": x.key, "n": x.n, "winrate": round(x.wins / x.n * 100) if x.n else 0,
                   "ewma_r": round(x.ewma_r, 3)} for x in stats],
        "history": [{"symbol": i.symbol, "regime": i.regime, "source": i.source, "at": i.ts.isoformat(),
                     "summary": (i.payload or {}).get("summary", "")} for i in insights],
    }


@router.post("/ai/run")
async def ai_run(request: Request, admin: AdminUser = Depends(current_admin)):
    m = mgr(request)
    if m is None:
        raise HTTPException(503, "Движок выключен")
    m.force_ai()
    await audit(admin.username, "ai.run")
    return {"ok": True}


class LessonIn(BaseModel):
    text: str = Field(min_length=3, max_length=500)


@router.post("/ai/lessons")
async def lesson_add(body: LessonIn, request: Request, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        s.add(Lesson(text=body.text))
        await s.commit()
    m = mgr(request)
    if m:
        m.lessons = (m.lessons + [body.text])[-10:]
    await audit(admin.username, "ai.lesson_add", body.text[:100])
    return {"ok": True}


@router.delete("/ai/lessons/{lesson_id}")
async def lesson_delete(lesson_id: int, request: Request, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        row = await s.get(Lesson, lesson_id)
        if row:
            await s.delete(row)
            await s.commit()
            m = mgr(request)
            if m and row.text in m.lessons:
                m.lessons.remove(row.text)
    await audit(admin.username, "ai.lesson_delete", str(lesson_id))
    return {"ok": True}


@router.put("/ai/tuning/{symbol}")
async def tuning_set(symbol: str, body: Dict[str, float], request: Request, admin: AdminUser = Depends(current_admin)):
    symbol = symbol.upper()
    params: dict = {}
    for k, v in body.items():
        if k in TUNABLE:
            lo, hi, _ = TUNABLE[k]
            params[k] = round(max(lo, min(hi, float(v))), 4)
    async with Session() as s:
        row = await s.get(SymbolTuning, symbol)
        if row is None:
            s.add(SymbolTuning(symbol=symbol, params=params))
        else:
            row.params, row.updated_at = params, utcnow()
        await s.commit()
    m = mgr(request)
    if m:
        m.brain.tuning[symbol] = params
    await audit(admin.username, "ai.tuning", f"{symbol} {params}")
    return {"ok": True, "params": params}


# ───────────── рассылка ─────────────

class BroadcastIn(BaseModel):
    text: str = Field(min_length=1, max_length=4000)
    audience: Literal["all", "subscribers", "running", "no_subscription"] = "all"


@router.post("/broadcast")
async def broadcast(body: BroadcastIn, request: Request, admin: AdminUser = Depends(current_admin)):
    bot = request.app.state.bot
    if bot is None:
        raise HTTPException(400, "Telegram-бот не запущен — задайте токен в настройках")
    now = utcnow()
    stmt = select(User.id).where(User.blocked.is_(False))
    if body.audience == "subscribers":
        stmt = stmt.where(User.sub_until > now)
    elif body.audience == "no_subscription":
        stmt = stmt.where(or_(User.sub_until.is_(None), User.sub_until <= now))
    elif body.audience == "running":
        stmt = stmt.join(BotSettings, BotSettings.user_id == User.id).where(BotSettings.running.is_(True))
    async with Session() as s:
        ids = [r[0] for r in (await s.execute(stmt)).all()]

    async def send_all():
        ok = 0
        for uid in ids:
            try:
                await bot.send_message(uid, body.text)
                ok += 1
            except Exception:  # noqa: BLE001 — клиент мог заблокировать бота
                pass
            await asyncio.sleep(0.05)              # ~20 сообщений в секунду — лимит Telegram
        await audit(admin.username, "broadcast.done", f"{ok}/{len(ids)}")

    asyncio.create_task(send_all())
    await audit(admin.username, "broadcast.start", f"{body.audience}: {len(ids)} получателей")
    return {"ok": True, "recipients": len(ids)}


# ───────────── журнал ─────────────

@router.get("/logs")
async def logs(lines: int = Query(200, ge=10, le=2000), admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        rows = (await s.execute(select(AuditLog).order_by(AuditLog.id.desc()).limit(300))).scalars().all()
    tail: List[str] = []
    if os.path.exists(LOG_FILE):
        with open(LOG_FILE, encoding="utf-8", errors="replace") as f:
            tail = f.readlines()[-lines:]
    return {"audit": [{"at": r.ts.isoformat(), "actor": r.actor, "action": r.action, "details": r.details} for r in rows],
            "app_log": "".join(tail)}


# ───────────── администраторы ─────────────

class AdminIn(BaseModel):
    username: str = Field(min_length=3, max_length=64, pattern=r"^[A-Za-z0-9_.-]+$")
    password: str = Field(min_length=8, max_length=128)


class PasswordIn(BaseModel):
    old_password: str
    new_password: str = Field(min_length=8, max_length=128)


@router.get("/admins")
async def admins(admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        rows = (await s.execute(select(AdminUser).order_by(AdminUser.id))).scalars().all()
    return [{"id": a.id, "username": a.username, "created_at": a.created_at.isoformat(),
             "last_login": a.last_login.isoformat() if a.last_login else None} for a in rows]


@router.post("/admins")
async def admin_add(body: AdminIn, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        if (await s.execute(select(AdminUser).where(AdminUser.username == body.username))).scalar_one_or_none():
            raise HTTPException(400, "Такой логин уже есть")
        s.add(AdminUser(username=body.username, password_hash=hash_password(body.password)))
        await s.commit()
    await audit(admin.username, "admin.add", body.username)
    return {"ok": True}


@router.delete("/admins/{admin_id}")
async def admin_delete(admin_id: int, admin: AdminUser = Depends(current_admin)):
    if admin_id == admin.id:
        raise HTTPException(400, "Нельзя удалить самого себя")
    async with Session() as s:
        row = await s.get(AdminUser, admin_id)
        if row:
            await s.delete(row)
            await s.commit()
    await audit(admin.username, "admin.delete", str(admin_id))
    return {"ok": True}


@router.post("/admins/password")
async def change_password(body: PasswordIn, admin: AdminUser = Depends(current_admin)):
    async with Session() as s:
        row = await s.get(AdminUser, admin.id)
        if not check_password(body.old_password, row.password_hash):
            raise HTTPException(400, "Старый пароль неверный")
        row.password_hash = hash_password(body.new_password)
        await s.commit()
    await audit(admin.username, "admin.password")
    return {"ok": True}
