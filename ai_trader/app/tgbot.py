"""Telegram-бот: вход в мини-апп, оплата подписки звёздами (Telegram Stars), уведомления."""
import logging
from datetime import timedelta

from aiogram import Bot, Dispatcher, F, Router
from aiogram.filters import Command, CommandStart
from aiogram.types import (InlineKeyboardButton, InlineKeyboardMarkup, MenuButtonWebApp, Message,
                           PreCheckoutQuery, WebAppInfo)
from sqlalchemy.exc import IntegrityError

from .api import ensure_user
from .config import settings
from .db import Payment, Session, User, utcnow

log = logging.getLogger("tgbot")
router = Router()


def app_keyboard() -> InlineKeyboardMarkup:
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text="🚀 Открыть AI Trader", web_app=WebAppInfo(url=settings.webapp_url))]])


@router.message(CommandStart())
async def start(m: Message) -> None:
    await ensure_user(m.from_user.id, m.from_user.username, m.from_user.first_name)
    await m.answer(
        "👋 <b>AI Trader для Bybit Futures</b>\n\n"
        "Бот торгует фьючерсами за тебя: сетка для боковика, входы по тренду и отскоки после ликвидаций. "
        "ИИ каждые полчаса анализирует рынок и распределяет капитал, а бот учится на результатах сделок.\n\n"
        "• Бесплатно: демо-торговля на виртуальном счёте\n"
        "• По подписке: торговля на твоём аккаунте Bybit\n\n"
        "⚠️ Торговля с плечом рискованна. Прошлые результаты не гарантируют будущих.",
        reply_markup=app_keyboard(), parse_mode="HTML")


@router.message(Command("app"))
async def open_app(m: Message) -> None:
    await m.answer("Открыть приложение:", reply_markup=app_keyboard())


@router.pre_checkout_query()
async def pre_checkout(q: PreCheckoutQuery) -> None:
    ok = q.invoice_payload.startswith("sub:") and q.invoice_payload.split(":")[2] == str(q.from_user.id)
    await q.answer(ok=ok, error_message=None if ok else "Счёт устарел, создайте новый в приложении")


@router.message(F.successful_payment)
async def paid(m: Message) -> None:
    sp = m.successful_payment
    _, plan_code, uid = sp.invoice_payload.split(":")
    plan = settings.plan(plan_code)
    async with Session() as s:
        s.add(Payment(user_id=int(uid), plan=plan.code, stars=sp.total_amount,
                      usd=round(sp.total_amount * settings.stars_usd_rate, 2),
                      charge_id=sp.telegram_payment_charge_id))
        user = await s.get(User, int(uid))
        base = user.sub_until if user.sub_until and user.sub_until > utcnow() else utcnow()
        user.sub_until = base + timedelta(days=plan.days)
        try:
            await s.commit()
        except IntegrityError:
            return   # этот платёж уже учтён
    await m.answer(f"✅ Подписка активна до {user.sub_until:%d.%m.%Y}. "
                   "Подключите Bybit в приложении и включите торговлю на бирже.", reply_markup=app_keyboard())


def build(token: str) -> tuple:
    bot = Bot(token)
    dp = Dispatcher()
    dp.include_router(router)
    return bot, dp


async def setup_menu(bot: Bot) -> None:
    await bot.set_chat_menu_button(menu_button=MenuButtonWebApp(text="AI Trader", web_app=WebAppInfo(url=settings.webapp_url)))


def make_notifier(bot: Bot):
    async def notify(user_id: int, text: str) -> None:
        try:
            await bot.send_message(user_id, text)
        except Exception as e:  # noqa: BLE001 — пользователь мог заблокировать бота
            log.debug("notify %s: %s", user_id, e)
    return notify
