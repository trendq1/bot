#!/usr/bin/env bash
# Установка AI Trader на сервер (Ubuntu/Debian, VPS с панелью или без).
# Запуск из папки проекта:  bash install.sh
set -e
cd "$(dirname "$0")"
DIR="$(pwd)"
PORT="${PORT:-8000}"

echo "== AI Trader: установка в $DIR =="

PY=""
for c in python3.12 python3.11 python3.10 python3; do
  if command -v "$c" >/dev/null 2>&1 && "$c" -c 'import sys; sys.exit(0 if sys.version_info >= (3,10) else 1)'; then PY="$c"; break; fi
done
if [ -z "$PY" ]; then
  echo "Нужен Python 3.10+. Установите: sudo apt install -y python3 python3-venv python3-pip"; exit 1
fi
echo "Python: $($PY --version)"

if ! "$PY" -m venv --help >/dev/null 2>&1; then
  echo "Нет модуля venv. Установите: sudo apt install -y python3-venv"; exit 1
fi
[ -d venv ] || "$PY" -m venv venv
venv/bin/pip install --upgrade pip -q
venv/bin/pip install -r requirements.txt -q
mkdir -p logs
chmod +x run.py

if [ "$(id -u)" = "0" ] && [ -d /run/systemd/system ]; then
  cat > /etc/systemd/system/aitrader.service <<UNIT
[Unit]
Description=AI Trader (Bybit bot + Telegram Mini App + admin panel)
After=network-online.target mysql.service mariadb.service
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=$DIR
Environment=PORT=$PORT
ExecStart=$DIR/venv/bin/python $DIR/run.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable --now aitrader
  echo "Служба aitrader запущена и будет стартовать вместе с сервером."
  echo "Управление: systemctl status|restart|stop aitrader · логи: $DIR/logs/app.log"
else
  echo "Нет прав root/systemd — запускаю в фоне через nohup."
  nohup venv/bin/python run.py > logs/run.log 2>&1 &
  echo "PID: $!  (остановить: kill $!)"
fi

sleep 4
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
echo
echo "== Готово =="
echo "1. Откройте в браузере: http://${IP:-SERVER_IP}:$PORT/setup  (или через ваш домен)"
if [ -f SETUP_CODE.txt ]; then echo "2. Код установки: $(cat SETUP_CODE.txt)  (файл SETUP_CODE.txt)"; fi
echo "3. После установки админ-панель: /admin, мини-апп для Telegram: /app/"
