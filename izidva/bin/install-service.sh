#!/usr/bin/env bash
# Запуск торгового движка как постоянной службы systemd (один раз после установки через migrate.php).
#   sudo bash bin/install-service.sh
# Движок работает 24/7, сам перезапускается при сбое и после перезагрузки сервера. Cron не нужен.
set -e
cd "$(dirname "$0")/.."
DIR="$(pwd)"
NAME=izidva-engine

if [ "$(id -u)" != "0" ]; then echo "Запустите с sudo: sudo bash $DIR/bin/install-service.sh"; exit 1; fi
if [ ! -d /run/systemd/system ]; then echo "На сервере нет systemd — запустите вручную: php $DIR/bin/daemon.php"; exit 1; fi

# PHP CLI 8.1+ с нужными расширениями (на панелях бывает несколько версий PHP)
PHP=""
for c in php8.4 php8.3 php8.2 php8.1 php8.5 php; do
  if command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(version_compare(PHP_VERSION,"8.1","<") || !extension_loaded("pdo_mysql") || !extension_loaded("curl") || !extension_loaded("openssl") || !extension_loaded("mbstring") ? 1 : 0);'; then
    PHP="$(command -v "$c")"; break
  fi
done
if [ -z "$PHP" ]; then
  echo "Не найден PHP 8.1+ с расширениями pdo_mysql, curl, openssl, mbstring."
  echo "Установите, например: apt install -y php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring"; exit 1
fi

if [ ! -f storage/.env ]; then echo "Сначала пройдите установку: откройте https://ваш-домен/migrate.php"; exit 1; fi

# служба работает от владельца файлов проекта (как PHP сайта), а не от root
OWNER="$(stat -c %U "$DIR")"
GROUP="$(stat -c %G "$DIR")"
mkdir -p storage/logs
chown -R "$OWNER:$GROUP" storage

cat > /etc/systemd/system/$NAME.service <<UNIT
[Unit]
Description=IZIDVA AI Trader - trading engine
After=network-online.target mysql.service mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=$OWNER
Group=$GROUP
WorkingDirectory=$DIR
ExecStart=$PHP $DIR/bin/daemon.php
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now $NAME
sleep 3
systemctl --no-pager --lines=5 status $NAME || true
echo
echo "Готово: торговый движок запущен как служба $NAME (PHP: $PHP, пользователь: $OWNER)."
echo "Статус: systemctl status $NAME · перезапуск: systemctl restart $NAME · лог: $DIR/storage/logs/app.log"
