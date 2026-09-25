"""Модели базы данных (SQLAlchemy 2, async). SQLite по умолчанию, PostgreSQL — через DATABASE_URL."""
from datetime import datetime, timezone
from typing import Optional

from sqlalchemy import JSON, BigInteger, Boolean, DateTime, Float, ForeignKey, Integer, String, Text
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column

from .config import settings


def utcnow() -> datetime:
    return datetime.now(timezone.utc).replace(tzinfo=None)


class Base(DeclarativeBase):
    pass


class User(Base):
    __tablename__ = "users"
    id: Mapped[int] = mapped_column(BigInteger, primary_key=True)          # Telegram user id
    username: Mapped[Optional[str]] = mapped_column(String(64))
    first_name: Mapped[Optional[str]] = mapped_column(String(128))
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    sub_until: Mapped[Optional[datetime]] = mapped_column(DateTime)

    def has_subscription(self) -> bool:
        return self.sub_until is not None and self.sub_until > utcnow()


class ExchangeAccount(Base):
    __tablename__ = "exchange_accounts"
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), primary_key=True)
    bybit_uid: Mapped[Optional[str]] = mapped_column(String(32))
    api_key_enc: Mapped[str] = mapped_column(Text)
    api_secret_enc: Mapped[str] = mapped_column(Text)
    mode: Mapped[str] = mapped_column(String(8), default="demo")         # demo | live
    referral_ok: Mapped[bool] = mapped_column(Boolean, default=False)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    last_error: Mapped[Optional[str]] = mapped_column(Text)


class BotSettings(Base):
    __tablename__ = "bot_settings"
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), primary_key=True)
    running: Mapped[bool] = mapped_column(Boolean, default=False)
    trading_mode: Mapped[str] = mapped_column(String(8), default="paper")  # paper | exchange
    risk_profile: Mapped[str] = mapped_column(String(16), default="balanced")
    symbols: Mapped[list] = mapped_column(JSON, default=list)
    strategies: Mapped[dict] = mapped_column(JSON, default=lambda: {"grid": True, "trend": True, "liquidation": True})
    paper_balance: Mapped[float] = mapped_column(Float, default=1000.0)
    status_text: Mapped[Optional[str]] = mapped_column(Text)


class Trade(Base):
    __tablename__ = "trades"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), index=True)
    symbol: Mapped[str] = mapped_column(String(24))
    strategy: Mapped[str] = mapped_column(String(16))      # grid | trend | liquidation
    side: Mapped[str] = mapped_column(String(4))           # Buy | Sell (сторона входа)
    qty: Mapped[float] = mapped_column(Float)
    entry: Mapped[float] = mapped_column(Float)
    exit: Mapped[float] = mapped_column(Float)
    pnl: Mapped[float] = mapped_column(Float)              # чистый, после комиссий
    r: Mapped[float] = mapped_column(Float, default=0.0)   # результат в единицах риска
    regime: Mapped[Optional[str]] = mapped_column(String(16))
    mode: Mapped[str] = mapped_column(String(8), default="paper")
    opened_at: Mapped[datetime] = mapped_column(DateTime)
    closed_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)


class EquitySnapshot(Base):
    __tablename__ = "equity_snapshots"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), index=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    equity: Mapped[float] = mapped_column(Float)


class Payment(Base):
    __tablename__ = "payments"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    user_id: Mapped[int] = mapped_column(ForeignKey("users.id"), index=True)
    plan: Mapped[str] = mapped_column(String(16))
    stars: Mapped[int] = mapped_column(Integer)
    charge_id: Mapped[str] = mapped_column(String(128), unique=True)
    created_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


class AIInsight(Base):
    __tablename__ = "ai_insights"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    symbol: Mapped[str] = mapped_column(String(24), index=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow, index=True)
    source: Mapped[str] = mapped_column(String(8))          # ai | rules
    regime: Mapped[str] = mapped_column(String(16))
    payload: Mapped[dict] = mapped_column(JSON)


class Lesson(Base):
    __tablename__ = "lessons"
    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=True)
    ts: Mapped[datetime] = mapped_column(DateTime, default=utcnow)
    text: Mapped[str] = mapped_column(Text)


class StrategyStat(Base):
    """Память обучения: как стратегия работала на монете в данном режиме рынка (по всем клиентам)."""
    __tablename__ = "strategy_stats"
    key: Mapped[str] = mapped_column(String(64), primary_key=True)   # symbol|strategy|regime
    n: Mapped[int] = mapped_column(Integer, default=0)
    wins: Mapped[int] = mapped_column(Integer, default=0)
    ewma_r: Mapped[float] = mapped_column(Float, default=0.0)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


class SymbolTuning(Base):
    """Параметры, которые ИИ подстраивает по итогам ежедневного разбора (в жёстких границах)."""
    __tablename__ = "symbol_tuning"
    symbol: Mapped[str] = mapped_column(String(24), primary_key=True)
    params: Mapped[dict] = mapped_column(JSON, default=dict)
    updated_at: Mapped[datetime] = mapped_column(DateTime, default=utcnow)


engine = create_async_engine(settings.database_url, future=True)
Session = async_sessionmaker(engine, expire_on_commit=False, class_=AsyncSession)


async def init_db() -> None:
    async with engine.begin() as conn:
        await conn.run_sync(Base.metadata.create_all)
