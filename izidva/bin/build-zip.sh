#!/usr/bin/env bash
# Сборка архива для загрузки через файловый менеджер: izidva.zip (с папкой vendor, без тестов и секретов).
set -e
cd "$(dirname "$0")/.."
composer install --no-dev --prefer-dist --optimize-autoloader -q
printf 'Require all denied\n' > vendor/.htaccess
OUT="${1:-izidva.zip}"
rm -f "$OUT"
TMP="$(mktemp -d)"
mkdir -p "$TMP/izidva"
tar --exclude=./storage/.env --exclude='./storage/logs/*.log' --exclude=./storage/setup_code.php \
    --exclude=./storage/login_attempts.json --exclude=./storage/setup_attempts --exclude=./storage/daemon.lock \
    --exclude='./storage/tmp_broadcast_*' \
    --exclude=./tests --exclude='.git' --exclude='./*.zip' --exclude='./vendor/*/*/tests' --exclude='./vendor/*/*/docs' \
    --exclude='./vendor/*/*/examples' -cf - . | tar -xf - -C "$TMP/izidva"
# Версия в ссылках на стили/скрипты мини-аппа = время сборки: иначе Telegram/браузер держат старый app.js
# с новым index.html (так получалось «Cannot set properties of null» после удаления блоков).
sed -i "s/\?v=[0-9A-Za-z]*/?v=$(date +%Y%m%d%H%M%S)/g" "$TMP/izidva/public/app/index.html"
(cd "$TMP" && zip -q -r -9 izidva.zip izidva)
mv "$TMP/izidva.zip" "$OUT"
rm -rf "$TMP"
echo "Готово: $OUT ($(du -h "$OUT" | cut -f1))"
