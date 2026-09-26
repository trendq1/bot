"""База данных: MySQL / MariaDB (основной вариант), SQLite — для тестов и локальной отладки.

DATABASE_URL:
  mysql+aiomysql://USER:PASSWORD@HOST:3306/DBNAME?charset=utf8mb4
  sqlite+aiosqlite:///./data.db
"""
from datetime import datetime, timezone
from typing import Dict, Optional
from urllib.parse import quote_plus

from sqlalchemy import (JSON, BigInteger, Boolean, DateTime, Float, ForeignKey, Index, Integer, String, Text,
                        select)
from sqlalchemy.ext.asyncio import AsyncEngine, AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column

from .config import FIELD_MAP, settings


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


def mysql_url(host: str, port: int, user: str, password: str, name: str) -> str:
    return f"mysql+aiomysql://{quote_plus(user)}:{quote_plus(password)}@{host}:{int(port)}/{name}?charset=utf8mb4"


class Base(DeclarativeBase):
    pass


# ───────────── клиенты ─────────────

class User(Base):
    __tablename__ = "users"
    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=False)   # Telegram id
    username: Mapped[Optional[str]] = mapped_column(String(64))
    first_name: Mapped[Optional[str]] = mapped_column(String(128))
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    last_seen: Mapped[Optional[datetime]] = mapped_column(DateTime)
    sub_until: Mapped[Optional[datetime]] = mapped_column(DateTime)
    blocked: Mapped[bool] = mapped_column(Boolean, default=False)
    notes: Mapped[Optional[str]] = mapped_column(Text)

    def has_subscription(self) -> bool:
        return self.sub_until is not None and self.sub_until > utcnow()


