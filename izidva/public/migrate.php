<?php
/**
 * Установка и обновление базы данных.
 *  - Первый запуск: открыть https://ваш-домен/migrate.php в браузере, ввести код из storage/setup_code.php,
 *    данные MySQL и логин администратора — будут созданы таблицы, storage/.env и администратор.
 *  - Обновление версии: загрузить новые файлы и снова открыть migrate.php (нужен вход в админ-панель) —
 *    применятся новые миграции из папки migrations/.
 *  - Из консоли: php public/migrate.php — применяет новые миграции.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Crypto;
use App\DB;
use App\Env;
use App\Migrator;
use App\Settings;
use App\Telegram;
use App\Web\AdminApi;
use App\Web\Api;

const CODE_FILE = STORAGE_DIR . '/setup_code.php';

// ───────────── консоль ─────────────
if (PHP_SAPI === 'cli') {
    if (!Env::configured()) {
        exit("Приложение не установлено — откройте migrate.php в браузере.\n");
    }
    $applied = Migrator::run(DB::pdo());
    echo $applied ? 'Применены миграции: ' . implode(', ', $applied) . "\n" : "Новых миграций нет.\n";
    exit(0);
}

function setupCode(): string
{
    if (!is_file(CODE_FILE)) {
        @mkdir(STORAGE_DIR, 0750, true);
        $code = strtoupper(bin2hex(random_bytes(4)));
        file_put_contents(CODE_FILE, "<?php exit; // Код установки: $code\n");
    }
    $raw = (string)file_get_contents(CODE_FILE);
    return preg_match('/Код установки:\s*([A-Z0-9]+)/', $raw, $m) ? $m[1] : '';
}

function baseUrl(): string
{
    return AdminApi::baseUrl();
}

function serviceCommand(): string
{
    return 'sudo bash ' . realpath(BASE_DIR) . '/bin/install-service.sh';
}

// ───────────── JSON-действия ─────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Api::run(function () {
        $b = Api::body();
        $action = (string)($b['action'] ?? '');
        if ($action === 'migrate') {
            if (!Env::configured()) {
                Api::fail(400, 'Сначала выполните установку');
            }
            $admin = AdminApi::currentAdmin(false);
            $applied = Migrator::run(DB::pdo());
            AdminApi::audit($admin['username'], 'migrate', implode(', ', $applied));
            return ['ok' => true, 'applied' => $applied];
        }
        if (Env::configured()) {
            Api::fail(404, 'Уже установлено');
        }
        $attempts = STORAGE_DIR . '/setup_attempts';
        $fails = (int)@file_get_contents($attempts);
        if ($fails >= 20) {
            Api::fail(429, 'Слишком много неверных попыток. Удалите файл storage/setup_attempts.');
        }
        if (strtoupper(trim((string)($b['code'] ?? ''))) !== setupCode()) {
            file_put_contents($attempts, (string)($fails + 1));
            Api::fail(403, 'Неверный код установки. Он в файле storage/setup_code.php.');
        }
        $db = $b['db'] ?? [];
        try {
            $pdo = DB::connect((string)($db['host'] ?? 'localhost'), (int)($db['port'] ?? 3306), (string)($db['name'] ?? ''),
                (string)($db['user'] ?? ''), (string)($db['password'] ?? ''));
            $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (PDOException $e) {
            Api::fail(400, 'Не удалось подключиться к MySQL: ' . $e->getMessage());
        }
        if ($action === 'test_db') {
            return ['ok' => true, 'version' => $version];
        }
        if ($action !== 'install') {
            Api::fail(400, 'Неизвестное действие');
        }
        $user = Api::str($b, 'admin_username', 3, 64);
        if (!preg_match('/^[A-Za-z0-9_.-]+$/', $user)) {
            Api::fail(400, 'Логин администратора: латиница, цифры, _ . -');
        }
        $pass = Api::str($b, 'admin_password', 8, 128);

        Migrator::run($pdo);
        Env::write(['DB_HOST' => (string)$db['host'], 'DB_PORT' => (string)(int)$db['port'], 'DB_NAME' => (string)$db['name'],
            'DB_USER' => (string)$db['user'], 'DB_PASS' => (string)$db['password'],
            'APP_KEY' => Crypto::newKey(), 'SECRET_KEY' => bin2hex(random_bytes(32))]);
        DB::reset();
        if (!DB::val('SELECT 1 FROM admin_users WHERE username = ?', [$user])) {
            DB::insert('admin_users', ['username' => $user, 'password_hash' => Crypto::hashPassword($pass), 'created_at' => DB::now()]);
        }
        $initial = array_filter([
            'bot_token' => trim((string)($b['bot_token'] ?? '')),
            'webapp_url' => trim((string)($b['webapp_url'] ?? '')) ?: baseUrl() . '/app/',
            'anthropic_api_key' => trim((string)($b['anthropic_api_key'] ?? '')),
        ]);
        Settings::save($initial);
        $telegram = null;
        if (!empty($initial['bot_token'])) {
            try {
                Telegram::setup(baseUrl());
                $telegram = 'Telegram-бот подключён';
            } catch (Throwable $e) {
                $telegram = 'Telegram: ' . $e->getMessage() . ' (проверьте токен в админ-панели)';
            }
        }
        @unlink(CODE_FILE);
        @unlink($attempts);
        return ['ok' => true, 'telegram' => $telegram, 'service_command' => serviceCommand()];
    });
    exit;
}

// ───────────── страница ─────────────
$configured = Env::configured();
$pending = [];
$adminOk = false;
if ($configured) {
    try {
        $adminOk = App\Crypto::readSession($_COOKIE[AdminApi::COOKIE] ?? null) !== null;
        $pending = array_map('basename', Migrator::pending(DB::pdo()));
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
} else {
    setupCode();
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AI Trader · Установка</title>
<link rel="stylesheet" href="admin/admin.css">
</head>
<body class="setup">
<div class="setup-box">
<?php if (!$configured): ?>
  <h1>⚙️ Установка AI Trader</h1>
  <p class="muted">Заполните один раз — будут созданы таблицы в MySQL, файл настроек и администратор.</p>
  <div class="card">
    <h3>1. Код установки</h3>
    <p class="muted small">Откройте файловым менеджером <b>storage/setup_code.php</b> в папке проекта (текстом, не в браузере) и вставьте код после «Код установки:».</p>
    <input class="inp" id="code" placeholder="Например A1B2C3D4" autocomplete="off">
  </div>
  <div class="card">
    <h3>2. База данных MySQL</h3>
    <p class="muted small">Создайте базу и пользователя в панели хостинга (кодировка utf8mb4).</p>
    <div class="grid2">
      <label>Хост<input class="inp" id="dbHost" value="localhost"></label>
      <label>Порт<input class="inp" id="dbPort" value="3306" inputmode="numeric"></label>
      <label>Имя базы<input class="inp" id="dbName"></label>
      <label>Пользователь<input class="inp" id="dbUser"></label>
    </div>
    <label>Пароль базы<input class="inp" id="dbPass" type="password"></label>
    <button class="btn ghost" id="testDb" style="margin-top:10px">Проверить подключение</button>
    <div class="note" id="dbResult"></div>
  </div>
  <div class="card">
    <h3>3. Администратор</h3>
    <div class="grid2">
      <label>Логин<input class="inp" id="adUser" value="admin"></label>
      <label>Пароль (от 8 символов)<input class="inp" id="adPass" type="password"></label>
    </div>
    <label>Повторите пароль<input class="inp" id="adPass2" type="password"></label>
  </div>
  <div class="card">
    <h3>4. Сервисы <span class="muted small">(можно позже в админ-панели)</span></h3>
    <label>Токен Telegram-бота (@BotFather)<input class="inp" id="botToken" placeholder="123456:ABC..."></label>
    <label>Адрес мини-аппа (HTTPS)<input class="inp" id="webappUrl" value="<?= $e(baseUrl() . '/app/') ?>"></label>
    <label>Ключ Anthropic (Claude)<input class="inp" id="aiKey" type="password" placeholder="sk-ant-..."></label>
  </div>
  <button class="btn primary big" id="install">Установить</button>
  <div class="note" id="result"></div>
  <div class="card" id="done" hidden>
    <h3>✅ Установка завершена</h3>
    <p><b>Последний шаг — запустить торговый движок.</b> Он работает постоянно (не через cron). Выполните один раз в терминале сервера:</p>
    <pre class="log" id="cmd"></pre>
    <p class="muted small">Сайт, админ-панель, мини-апп и Telegram-бот уже работают. Без этой команды не будет только торговли.</p>
    <a class="btn primary big" href="admin/" style="display:block;text-align:center;text-decoration:none">Открыть админ-панель</a>
  </div>
<?php elseif (isset($dbError)): ?>
  <h1>⚠️ Нет подключения к базе</h1>
  <div class="card note err"><?= $e($dbError) ?></div>
  <p class="muted">Проверьте storage/.env и что MySQL запущен.</p>
<?php else: ?>
  <h1>🗄 Обновление базы данных</h1>
  <?php if (!$adminOk): ?>
    <div class="card">Войдите в <a href="admin/">админ-панель</a>, затем откройте эту страницу снова.</div>
  <?php elseif (!$pending): ?>
    <div class="card">✅ База данных в актуальном состоянии. Новых миграций нет.</div>
    <a class="btn primary" href="admin/">В админ-панель</a>
  <?php else: ?>
    <div class="card"><h3>Новые миграции</h3><ul><?php foreach ($pending as $p): ?><li><?= $e($p) ?></li><?php endforeach ?></ul>
      <p class="muted small">Перед обновлением сделайте резервную копию базы в панели хостинга.</p>
      <button class="btn primary" id="migrate">Применить</button><div class="note" id="result"></div></div>
  <?php endif ?>
<?php endif ?>
</div>
<script>
const $ = (id) => document.getElementById(id);
async function post(body) {
  const r = await fetch("migrate.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body), credentials: "same-origin" });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(d.detail || "Ошибка " + r.status);
  return d;
}
function show(id, text, ok) { const el = $(id); el.textContent = text; el.className = "note " + (ok ? "ok" : "err"); }
const db = () => ({ host: $("dbHost").value.trim(), port: Number($("dbPort").value || 3306), name: $("dbName").value.trim(),
                    user: $("dbUser").value.trim(), password: $("dbPass").value });
if ($("testDb")) $("testDb").onclick = async () => {
  try { const r = await post({ action: "test_db", code: $("code").value, db: db() }); show("dbResult", "✅ Подключено: " + r.version, true); }
  catch (e) { show("dbResult", "❌ " + e.message, false); }
};
if ($("install")) $("install").onclick = async () => {
  if ($("adPass").value !== $("adPass2").value) return show("result", "❌ Пароли не совпадают", false);
  const btn = $("install"); btn.disabled = true; btn.textContent = "Устанавливаем…";
  try {
    const r = await post({ action: "install", code: $("code").value, db: db(), admin_username: $("adUser").value.trim(),
      admin_password: $("adPass").value, bot_token: $("botToken").value.trim(), webapp_url: $("webappUrl").value.trim(),
      anthropic_api_key: $("aiKey").value.trim() });
    show("result", r.telegram ? "✅ " + r.telegram : "", !!r.telegram && !r.telegram.startsWith("Telegram:"));
    $("cmd").textContent = r.service_command;
    document.querySelectorAll(".card:not(#done)").forEach((c) => (c.hidden = true));
    btn.hidden = true; $("done").hidden = false;
  } catch (e) { show("result", "❌ " + e.message, false); btn.disabled = false; btn.textContent = "Установить"; }
};
if ($("migrate")) $("migrate").onclick = async () => {
  try { const r = await post({ action: "migrate" }); show("result", "✅ Применено: " + (r.applied.join(", ") || "ничего"), true); setTimeout(() => location.reload(), 1500); }
  catch (e) { show("result", "❌ " + e.message, false); }
};
</script>
</body>
</html>
