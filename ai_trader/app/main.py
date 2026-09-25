"""Точка входа: FastAPI (API + мини-апп) + Telegram-бот + торговый движок в одном процессе.

Запуск: uvicorn app.main:app --host 0.0.0.0 --port 8000
"""
import asyncio
import logging
import os
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.responses import RedirectResponse
from fastapi.staticfiles import StaticFiles

from .api import router
from .config import settings
from .db import init_db

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("main")
WEBAPP_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "webapp")


@asynccontextmanager
async def lifespan(app: FastAPI):
    await init_db()
    app.state.bot = None
    app.state.manager = None
    tasks = []
    notify = None
    if settings.bot_token:
        from .tgbot import build, make_notifier, setup_menu
        bot, dp = build()
        app.state.bot = bot
        notify = make_notifier(bot)
        try:
            await setup_menu(bot)
        except Exception as e:  # noqa: BLE001
            log.warning("Кнопка меню не установлена: %s", e)
        tasks.append(asyncio.create_task(dp.start_polling(bot, handle_signals=False)))
    if settings.engine_enabled:
        from .engine.manager import EngineManager
        mgr = EngineManager(notify) if notify else EngineManager()
        app.state.manager = mgr
        tasks.append(asyncio.create_task(mgr.run()))
    yield
    for t in tasks:
        t.cancel()
    if app.state.bot:
        await app.state.bot.session.close()


app = FastAPI(title="AI Trader", lifespan=lifespan)
app.include_router(router)
app.mount("/app", StaticFiles(directory=WEBAPP_DIR, html=True), name="webapp")


@app.get("/")
async def root():
    return RedirectResponse("/app/")


@app.get("/health")
async def health():
    return {"ok": True}
