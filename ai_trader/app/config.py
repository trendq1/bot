"""Настройки приложения.

Два уровня:
1. Базовые (только файл .env): подключение к БД, ключи шифрования, порт.
   Их пишет мастер установки /setup.
2. Рабочие (токены, API-ключи, цены, монеты…): значение по умолчанию -> .env -> база данных.
   Редактируются в админ-панели, секреты хранятся в БД зашифрованными.
"""
import os
from dataclasses import dataclass
from typing import Any, Dict, List

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
ENV_PATH = os.path.join(BASE_DIR, ".env")


def load_dotenv(path: str = ENV_PATH) -> Dict[str, str]:
    values: Dict[str, str] = {}
    if not os.path.exists(path):
        return values
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            values[k.strip()] = v.strip().strip('"').strip("'")
    for k, v in values.items():
        os.environ.setdefault(k, v)
    return values


def write_dotenv(values: Dict[str, str], path: str = ENV_PATH) -> None:
    current = {}
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            for line in f:
                if "=" in line and not line.strip().startswith("#"):
                    k, v = line.strip().split("=", 1)
                    current[k.strip()] = v
    current.update(values)
    with open(path, "w", encoding="utf-8") as f:
        f.write("# Создано мастером установки. Остальные настройки — в админ-панели.\n")
        for k, v in current.items():
            f.write(f"{k}={v}\n")
    try:
        os.chmod(path, 0o600)
    except OSError:
        pass


load_dotenv()


@dataclass(frozen=True)
class Field:
    key: str
    type: type
    default: Any
    label: str
    group: str
    secret: bool = False
    restart: bool = False
    help: str = ""


FIELDS: List[Field] = [
    Field("bot_token", str, "", "Токен Telegram-бота", "Telegram", secret=True, restart=True, help="от @BotFather"),
    Field("webapp_url", str, "https://example.com/app/", "Адрес мини-аппа (HTTPS)", "Telegram", restart=True),
    Field("support_contact", str, "", "Контакт поддержки", "Telegram", help="например @support"),
    Field("anthropic_api_key", str, "", "Ключ Anthropic (Claude)", "ИИ", secret=True),
    Field("ai_model", str, "claude-opus-5", "Модель ИИ", "ИИ", help="claude-opus-5 или дешевле claude-sonnet-5"),
    Field("ai_interval_min", int, 30, "Интервал анализа монеты, мин", "ИИ"),
    Field("referral_link", str, "https://www.bybit.com/invite?ref=YOURCODE", "Реферальная ссылка Bybit", "Bybit"),
    Field("require_referral", bool, True, "Реальный счёт только для рефералов", "Bybit"),
    Field("affiliate_api_key", str, "", "Партнёрский API-ключ Bybit", "Bybit", secret=True, help="read-only, для проверки рефералов"),
    Field("affiliate_api_secret", str, "", "Партнёрский API-секрет Bybit", "Bybit", secret=True),
    Field("symbols", list, ["BTCUSDT", "ETHUSDT", "SOLUSDT", "XRPUSDT", "DOGEUSDT", "SUIUSDT", "AVAXUSDT",
                            "LINKUSDT", "ADAUSDT", "LTCUSDT"], "Доступные монеты", "Торговля", restart=True),
    Field("default_symbols", list, ["BTCUSDT", "ETHUSDT", "SOLUSDT"], "Монеты новых клиентов", "Торговля"),
    Field("paper_start_balance", float, 1000.0, "Стартовый демо-баланс, $", "Торговля"),
    Field("engine_enabled", bool, True, "Торговый движок включён", "Торговля", restart=True),
    Field("maintenance", bool, False, "Техобслуживание (запрет запуска ботов)", "Торговля"),
    Field("price_week_stars", int, 250, "Цена 7 дней, ⭐", "Тарифы"),
    Field("price_month_stars", int, 750, "Цена 30 дней, ⭐", "Тарифы"),
    Field("price_quarter_stars", int, 1900, "Цена 90 дней, ⭐", "Тарифы"),
    Field("stars_usd_rate", float, 0.013, "Курс 1 ⭐ в $ (для учёта доходов)", "Тарифы"),
]
FIELD_MAP = {f.key: f for f in FIELDS}


@dataclass(frozen=True)
class Plan:
    code: str
    title: str
    days: int
    stars: int


def coerce(field: Field, value: Any) -> Any:
    if value is None:
        return field.default
    if field.type is bool:
        return value if isinstance(value, bool) else str(value).strip().lower() in ("1", "true", "yes", "on", "да")
    if field.type is list:
        items = value if isinstance(value, list) else str(value).split(",")
        return [str(x).strip().upper() for x in items if str(x).strip()]
    return field.type(value)


class Settings:
    def __init__(self):
        # базовые — только из окружения/.env
        self.database_url = os.getenv("DATABASE_URL", "")
        self.encryption_key = os.getenv("ENCRYPTION_KEY", "")
        self.secret_key = os.getenv("SECRET_KEY", "")
        self.host = os.getenv("HOST", "0.0.0.0")
        self.port = int(os.getenv("PORT", "8000"))
        self.dev_user_id = int(os.getenv("DEV_USER_ID", "0") or 0)   # только локальная отладка
        self.admin_ids: List[int] = [int(x) for x in os.getenv("ADMIN_IDS", "").split(",") if x.strip()]
        for f in FIELDS:
            env = os.getenv(f.key.upper())
            setattr(self, f.key, coerce(f, env) if env is not None else f.default)

    @property
    def configured(self) -> bool:
        return bool(self.database_url and self.encryption_key and self.secret_key)

    def apply(self, values: Dict[str, Any]) -> None:
        for k, v in values.items():
            if k in FIELD_MAP:
                setattr(self, k, coerce(FIELD_MAP[k], v))

    @property
    def plans(self) -> List[Plan]:
        return [Plan("week", "7 дней", 7, self.price_week_stars),
                Plan("month", "30 дней", 30, self.price_month_stars),
                Plan("quarter", "90 дней", 90, self.price_quarter_stars)]

    def plan(self, code: str) -> Plan:
        for p in self.plans:
            if p.code == code:
                return p
        raise KeyError(code)


settings = Settings()
