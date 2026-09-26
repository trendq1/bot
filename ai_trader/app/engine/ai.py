"""ИИ-аналитик на Claude (Anthropic API).

1) Каждые AI_INTERVAL_MIN минут по каждому символу: режим рынка, веса стратегий,
   направление и шаг сетки, множитель риска. Один анализ на символ — для всех клиентов.
2) Раз в сутки: разбор закрытых сделок -> «уроки» (попадают в следующие анализы)
   и подстройка параметров в жёстких границах.

ИИ не отправляет ордера сам: он выбирает режим и параметры, а все лимиты риска
проверяет код. Если ключа нет или API недоступен — работает запасной алгоритм.
"""
import json
import logging
from dataclasses import asdict, dataclass
from typing import Dict, List, Optional

import anthropic

from ..config import settings
from .indicators import REGIMES, rule_regime

log = logging.getLogger("ai")

TUNABLE = {  # параметр: (мин, макс, по умолчанию)
    "grid_step_atr": (0.3, 1.5, 0.6),
    "trend_rr": (0.8, 2.0, 1.2),
    "liq_threshold_mult": (0.5, 3.0, 1.0),
}


@dataclass
class Insight:
    regime: str
    confidence: float
    w_grid: float
    w_trend: float
    w_liquidation: float
    grid_mode: str          # long | short | off
    grid_step_atr: float
    risk_mult: float
    summary: str
    source: str = "rules"

    @staticmethod
    def clamp(d: dict, source: str) -> "Insight":
        c = lambda v, lo, hi, dflt: max(lo, min(hi, float(v))) if v is not None else dflt  # noqa: E731
        regime = d.get("regime") if d.get("regime") in REGIMES else "range"
        mode = d.get("grid_mode") if d.get("grid_mode") in ("long", "short", "off") else "off"
        return Insight(
            regime=regime,
            confidence=c(d.get("confidence"), 0, 1, 0.5),
            w_grid=c(d.get("w_grid"), 0, 1, 0.5),
            w_trend=c(d.get("w_trend"), 0, 1, 0.5),
            w_liquidation=c(d.get("w_liquidation"), 0, 1, 0.5),
            grid_mode=mode,
            grid_step_atr=c(d.get("grid_step_atr"), *TUNABLE["grid_step_atr"]),
            risk_mult=c(d.get("risk_mult"), 0.3, 1.2, 1.0),
            summary=str(d.get("summary", ""))[:300],
            source=source,
        )

    def to_dict(self) -> dict:
        return asdict(self)


def rule_insight(f: Dict[str, float], tuning: Optional[dict] = None) -> Insight:
    regime = rule_regime(f)
    step = (tuning or {}).get("grid_step_atr", TUNABLE["grid_step_atr"][2])
    below_vwap = f["price"] < f["vwap_4h"]
    table = {
        "trend_up":        (0.4, 0.9, 0.5, "long", 1.0, "Восходящий тренд: сетка в лонг, входы на откатах."),
        "trend_down":      (0.4, 0.9, 0.5, "short", 1.0, "Нисходящий тренд: сетка в шорт, входы на откатах."),
        "range":           (1.0, 0.2, 0.7, "long" if below_vwap else "short", 1.0, "Боковик: основной заработок — сетка."),
        "high_volatility": (0.0, 0.2, 0.9, "off", 0.5, "Высокая волатильность: сетка выключена, риск снижен."),
    }
    wg, wt, wl, mode, rm, text = table[regime]
    return Insight(regime, 0.5, wg, wt, wl, mode, step, rm, text, "rules")


SYSTEM = """Ты — риск-ориентированный аналитик крипторынка для автоматического бота на фьючерсах Bybit (USDT Perpetual).
Бот сам исполняет сделки и сам соблюдает лимиты риска. Твоя задача — для одной монеты выбрать режим рынка и распределить приоритет между стратегиями.

Стратегии бота:
- grid — сетка лимитных ордеров: много мелких прибыльных сделок в боковике. В одностороннем режиме: long (покупки ниже цены, продажи на шаг выше) или short (зеркально). Опасна при сильном тренде против направления сетки. grid_mode="off" выключает сетку.
- trend — вход по тренду после отката к EMA20 с коротким тейком (~1.2R). Хороша при ADX>23 и выстроенных EMA.
- liquidation — вход на отскок после каскада ликвидаций при сильном отклонении от VWAP. Хороша при высокой волатильности.

Режимы: trend_up, trend_down, range, high_volatility.

Правила:
- Веса w_grid, w_trend, w_liquidation — числа от 0 до 1 (приоритет стратегии, не доли).
- grid_step_atr — шаг сетки в долях ATR(5м), от 0.3 до 1.5. Шире при высокой волатильности.
- risk_mult — от 0.3 до 1.2. Снижай при неопределённости, новостной волатильности, экстремальном funding.
- confidence — от 0 до 1.
- Учитывай статистику обучения (как стратегии уже отработали на этой монете) и уроки прошлых дней.
- Не выдумывай данные, которых нет во входе. При сомнениях выбирай более осторожный вариант.
- summary — 1–2 предложения по-русски для клиента: что происходит на рынке и что делает бот."""

