"""Шифрование API-ключей клиентов и проверка подписи Telegram Mini App."""
import hashlib
import hmac
import json
import time
from typing import Optional
from urllib.parse import parse_qsl

from cryptography.fernet import Fernet, InvalidToken

from .config import settings


def _fernet() -> Fernet:
    if not settings.encryption_key:
        raise RuntimeError("ENCRYPTION_KEY не задан. Сгенерируй: python -m app.security")
    return Fernet(settings.encryption_key.encode())


def encrypt(text: str) -> str:
    return _fernet().encrypt(text.encode()).decode()


def decrypt(token: str) -> str:
    try:
        return _fernet().decrypt(token.encode()).decode()
    except InvalidToken as e:
        raise RuntimeError("Не удалось расшифровать ключ — ENCRYPTION_KEY изменился?") from e


def validate_init_data(init_data: str, bot_token: str, max_age_sec: int = 86400) -> Optional[dict]:
    """Проверяет Telegram.WebApp.initData. Возвращает данные пользователя или None.

    https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
    """
    if not init_data or not bot_token:
        return None
    pairs = dict(parse_qsl(init_data, keep_blank_values=True))
    received_hash = pairs.pop("hash", None)
    if not received_hash:
        return None
    check_string = "\n".join(f"{k}={v}" for k, v in sorted(pairs.items()))
    secret = hmac.new(b"WebAppData", bot_token.encode(), hashlib.sha256).digest()
    expected = hmac.new(secret, check_string.encode(), hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, received_hash):
        return None
    if max_age_sec and time.time() - int(pairs.get("auth_date", "0")) > max_age_sec:
        return None
    try:
        return json.loads(pairs.get("user", "{}"))
    except json.JSONDecodeError:
        return None


if __name__ == "__main__":
    print(Fernet.generate_key().decode())


# ───────────── админ-панель: пароли и сессии ─────────────

def hash_password(password: str) -> str:
    import os as _os
    salt = _os.urandom(16)
    dk = hashlib.pbkdf2_hmac("sha256", password.encode(), salt, 310_000)
    return f"pbkdf2_sha256$310000${salt.hex()}${dk.hex()}"


def check_password(password: str, stored: str) -> bool:
    try:
        algo, rounds, salt, digest = stored.split("$")
    except ValueError:
        return False
    dk = hashlib.pbkdf2_hmac("sha256", password.encode(), bytes.fromhex(salt), int(rounds))
    return hmac.compare_digest(dk.hex(), digest)


def make_session_token(admin_id: int, ttl_sec: int = 12 * 3600) -> str:
    exp = int(time.time()) + ttl_sec
    body = f"{admin_id}.{exp}"
    sig = hmac.new(settings.secret_key.encode(), body.encode(), hashlib.sha256).hexdigest()
    return f"{body}.{sig}"


def read_session_token(token: str) -> Optional[int]:
    try:
        admin_id, exp, sig = token.split(".")
    except (ValueError, AttributeError):
        return None
    expected = hmac.new(settings.secret_key.encode(), f"{admin_id}.{exp}".encode(), hashlib.sha256).hexdigest()
    if not settings.secret_key or not hmac.compare_digest(sig, expected) or int(exp) < time.time():
        return None
    return int(admin_id)
