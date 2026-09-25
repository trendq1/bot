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
