"""Настройки из переменных окружения (.env)."""
import os
from dataclasses import dataclass, field
from typing import List


def _env(name: str, default: str = "") -> str:
    return os.getenv(name, default).strip()


def _list(name: str, default: str) -> List[str]:
    return [s.strip().upper() for s in _env(name, default).split(",") if s.strip()]


def _load_dotenv(path: str = ".env") -> None:
    """Минимальный загрузчик .env без внешних зависимостей."""
    if not os.path.exists(path):
        return
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


_load_dotenv()


@dataclass(frozen=True)
class Plan:
    code: str
    title: str
    days: int
    stars: int


@dataclass(frozen=True)
class Settings:
    bot_token: str = _env("BOT_TOKEN")
    webapp_url: str = _env("WEBAPP_URL", "https://example.com/app/")
    database_url: str = _env("DATABASE_URL", "sqlite+aiosqlite:///./data.db")
    encryption_key: str = _env("ENCRYPTION_KEY")

    anthropic_api_key: str = _env("ANTHROPIC_API_KEY")
    ai_model: str = _env("AI_MODEL", "claude-opus-5")
    ai_interval_min: int = int(_env("AI_INTERVAL_MIN", "30"))

    referral_link: str = _env("BYBIT_REFERRAL_LINK", "https://www.bybit.com/invite?ref=YOURCODE")
    require_referral: bool = _env("REQUIRE_REFERRAL", "1") == "1"
    affiliate_api_key: str = _env("BYBIT_AFFILIATE_API_KEY")
    affiliate_api_secret: str = _env("BYBIT_AFFILIATE_API_SECRET")

    symbols: List[str] = field(default_factory=lambda: _list(
        "SYMBOLS", "BTCUSDT,ETHUSDT,SOLUSDT,XRPUSDT,DOGEUSDT,SUIUSDT,AVAXUSDT,LINKUSDT,ADAUSDT,LTCUSDT"))
    default_symbols: List[str] = field(default_factory=lambda: _list("DEFAULT_SYMBOLS", "BTCUSDT,ETHUSDT,SOLUSDT"))

    admin_ids: List[int] = field(default_factory=lambda: [int(x) for x in _env("ADMIN_IDS").split(",") if x.strip()])
    engine_enabled: bool = _env("ENGINE_ENABLED", "1") == "1"
    dev_user_id: int = int(_env("DEV_USER_ID", "0") or 0)   # только для локальной отладки в браузере
    paper_start_balance: float = float(_env("PAPER_BALANCE", "1000"))

    plans: List[Plan] = field(default_factory=lambda: [
        Plan("week", "7 дней", 7, int(_env("PRICE_WEEK_STARS", "250"))),
        Plan("month", "30 дней", 30, int(_env("PRICE_MONTH_STARS", "750"))),
        Plan("quarter", "90 дней", 90, int(_env("PRICE_QUARTER_STARS", "1900"))),
    ])

    def plan(self, code: str) -> Plan:
        for p in self.plans:
            if p.code == code:
                return p
        raise KeyError(code)


settings = Settings()