INSIGHT_SCHEMA = {
    "type": "object",
    "properties": {
        "regime": {"type": "string", "enum": list(REGIMES)},
        "confidence": {"type": "number"},
        "w_grid": {"type": "number"},
        "w_trend": {"type": "number"},
        "w_liquidation": {"type": "number"},
        "grid_mode": {"type": "string", "enum": ["long", "short", "off"]},
        "grid_step_atr": {"type": "number"},
        "risk_mult": {"type": "number"},
        "summary": {"type": "string"},
    },
    "required": ["regime", "confidence", "w_grid", "w_trend", "w_liquidation",
                 "grid_mode", "grid_step_atr", "risk_mult", "summary"],
    "additionalProperties": False,
}

REVIEW_SYSTEM = """Ты разбираешь итоги торгового дня автоматического бота на фьючерсах Bybit.
На входе — агрегированная статистика закрытых сделок по монетам, стратегиям и режимам рынка, а также текущие уроки.
Сформулируй до 5 коротких практичных уроков по-русски (что работало, что нет, чего избегать) — они будут подсказками для аналитика в следующие дни.
Предложи подстройку параметров только при достаточной статистике (от 10 сделок на связку). Допустимые параметры и границы:
grid_step_atr 0.3–1.5, trend_rr 0.8–2.0, liq_threshold_mult 0.5–3.0. Меняй плавно, не более чем на 20% за день.
Если данных мало — верни пустой список tuning."""

REVIEW_SCHEMA = {
    "type": "object",
    "properties": {
        "lessons": {"type": "array", "items": {"type": "string"}},
        "tuning": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "symbol": {"type": "string"},
                "param": {"type": "string", "enum": list(TUNABLE)},
                "value": {"type": "number"},
            },
            "required": ["symbol", "param", "value"],
            "additionalProperties": False,
        }},
    },
    "required": ["lessons", "tuning"],
    "additionalProperties": False,
}


# $ за 1 млн токенов (вход, выход) — для учёта расходов в админ-панели
PRICES = {
    "claude-fable-5-1": (10.0, 50.0), "claude-fable-5": (10.0, 50.0),
    "claude-opus-5-5": (4.0, 20.0), "claude-opus-5": (5.0, 25.0),
    "claude-sonnet-5": (2.0, 10.0), "claude-haiku-4-5": (1.0, 5.0),
}


def usage_cost(model: str, usage) -> float:
    inp, out = PRICES.get(model, PRICES["claude-opus-5"])
    cache_read = getattr(usage, "cache_read_input_tokens", 0) or 0
    cache_write = getattr(usage, "cache_creation_input_tokens", 0) or 0
    return (usage.input_tokens * inp + cache_read * inp * 0.1 + cache_write * inp * 1.25
            + usage.output_tokens * out) / 1_000_000


