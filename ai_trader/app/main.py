"""Точка входа: API мини-аппа + админ-панель + Telegram-бот + торговый движок в одном процессе.

Запускать через run.py (он перезапускает приложение после установки и смены настроек).
"""
import asyncio
import logging
import os
from contextlib import asynccontextmanager
from logging.handlers import RotatingFileHandler

from fastapi import FastAPI, Request
from fastapi.responses import FileResponse, JSONResponse, RedirectResponse
from fastapi.staticfiles import StaticFiles

from .config import BASE_DIR, settings

os.makedirs(os.path.join(BASE_DIR, "logs"), exist_ok=True)
_fmt = logging.Formatter("%(asctime)s %(levelname)s %(name)s: %(message)s")
_file = RotatingFileHandler(os.path.join(BASE_DIR, "logs", "app.log"), maxBytes=5_000_000, backupCount=5, encoding="utf-8")
_file.setFormatter(_fmt)
_console = logging.StreamHandler()
_console.setFormatter(_fmt)
logging.basicConfig(level=logging.INFO, handlers=[_file, _console], force=True)
log = logging.getLogger("main")

WEBAPP_DIR = os.path.join(BASE_DIR, "webapp")
ADMIN_DIR = os.path.join(BASE_DIR, "admin")
SETUP_DIR = os.path.join(BASE_DIR, "setup")


@asynccontextmanager
async def lifespan(app: FastAPI):
    app.state.bot = None
    app.state.manager = None
    tasks = []
    if not settings.configured:
        from .setup import setup_code
        log.warning("Приложение не настроено. Откройте /setup — код установки: %s (файл SETUP_CODE.txt)", setup_code())
        yield
        return

    from .db import init_db, load_app_settings
    await init_db()
    await load_app_settings()

    notify = None
    if settings.bot_token:
        from .tgbot import build, make_notifier, setup_menu
        bot, dp = build(settings.bot_token)
        app.state.bot = bot
        notify = make_notifier(bot)
        try:
            await setup_menu(bot)
        except Exception as e:  # noqa: BLE001
            log.warning("Кнопка меню не установлена: %s", e)
        tasks.append(asyncio.create_task(dp.start_polling(bot, handle_signals=False)))
    else:
        log.warning("Токен Telegram-бота не задан — укажите его в админ-панели → Настройки")
    if settings.engine_enabled:
        from .engine.manager import EngineManager
        mgr = EngineManager(notify) if notify else EngineManager()
        app.state.manager = mgr
        tasks.append(asyncio.create_task(mgr.run()))
    log.info("AI Trader запущен")
    yield
    for t in tasks:
        t.cancel()
    if app.state.bot:
        await app.state.bot.session.close()


app = FastAPI(title="AI Trader", lifespan=lifespan, docs_url=None, redoc_url=None)

from .admin_api import router as admin_router  # noqa: E402
from .api import router as api_router  # noqa: E402
from .setup import router as setup_router  # noqa: E402

app.include_router(api_router)
app.include_router(admin_router)
app.include_router(setup_router)
app.mount("/app", StaticFiles(directory=WEBAPP_DIR, html=True), name="webapp")
app.mount("/admin/static", StaticFiles(directory=ADMIN_DIR), name="admin")


@app.middleware("http")
async def require_setup(request: Request, call_next):
    """Пока не пройдена установка — всё ведёт на мастер /setup."""
    path = request.url.path
    if not settings.configured and not path.startswith(("/setup", "/admin/static", "/health")):
        if path.startswith(("/api", "/admin/api")):
            return JSONResponse({"detail": "Приложение не настроено — откройте /setup"}, status_code=503)
        return RedirectResponse("/setup")
    return await call_next(request)


@app.get("/")
async def root():
    return RedirectResponse("/admin")


@app.get("/admin")
async def admin_page():
    return FileResponse(os.path.join(ADMIN_DIR, "index.html"))


@app.get("/setup")
async def setup_page():
    if settings.configured:
        return RedirectResponse("/admin")
    return FileResponse(os.path.join(SETUP_DIR, "index.html"))


@app.get("/health")
async def health():
    return {"ok": True, "configured": settings.configured}
