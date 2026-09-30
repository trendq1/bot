#!/usr/bin/env python3
"""
Ридер сигналов: отдельный аккаунт Telegram читает посты каналов и передаёт их боту (POST /tg/signal-ingest.php).

  python3 reader.py --login   # один раз: вход по номеру телефона и коду из Telegram
  python3 reader.py --list    # показать каналы аккаунта с их id (для CHANNELS)
  python3 reader.py           # рабочий режим (под systemd)

Настройки — в файле reader.env рядом со скриптом (см. reader.env.example). Используйте ОТДЕЛЬНЫЙ номер/аккаунт,
не основной: пользовательские аккаунты для автоматизации — серая зона правил Telegram.
"""
import asyncio
import json
import os
import sys
import urllib.request
from pathlib import Path

from telethon import TelegramClient, events

HERE = Path(__file__).resolve().parent


def load_env():
    f = HERE / "reader.env"
    if f.exists():
        for line in f.read_text(encoding="utf-8").splitlines():
            line = line.strip()
            if line and not line.startswith("#") and "=" in line:
                k, v = line.split("=", 1)
                os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


load_env()
API_ID = int(os.environ.get("API_ID", "0"))
API_HASH = os.environ.get("API_HASH", "")
INGEST_URL = os.environ.get("INGEST_URL", "")          # https://ваш-сайт/tg/signal-ingest.php
INGEST_TOKEN = os.environ.get("INGEST_TOKEN", "")      # токен из админки → Сигналы → Внешний ридер
# Каналы: @username или числовой id через запятую. Пусто = ни одного (безопасно). ALL = все каналы аккаунта.
CHANNELS = [c.strip() for c in os.environ.get("CHANNELS", "").split(",") if c.strip()]
SESSION = str(HERE / os.environ.get("SESSION_NAME", "reader"))

seen = set()


def post_json(payload: dict) -> str:
    req = urllib.request.Request(INGEST_URL, data=json.dumps(payload).encode("utf-8"),
                                 headers={"Content-Type": "application/json", "X-Signal-Token": INGEST_TOKEN}, method="POST")
    with urllib.request.urlopen(req, timeout=20) as r:
        return r.read().decode("utf-8", "replace")


async def send(payload: dict):
    for attempt in range(4):
        try:
            res = await asyncio.to_thread(post_json, payload)
            print(f"[ok] {payload['channel_name']}: {res[:200]}", flush=True)
            return
        except Exception as e:  # сеть/сервер — повторяем с паузой
            print(f"[retry {attempt + 1}] {e}", flush=True)
            await asyncio.sleep(2 ** attempt)
    print("[fail] пост не доставлен", flush=True)


def parse_targets():
    out = []
    for c in CHANNELS:
        if c.upper() == "ALL":
            return None
        out.append(int(c) if c.lstrip("-").isdigit() else c)
    return out


async def main():
    if not API_ID or not API_HASH:
        sys.exit("Задайте API_ID и API_HASH в reader.env (получить: https://my.telegram.org → API development tools)")
    client = TelegramClient(SESSION, API_ID, API_HASH)
    if "--login" in sys.argv:
        await client.start()                                # спросит телефон и код
        print("Вход выполнен, сессия сохранена.")
        await client.disconnect()
        return
    await client.connect()
    if not await client.is_user_authorized():
        sys.exit("Нет входа. Сначала выполните: python3 reader.py --login")
    if "--list" in sys.argv:
        async for d in client.iter_dialogs():
            if d.is_channel and not d.is_group:
                print(f"{d.id}\t{getattr(d.entity, 'username', None) or ''}\t{d.name}")
        await client.disconnect()
        return
    if not INGEST_URL or not INGEST_TOKEN:
        sys.exit("Задайте INGEST_URL и INGEST_TOKEN в reader.env")
    targets = parse_targets()
    if targets == []:
        sys.exit("Задайте CHANNELS в reader.env (каналы через запятую или ALL). Список: python3 reader.py --list")

    @client.on(events.NewMessage(chats=targets))
    async def on_post(event):
        if not event.is_channel or event.is_group:
            return
        text = (event.message.message or "").strip()
        key = (event.chat_id, event.message.id)
        if not text or key in seen:
            return
        seen.add(key)
        if len(seen) > 5000:
            seen.clear()
        chat = await event.get_chat()
        await send({"channel_id": str(event.chat_id), "channel_name": getattr(chat, "title", "") or str(event.chat_id), "text": text})

    print("Ридер запущен. Каналы:", "ВСЕ" if targets is None else targets, flush=True)
    await client.run_until_disconnected()


if __name__ == "__main__":
    asyncio.run(main())
