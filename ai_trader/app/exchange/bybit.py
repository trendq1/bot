"""Bybit USDT Perpetual через API v5 (pybit). Вызовы pybit синхронные — выполняем их в потоке."""
import asyncio
from decimal import Decimal
from typing import Dict, List, Optional, Set, Tuple

from pybit.exceptions import InvalidRequestError
from pybit.unified_trading import HTTP

from ..config import settings
from .base import ClosedPnl, OrderResult, Position, fmt

ERR_LEVERAGE_NOT_MODIFIED = 110043


class KeyCheckError(Exception):
    pass


class BybitExchange:
    def __init__(self, api_key: str, api_secret: str, mode: str):
        self.mode = mode                               # demo | live
        self.http = HTTP(demo=mode == "demo", api_key=api_key, api_secret=api_secret)

    async def _call(self, fn, **kw):
        return await asyncio.to_thread(fn, **kw)

    async def equity(self) -> float:
        r = await self._call(self.http.get_wallet_balance, accountType="UNIFIED")
        return float(r["result"]["list"][0]["totalEquity"] or 0)

    async def positions(self) -> Dict[str, Position]:
        r = await self._call(self.http.get_positions, category="linear", settleCoin="USDT")
        return {p["symbol"]: Position(p["symbol"], p["side"], float(p["size"]), float(p["avgPrice"]))
                for p in r["result"]["list"] if float(p["size"]) > 0}

    async def prepare(self) -> None:
        try:
            await self._call(self.http.switch_position_mode, category="linear", coin="USDT", mode=0)
        except InvalidRequestError:
            pass  # уже one-way или есть открытые позиции

    async def set_leverage(self, symbol: str, leverage: int) -> None:
        try:
            await self._call(self.http.set_leverage, category="linear", symbol=symbol,
                             buyLeverage=str(leverage), sellLeverage=str(leverage))
        except InvalidRequestError as e:
            if e.status_code != ERR_LEVERAGE_NOT_MODIFIED:
                raise

    async def place_limit(self, symbol, side, qty: Decimal, price: Decimal, link_id, reduce_only=False) -> None:
        await self._call(self.http.place_order, category="linear", symbol=symbol, side=side, orderType="Limit",
                         qty=fmt(qty), price=fmt(price), timeInForce="GTC", orderLinkId=link_id,
                         reduceOnly=reduce_only, positionIdx=0)

    async def place_market(self, symbol, side, qty: Decimal, stop=None, take=None, reduce_only=False) -> None:
        kw = dict(category="linear", symbol=symbol, side=side, orderType="Market", qty=fmt(qty),
                  reduceOnly=reduce_only, positionIdx=0)
        if stop is not None or take is not None:
            kw["tpslMode"] = "Full"
        if stop is not None:
            kw["stopLoss"] = fmt(stop)
        if take is not None:
            kw["takeProfit"] = fmt(take)
        await self._call(self.http.place_order, **kw)

    async def cancel(self, symbol, link_id) -> None:
        try:
            await self._call(self.http.cancel_order, category="linear", symbol=symbol, orderLinkId=link_id)
        except InvalidRequestError:
            pass  # уже исполнен или отменён

    async def cancel_all(self, symbol) -> None:
        await self._call(self.http.cancel_all_orders, category="linear", symbol=symbol)

    async def open_order_ids(self, symbol) -> Set[str]:
        r = await self._call(self.http.get_open_orders, category="linear", symbol=symbol, limit=50)
        return {o["orderLinkId"] for o in r["result"]["list"] if o.get("orderLinkId")}

    async def order_result(self, symbol, link_id) -> OrderResult:
        r = await self._call(self.http.get_order_history, category="linear", symbol=symbol, orderLinkId=link_id)
        rows = r["result"]["list"]
        if not rows:
            return OrderResult("Unknown", 0.0, 0.0)
        o = rows[0]
        return OrderResult(o["orderStatus"], float(o.get("avgPrice") or 0), float(o.get("cumExecQty") or 0))

    async def closed_pnl(self, symbol, since_ms) -> List[ClosedPnl]:
        r = await self._call(self.http.get_closed_pnl, category="linear", symbol=symbol, startTime=since_ms, limit=50)
        return [ClosedPnl(float(x["closedPnl"]), float(x["avgExitPrice"]), int(x["updatedTime"]))
                for x in r["result"]["list"]]

    async def close_position(self, symbol) -> Optional[Tuple[float, float]]:
        pos = (await self.positions()).get(symbol)
        if not pos:
            return None
        await self.place_market(symbol, "Sell" if pos.side == "Buy" else "Buy",
                                Decimal(str(pos.qty)), reduce_only=True)
        return pos.entry, pos.qty


async def verify_keys(api_key: str, api_secret: str, mode: str) -> dict:
    """Проверяет ключ клиента: права на торговлю, отсутствие права вывода, реферал."""
    http = HTTP(demo=mode == "demo", api_key=api_key, api_secret=api_secret)
    try:
        info = (await asyncio.to_thread(http.get_api_key_information))["result"]
    except Exception as e:  # noqa: BLE001 — показываем клиенту понятную причину
        raise KeyCheckError(f"Bybit отклонил ключ: {e}") from e
    if int(info.get("readOnly", 1)) == 1:
        raise KeyCheckError("Ключ только для чтения. Включи права Contract → Orders и Positions.")
    if "Withdraw" in (info.get("permissions", {}).get("Wallet") or []):
        raise KeyCheckError("У ключа есть право вывода средств. Создай ключ БЕЗ права Withdraw.")
    uid = str(info.get("userID", ""))
    referral_ok = await is_referral(uid) if mode == "live" else True
    if mode == "live" and settings.require_referral and not referral_ok:
        raise KeyCheckError("Аккаунт Bybit зарегистрирован не по нашей реферальной ссылке. "
                            f"Зарегистрируйся по ссылке: {settings.referral_link}")
    return {"uid": uid, "referral_ok": referral_ok}


async def is_referral(uid: str) -> bool:
    """Клиент считается рефералом, если партнёрский API Bybit знает его UID."""
    if not settings.affiliate_api_key or not uid:
        return not settings.require_referral
    http = HTTP(api_key=settings.affiliate_api_key, api_secret=settings.affiliate_api_secret)
    try:
        r = await asyncio.to_thread(http.get_affiliate_user_info, uid=uid)
        return bool(r.get("result", {}).get("uid"))
    except InvalidRequestError:
        return False
