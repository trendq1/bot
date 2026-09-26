"""Мастер первоначальной установки (/setup).

Работает только пока приложение не настроено. Защищён одноразовым кодом из файла
SETUP_CODE.txt в папке проекта (его видно через файловый менеджер хостинга).
После сохранения пишет .env, создаёт таблицы и администратора, затем перезапускается.
"""
import asyncio
import os
import secrets

from cryptography.fernet import Fernet
from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field
from sqlalchemy import text

from .config import BASE_DIR, settings, write_dotenv

router = APIRouter()
CODE_FILE = os.path.join(BASE_DIR, "SETUP_CODE.txt")
RESTART_CODE = 3


def setup_code() -> str:
    if not os.path.exists(CODE_FILE):
        with open(CODE_FILE, "w", encoding="utf-8") as f:
            f.write(secrets.token_hex(4).upper())
    with open(CODE_FILE, encoding="utf-8") as f:
        return f.read().strip()


class DBIn(BaseModel):
    host: str = "localhost"
    port: int = 3306
    user: str
    password: str = ""
    name: str


class SetupIn(BaseModel):
    code: str
    db: DBIn
    admin_username: str = Field(min_length=3, max_length=64, pattern=r"^[A-Za-z0-9_.-]+$")
    admin_password: str = Field(min_length=8, max_length=128)
    bot_token: str = ""
    webapp_url: str = ""
    anthropic_api_key: str = ""


_failures = {"n": 0}


def _guard(code: str) -> None:
    if settings.configured:
        raise HTTPException(404, "Уже настроено")
    if _failures["n"] >= 20:
        raise HTTPException(429, "Слишком много неверных попыток. Перезапустите приложение.")
    if code.strip().upper() != setup_code():
        _failures["n"] += 1
        raise HTTPException(403, "Неверный код установки. Он в файле SETUP_CODE.txt в папке проекта.")


async def _check_db(db: DBIn) -> tuple:
    from .db import make_engine, mysql_url
    url = mysql_url(db.host, db.port, db.user, db.password, db.name)
    engine = make_engine(url)
    try:
        async with engine.connect() as conn:
            version = (await conn.execute(text("SELECT VERSION()"))).scalar()
    except Exception as e:  # noqa: BLE001 — показываем причину пользователю
        raise HTTPException(400, f"Не удалось подключиться к MySQL: {e.__class__.__name__}: {str(e)[:300]}")
    finally:
        await engine.dispose()
    return url, version


class TestIn(BaseModel):
    code: str
    db: DBIn


@router.post("/setup/api/test-db")
async def test_db(body: TestIn):
    _guard(body.code)
    _, version = await _check_db(body.db)
    return {"ok": True, "version": version}


@router.post("/setup/api/finish")
async def finish(body: SetupIn):
    _guard(body.code)
    url, _ = await _check_db(body.db)

    from . import db
    from .security import hash_password

    enc_key, secret = Fernet.generate_key().decode(), secrets.token_urlsafe(48)
    settings.database_url, settings.encryption_key, settings.secret_key = url, enc_key, secret
    db.configure(url)
    await db.init_db()
    async with db.Session() as s:
        from sqlalchemy import select
        exists = (await s.execute(select(db.AdminUser).where(db.AdminUser.username == body.admin_username))).scalar_one_or_none()
        if exists is None:
            s.add(db.AdminUser(username=body.admin_username, password_hash=hash_password(body.admin_password)))
        await s.commit()
    initial = {k: v for k, v in {"bot_token": body.bot_token.strip(), "webapp_url": body.webapp_url.strip(),
                                 "anthropic_api_key": body.anthropic_api_key.strip()}.items() if v}
    if initial:
        await db.save_app_settings(initial)
    write_dotenv({"DATABASE_URL": url, "ENCRYPTION_KEY": enc_key, "SECRET_KEY": secret})
    try:
        os.remove(CODE_FILE)
    except OSError:
        pass
    asyncio.get_running_loop().call_later(1.5, os._exit, RESTART_CODE)
    return {"ok": True}
