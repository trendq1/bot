#!/usr/bin/env python3
"""Запуск AI Trader.

    python run.py

Следит за приложением: после установки (/setup) и по кнопке «Перезапустить»
в админ-панели приложение завершается с кодом 3 — run.py сразу запускает его снова.
При падении — повторный запуск через 5 секунд.
"""
import os
import signal
import subprocess
import sys
import time

BASE = os.path.dirname(os.path.abspath(__file__))
RESTART_CODE = 3


def env_value(name: str, default: str) -> str:
    path = os.path.join(BASE, ".env")
    if os.path.exists(path):
        with open(path, encoding="utf-8") as f:
            for line in f:
                if line.strip().startswith(name + "="):
                    return line.split("=", 1)[1].strip()
    return os.getenv(name, default)


child = None


def _stop(signum, frame):
    """systemctl stop / kill: корректно останавливаем и само приложение."""
    if child and child.poll() is None:
        child.terminate()
        try:
            child.wait(timeout=20)
        except subprocess.TimeoutExpired:
            child.kill()
    sys.exit(0)


def main() -> None:
    global child
    os.chdir(BASE)
    signal.signal(signal.SIGTERM, _stop)
    while True:
        host, port = env_value("HOST", "0.0.0.0"), env_value("PORT", "8000")
        cmd = [sys.executable, "-m", "uvicorn", "app.main:app", "--host", host, "--port", port,
               "--proxy-headers", "--forwarded-allow-ips", "*"]
        print(f"[run.py] старт: http://{host}:{port}", flush=True)
        try:
            child = subprocess.Popen(cmd)
            code = child.wait()
        except KeyboardInterrupt:
            _stop(None, None)
            return
        if code == RESTART_CODE:
            print("[run.py] перезапуск по запросу", flush=True)
            continue
        if code in (0, -2, 130):          # штатная остановка / Ctrl+C
            return
        print(f"[run.py] приложение упало (код {code}) — перезапуск через 5 с", flush=True)
        time.sleep(5)


if __name__ == "__main__":
    main()