class ExchangeAccount(Base):
    __tablename__ = "exchange_accounts"
    user_id: Mapped[int] = mapped_column(BigInteger, ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    bybit_uid: Mapped[Optional[str]] = mapped_column(String(32))
    api_key_enc: Mapped[str] = mapped_column(Text)
    api_secret_enc: Mapped[str] = mapped_column(Text)
    mode: Mapped[str] = mapped_column(String(8), default="demo")          # demo | live
    referral_ok: Mapped[bool] = mapped_column(Boolean, default=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    last_error: Mapped[Optional[str]] = mapped_column(Text)


class BotSettings(Base):
    __tablename__ = "bot_settings"
    user_id: Mapped[int] = mapped_column(BigInteger, ForeignKey("users.id", ondelete="CASCADE"), primary_key=True)
    running: Mapped[bool] = mapped_column(Boolean, default=False)
    trading_mode: Mapped[str] = mapped_column(String(8), default="paper")   # paper | exchange
    risk_profile: Mapped[str] = mapped_column(String(16), default="balanced")
    symbols: Mapped[list] = mapped_column(JSON, default=list)
    strategies: Mapped[dict] = mapped_column(JSON, default=lambda: {"grid": True, "trend": True, "liquidation": True})
    paper_balance: Mapped[float] = mapped_column(Float, default=1000.0)
    status_text: Mapped[Optional[str]] = mapped_column(Text)


class Trade(Base):
    __tablename__ = "trades"
    __table_args__ = (Index("ix_trades_user_closed", "user_id", "closed_at"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(BigInteger, ForeignKey("users.id", ondelete="CASCADE"))
    symbol: Mapped[str] = mapped_column(String(24))
    strategy: Mapped[str] = mapped_column(String(16))
    side: Mapped[str] = mapped_column(String(4))
    qty: Mapped[float] = mapped_column(Float)
    entry: Mapped[float] = mapped_column(Float)
    exit: Mapped[float] = mapped_column(Float)
    pnl: Mapped[float] = mapped_column(Float)
    r: Mapped[float] = mapped_column(Float, default=0.0)
    regime: Mapped[Optional[str]] = mapped_column(String(16))
    mode: Mapped[str] = mapped_column(String(8), default="paper")
    opened_at: Mapped[datetime] = mapped_column(DateTime)
    closed_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)


class EquitySnapshot(Base):
    __tablename__ = "equity_snapshots"
    __table_args__ = (Index("ix_equity_user_ts", "user_id", "ts"),)
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(BigInteger, ForeignKey("users.id", ondelete="CASCADE"))
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    equity: Mapped[float] = mapped_column(Float)


class Payment(Base):
    __tablename__ = "payments"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(BigInteger, ForeignKey("users.id", ondelete="CASCADE"), index=True)
    plan: Mapped[str] = mapped_column(String(16))
    stars: Mapped[int] = mapped_column(Integer)
    usd: Mapped[float] = mapped_column(Float, default=0.0)          # по курсу на момент оплаты
    charge_id: Mapped[str] = mapped_column(String(128), unique=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)


# ───────────── рынок и ИИ ─────────────

class AIInsight(Base):
    __tablename__ = "ai_insights"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    symbol: Mapped[str] = mapped_column(String(24), index=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    source: Mapped[str] = mapped_column(String(8))
    regime: Mapped[str] = mapped_column(String(16))
    payload: Mapped[dict] = mapped_column(JSON)


class AIUsage(Base):
    """Расход токенов Claude — для учёта затрат в админ-панели."""
    __tablename__ = "ai_usage"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    model: Mapped[str] = mapped_column(String(48))
    kind: Mapped[str] = mapped_column(String(16))              # analyze | review
    input_tokens: Mapped[int] = mapped_column(Integer, default=0)
    output_tokens: Mapped[int] = mapped_column(Integer, default=0)
    cache_read_tokens: Mapped[int] = mapped_column(Integer, default=0)
    cache_write_tokens: Mapped[int] = mapped_column(Integer, default=0)
    cost_usd: Mapped[float] = mapped_column(Float, default=0.0)


class Lesson(Base):
    __tablename__ = "lessons"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    text: Mapped[str] = mapped_column(Text)


class StrategyStat(Base):
    __tablename__ = "strategy_stats"
    key: Mapped[str] = mapped_column(String(64), primary_key=True)   # symbol|strategy|regime
    n: Mapped[int] = mapped_column(Integer, default=0)
    wins: Mapped[int] = mapped_column(Integer, default=0)
    ewma_r: Mapped[float] = mapped_column(Float, default=0.0)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


class SymbolTuning(Base):
    __tablename__ = "symbol_tuning"
    symbol: Mapped[str] = mapped_column(String(24), primary_key=True)
    params: Mapped[dict] = mapped_column(JSON, default=dict)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


# ───────────── администрирование ─────────────

class AdminUser(Base):
    __tablename__ = "admin_users"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    username: Mapped[str] = mapped_column(String(64), unique=True)
    password_hash: Mapped[str] = mapped_column(String(255))
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    last_login: Mapped[Optional[datetime]] = mapped_column(DateTime)


class AppSetting(Base):
    __tablename__ = "app_settings"
    key: Mapped[str] = mapped_column(String(64), primary_key=True)
    value: Mapped[str] = mapped_column(Text)                          # секреты — зашифрованы
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


class FinanceEntry(Base):
    """Доходы и расходы, внесённые вручную: сервер, реклама, реферальные выплаты Bybit и т.п."""
    __tablename__ = "finance_entries"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    date: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    kind: Mapped[str] = mapped_column(String(8))                     # income | expense
    category: Mapped[str] = mapped_column(String(32))
    amount_usd: Mapped[float] = mapped_column(Float)
    note: Mapped[Optional[str]] = mapped_column(String(255))
    created_by: Mapped[Optional[str]] = mapped_column(String(64))


class AuditLog(Base):
    __tablename__ = "audit_log"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    actor: Mapped[str] = mapped_column(String(64))
    action: Mapped[str] = mapped_column(String(64))
    details: Mapped[Optional[str]] = mapped_column(Text)


# Все таблицы MySQL — InnoDB в utf8mb4 (эмодзи и кириллица в именах клиентов)
for _t in Base.metadata.tables.values():
    _t.dialect_kwargs.update({"mysql_engine": "InnoDB", "mysql_charset": "utf8mb4",
                              "mysql_collate": "utf8mb4_unicode_ci"})


# ───────────── подключение ─────────────

_engine: Optional[AsyncEngine] = None
_sessionmaker: Optional[async_sessionmaker] = None


def make_engine(url: str) -> AsyncEngine:
    import os

    from sqlalchemy.pool import NullPool
    kw: dict = {}
    if os.getenv("DB_NULLPOOL"):                 # тесты: без пула соединений между циклами событий
        kw = {"poolclass": NullPool}
    elif url.startswith("mysql"):
        # MySQL закрывает простаивающие соединения — проверяем и обновляем их
        kw = {"pool_pre_ping": True, "pool_recycle": 1800, "pool_size": 10, "max_overflow": 20}
    return create_async_engine(url, **kw)


def configure(url: str) -> None:
    global _engine, _sessionmaker
    _engine = make_engine(url)
    _sessionmaker = async_sessionmaker(_engine, expire_on_commit=False, class_=AsyncSession)


def Session() -> AsyncSession:  # noqa: N802 — используется как фабрика сессий
    if _sessionmaker is None:
        raise RuntimeError("База данных не настроена — откройте /setup")
    return _sessionmaker()


async def init_db() -> None:
    async with _engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)


async def load_app_settings() -> Dict[str, str]:
    """Читает настройки из БД и применяет их поверх .env."""
    from .security import decrypt
    values = {}
    async with Session() as s:
        for row in (await s.execute(select(AppSetting))).scalars():
            field = FIELD_MAP.get(row.key)
            if field is None:
                continue
            if field.secret:
                values[row.key] = decrypt(row.value) if row.value else ""
            else:
                values[row.key] = _json_or_raw(row.value)
    settings.apply(values)
    return values


async def save_app_settings(values: Dict[str, object]) -> None:
    import json

    from .security import encrypt
    async with Session() as s:
        for key, value in values.items():
            field = FIELD_MAP[key]
            if field.secret:
                stored = encrypt(str(value)) if value else ""
            else:
                stored = json.dumps(value, ensure_ascii=False)
            row = await s.get(AppSetting, key)
            if row is None:
                s.add(AppSetting(key=key, value=stored))
            else:
                row.value, row.updated_at = stored, utcnow()
        await s.commit()
    settings.apply(values)


def _json_or_raw(v: str):
    import json
    try:
        return json.loads(v)
    except (TypeError, ValueError):
        return v


if settings.database_url:
    configure(settings.database_url)