class AIAnalyst:
    def __init__(self):
        self._client = None
        self._client_key = None

    @property
    def client(self):
        """Клиент пересоздаётся, если ключ поменяли в админ-панели."""
        key = settings.anthropic_api_key
        if not key:
            return None
        if key != self._client_key:
            self._client, self._client_key = anthropic.AsyncAnthropic(api_key=key), key
        return self._client

    @property
    def enabled(self) -> bool:
        return self.client is not None

    async def _log_usage(self, kind: str, resp) -> None:
        from ..db import AIUsage, Session
        u = resp.usage
        try:
            async with Session() as s:
                s.add(AIUsage(model=resp.model or settings.ai_model, kind=kind, input_tokens=u.input_tokens,
                              output_tokens=u.output_tokens,
                              cache_read_tokens=getattr(u, "cache_read_input_tokens", 0) or 0,
                              cache_write_tokens=getattr(u, "cache_creation_input_tokens", 0) or 0,
                              cost_usd=usage_cost(resp.model or settings.ai_model, u)))
                await s.commit()
        except Exception as e:  # noqa: BLE001 — учёт расходов не должен ломать анализ
            log.warning("Не удалось записать расход ИИ: %s", e)

    def _request_kwargs(self) -> dict:
        model = settings.ai_model
        kw: dict = {"model": model}
        if not model.startswith("claude-haiku"):
            kw["output_config"] = {"effort": "low"}
        if model.startswith(("claude-opus-5", "claude-fable-5")):
            # при отказе фильтров запрос автоматически повторяется на резервной модели
            kw["betas"] = ["server-side-fallback-2026-07-01"]
            kw["fallbacks"] = "default"
        return kw

    async def _json_call(self, system: str, schema: dict, payload: dict, max_tokens: int,
                         kind: str = "analyze") -> Optional[dict]:
        kw = self._request_kwargs()
        kw.setdefault("output_config", {})["format"] = {"type": "json_schema", "schema": schema}
        try:
            resp = await self.client.beta.messages.create(
                max_tokens=max_tokens,
                system=[{"type": "text", "text": system, "cache_control": {"type": "ephemeral"}}],
                messages=[{"role": "user", "content": json.dumps(payload, ensure_ascii=False, sort_keys=True)}],
                **kw,
            )
        except anthropic.RateLimitError:
            log.warning("Claude: лимит запросов, используем запасной алгоритм")
            return None
        except anthropic.APIStatusError as e:
            log.warning("Claude: ошибка API %s: %s", e.status_code, e.message)
            return None
        except anthropic.APIConnectionError:
            log.warning("Claude: нет соединения")
            return None
        await self._log_usage(kind, resp)
        if resp.stop_reason in ("refusal", "max_tokens"):
            log.warning("Claude: ответ не получен (%s)", resp.stop_reason)
            return None
        text = next((b.text for b in resp.content if b.type == "text"), None)
        try:
            return json.loads(text) if text else None
        except json.JSONDecodeError:
            return None

    async def analyze(self, symbol: str, f: Dict[str, float], learner_stats: dict,
                      lessons: List[str], tuning: dict, liq_1h: Dict[str, float], funding: Optional[float]) -> Insight:
        fallback = rule_insight(f, tuning)
        if not self.enabled:
            return fallback
        r = lambda v: round(v, 4)  # noqa: E731
        payload = {
            "symbol": symbol,
            "market": {
                "price": f["price"], "atr_pct": r(f["atr_pct"]), "atr_pct_median_17h": r(f["atr_pct_median"]),
                "adx": r(f["adx"]), "rsi": r(f["rsi"]), "bb_width_pct": r(f["bb_width_pct"]),
                "ret_1h_pct": r(f["ret_1h"]), "ret_4h_pct": r(f["ret_4h"]), "ret_24h_pct": r(f["ret_24h"]),
                "price_vs_ema20_pct": r((f["price"] / f["ema20"] - 1) * 100),
                "ema50_vs_ema200_pct": r((f["ema50"] / f["ema200"] - 1) * 100),
                "distance_from_vwap4h_atr": r(f["dist_vwap_atr"]),
                "funding_rate": funding,
                "liquidations_1h_usd": {k: round(v) for k, v in liq_1h.items()},
            },
            "rule_based_regime": fallback.regime,
            "learning_stats": learner_stats,
            "lessons": lessons[-8:],
            "current_tuning": tuning,
        }
        data = await self._json_call(SYSTEM, INSIGHT_SCHEMA, payload, max_tokens=8000)
        if data is None:
            return fallback
        ins = Insight.clamp(data, "ai")
        if ins.grid_step_atr == TUNABLE["grid_step_atr"][2] and "grid_step_atr" in tuning:
            ins.grid_step_atr = tuning["grid_step_atr"]
        return ins

    async def review_day(self, summary: dict, lessons: List[str]) -> Optional[dict]:
        if not self.enabled:
            return None
        return await self._json_call(REVIEW_SYSTEM, REVIEW_SCHEMA,
                                     {"stats": summary, "current_lessons": lessons[-10:]}, max_tokens=16000,
                                     kind="review")


def apply_tuning(current: dict, param: str, value: float) -> dict:
    """Изменение не больше 20% за раз и в пределах границ."""
    if param not in TUNABLE:
        return current
    lo, hi, dflt = TUNABLE[param]
    old = current.get(param, dflt)
    value = max(old * 0.8, min(old * 1.2, value))
    out = dict(current)
    out[param] = round(max(lo, min(hi, value)), 4)
    return out
