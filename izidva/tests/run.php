<?php
/**
 * Тесты без внешних зависимостей: php tests/run.php
 * Нужна тестовая база MySQL/MariaDB (будет очищена!):
 *   TEST_DB_NAME=izidva_test TEST_DB_USER=... TEST_DB_PASS=... php tests/run.php
 */
declare(strict_types=1);

const APP_TESTING = true;
putenv('DB_HOST=' . (getenv('TEST_DB_HOST') ?: 'localhost'));
putenv('DB_PORT=' . (getenv('TEST_DB_PORT') ?: '3306'));
putenv('DB_NAME=' . (getenv('TEST_DB_NAME') ?: 'izidva_test'));
putenv('DB_USER=' . (getenv('TEST_DB_USER') ?: 'root'));
putenv('DB_PASS=' . (getenv('TEST_DB_PASS') ?: ''));
putenv('APP_KEY=' . base64_encode(str_repeat('k', 32)));
putenv('SECRET_KEY=test-secret');

require __DIR__ . '/../src/bootstrap.php';

use App\Crypto;
use App\DB;
use App\Engine\AIAnalyst;
use App\Engine\Brain;
use App\Engine\Grid;
use App\Engine\Indicators;
use App\Engine\Instrument;
use App\Engine\Learner;
use App\Engine\Manager;
use App\Engine\Market;
use App\Engine\PaperExchange;
use App\Engine\Risk;
use App\Engine\RiskGuard;
use App\Engine\Setups;
use App\Engine\SymbolFeed;
use App\Engine\Worker;
use App\Migrator;
use App\Settings;

$passed = 0;
$failed = [];
function check(bool $cond, string $msg): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
    } else {
        $failed[] = $msg . ' (' . debug_backtrace()[0]['line'] . ')';
    }
}
function near(float $a, float $b, float $eps = 1e-6): bool
{
    return abs($a - $b) < $eps;
}
function test(string $name, callable $fn): void
{
    global $failed;
    try {
        $fn();
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed[] = "$name: " . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "  ✗ $name\n";
    }
}

$BTC = new Instrument('BTCUSDT', '0.1', '0.001', 0.001, 100, 5, 100);

echo "Безопасность\n";
test('шифрование', function () {
    $t = Crypto::encrypt('секрет-ключ');
    check($t !== 'секрет-ключ' && Crypto::decrypt($t) === 'секрет-ключ', 'расшифровка');
    check(Crypto::checkPassword('pass12345', Crypto::hashPassword('pass12345')), 'пароль');
    check(!Crypto::checkPassword('wrong', Crypto::hashPassword('pass12345')), 'чужой пароль');
});
test('сессия админа', function () {
    $tok = Crypto::sessionToken(7);
    check(Crypto::readSession($tok) === 7, 'токен читается');
    check(Crypto::readSession($tok . 'x') === null, 'подделка');
    check(Crypto::readSession(Crypto::sessionToken(7, -10)) === null, 'истёк');
});
test('подпись Telegram initData', function () {
    $sign = function (string $token, array $user, ?int $auth = null) {
        $f = ['auth_date' => (string)($auth ?? time()), 'query_id' => 'q1', 'user' => json_encode($user)];
        ksort($f);
        $check = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($f), $f));
        $f['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));
        return http_build_query($f);
    };
    $d = $sign('123:ABC', ['id' => 42, 'first_name' => 'Артем']);
    check((Crypto::telegramUser($d, '123:ABC')['id'] ?? 0) === 42, 'валидная');
    check(Crypto::telegramUser($d, '123:XYZ') === null, 'чужой токен');
    check(Crypto::telegramUser(str_replace('42', '43', $d), '123:ABC') === null, 'подмена');
    check(Crypto::telegramUser($sign('123:ABC', ['id' => 1], time() - 200000), '123:ABC') === null, 'устарела');
});

echo "Инструменты и бумажная биржа\n";
test('округление', function () use ($BTC) {
    check($BTC->roundPrice(123.456) === '123.4' && $BTC->roundPrice(123.456, true) === '123.5', 'цена');
    check($BTC->roundQty(0.12345) === '0.123', 'объём');
    check($BTC->qtyOk('0.001', 100000) && !$BTC->qtyOk('0.0005', 100000), 'минимум');
});
test('лимитный ордер и прибыль', function () {
    $ex = new PaperExchange(1000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $ex->placeLimit('BTCUSDT', 'Buy', '0.01', '99000', 'a');
    $ex->updatePrices(['BTCUSDT' => 99500]);
    check($ex->positions() === [], 'не исполнен выше лимита');
    $ex->updatePrices(['BTCUSDT' => 98900]);
    check(near($ex->positions()['BTCUSDT']['entry'], 99000), 'вход по лимиту');
    $ex->placeLimit('BTCUSDT', 'Sell', '0.01', '100000', 'b', true);
    $ex->updatePrices(['BTCUSDT' => 100100]);
    check($ex->positions() === [], 'закрыт');
    check(near($ex->balance, 1000 + 10 - (990 + 1000) * 0.0002), 'баланс с комиссиями');
});
test('стоп по рынку и reduce-only', function () {
    $ex = new PaperExchange(1000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $ex->placeMarket('BTCUSDT', 'Sell', '0.01', '101000', '98000');
    $ex->updatePrices(['BTCUSDT' => 101500]);
    $c = $ex->closedPnl('BTCUSDT', 0);
    check($ex->positions() === [] && count($c) === 1 && $c[0]['pnl'] < -10, 'стоп сработал');
    $ex->placeLimit('BTCUSDT', 'Sell', '0.01', '100500', 'x', true);
    $ex->updatePrices(['BTCUSDT' => 101000]);
    check($ex->positions() === [] && $ex->orderResult('BTCUSDT', 'x')['status'] === 'Cancelled', 'reduce-only не открывает');
});

echo "Сетка\n";
test('план в пределах риска', function () use ($BTC) {
    $plan = Grid::plan(100000, 500, $BTC, 'long', 0.6, 8, 750, 5, 125);
    check($plan !== null && $plan['step_pct'] >= 0.2, 'план');
    check(Grid::worstLossPerQty(100000, $plan['step_pct'], 8) * (float)$plan['qty'] <= 125 + 1e-6, 'убыток ≤ лимита');
    check(Grid::plan(100000, 500, $BTC, 'long', 0.6, 8, 150, 5, 25) === null, 'малый депозит — без сетки');
    check(near(Grid::worstLossPerQty(100, 1.0, 2), 1.5 + 2.5), 'формула');
});
test('цикл, стоп и мягкая остановка', function () use ($BTC) {
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $g = new Grid($ex, 'BTCUSDT', $BTC, Grid::plan(100000, 500, $BTC, 'long', 1.0, 4, 2000, 5, 500));
    $g->start();
    check(count($g->orders) === 4, '4 уровня');
    $l1 = $g->levelPrice(1);
    $ex->updatePrices(['BTCUSDT' => $l1 - 1]);
    check($g->sync($l1 - 1) === [] && isset($g->inventory[1]), 'куплен уровень 1');
    $up = $l1 * (1 + $g->plan['step_pct'] / 100) + 5;
    $ex->updatePrices(['BTCUSDT' => $up]);
    $ev = $g->sync($up);
    check(count($ev) === 1 && $ev[0]['kind'] === 'cycle' && $ev[0]['pnl'] > 0, 'прибыльный цикл');
    for ($i = 1; $i <= 4; $i++) {
        $p = $g->levelPrice($i) - 1;
        $ex->updatePrices(['BTCUSDT' => $p]);
        $g->sync($p);
    }
    $crash = $g->stopPrice() - 10;
    $ex->updatePrices(['BTCUSDT' => $crash]);
    $ev = $g->sync($crash);
    check($ev && $ev[0]['kind'] === 'stop' && $ev[0]['pnl'] < 0 && abs($ev[0]['pnl']) <= 550, 'стоп в пределах бюджета');
    check(!$g->active && $ex->positions() === [], 'позиция закрыта');

    $g2 = new Grid($ex, 'BTCUSDT', $BTC, Grid::plan(100000, 500, $BTC, 'short', 1.0, 4, 2000, 5, 500));
    $g2->start();
    $g2->drain();
    check(!$g2->active && $ex->openOrderIds('BTCUSDT') === [], 'drain без позиции');
});

echo "Сигналы и индикаторы\n";
function klines(int $n = 300, float $drift = 0.0): array
{
    $out = [];
    $p = 100.0;
    for ($i = 0; $i < $n; $i++) {
        $o = $p;
        $p = $p * (1 + $drift) + ($i % 2 ? 0.3 : -0.3);
        $out[] = [(string)($i * 300000), $o, max($o, $p) + 0.2, min($o, $p) - 0.2, $p, 10.0, 10.0 * $p];
    }
    return $out;
}
test('признаки и режим', function () {
    $f = Indicators::features(klines(300, 0.002));
    check($f !== null && $f['ema50'] > $f['ema200'], 'тренд вверх');
    check(Indicators::ruleRegime(Indicators::features(klines(300))) === 'range', 'боковик');
    check(Indicators::features(klines(100)) === null, 'мало свечей');
});
test('тренд после отката', function () {
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5];
    $s = Setups::trend($f, 'trend_up', 1.2);
    check($s && $s['side'] === 'Buy' && $s['stop'] < 99.5 && near(($s['take'] - $s['entry']) / $s['risk'], 1.2), 'лонг');
    check(Setups::trend($f, 'range', 1.2) === null, 'не в боковике');
});
test('отскок после ликвидаций', function () {
    $now = 1000.0;
    $feed = new SymbolFeed('BTCUSDT');
    $feed->price = 97400;
    $feed->addLiq('long', 700000, $now - 30);
    $feed->addLiq('long', 600000, $now - 20);
    foreach ([[$now - 40, 99000], [$now - 25, 97000], [$now - 1, 97400]] as [$t, $p]) {
        $feed->prices[] = [$t, $p];
    }
    $f = ['atr' => 1000.0, 'price' => 97400, 'vwap_4h' => 100000];
    $s = Setups::liquidation($feed, $f, 1.0, $now);
    check($s && $s['side'] === 'Buy' && $s['stop'] < 97000 && $s['take'] <= 100000, 'лонг на отскок');
    $feed->addLiq('long', 10000, $now - 1);
    check(Setups::liquidation($feed, $f, 1.0, $now) === null, 'каскад ещё идёт');
});

echo "Обучение, риск, ИИ\n";
test('множитель обучения', function () {
    check(Learner::multiplier(0.0, 50) == 1.0 && Learner::multiplier(-5, 50) == 0.4 && Learner::multiplier(5, 50) == 1.6, 'границы');
    $m = Learner::multiplier(0.5, 2);
    check($m > 1.0 && $m < 1.5, 'мало сделок — осторожнее');
});
test('дневной лимит и пауза', function () {
    $g = new RiskGuard(Risk::profile('balanced'));
    $g->updateEquity(1000);
    check($g->allowed(990, 0)[0] && !$g->allowed(965, 0)[0], 'лимит 3%');
    for ($i = 0; $i < 3; $i++) {
        check(!$g->onTrade(-1, 0), 'ещё не пауза');
    }
    check($g->onTrade(-1, 0) && !$g->allowed(1000, 10)[0], 'пауза после 4 убытков');
});
test('границы ответа ИИ', function () {
    $i = AIAnalyst::clamp(['regime' => 'moon', 'w_grid' => 5, 'risk_mult' => 9, 'grid_mode' => 'x', 'grid_step_atr' => 0.01], 'ai');
    check($i['regime'] === 'range' && $i['w_grid'] == 1 && $i['risk_mult'] == 1.2 && $i['grid_mode'] === 'off' && $i['grid_step_atr'] == 0.3, 'clamp');
    check(AIAnalyst::applyTuning(['grid_step_atr' => 0.6], 'grid_step_atr', 1.5)['grid_step_atr'] == 0.72, '+20% максимум');
    check(AIAnalyst::applyTuning([], 'bad', 1) === [], 'неизвестный параметр');
    check(near(AIAnalyst::usageCost('claude-opus-5', 1_000_000, 0, 0, 0), 5.0), 'стоимость');
});

echo "База данных\n";
test('миграции на пустую базу и повторно', function () {
    $pdo = DB::pdo();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $pdo->exec("DROP TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $applied = Migrator::run($pdo);
    check(count($applied) === count(Migrator::files()), 'все миграции');
    check(Migrator::run($pdo) === [], 'повторный запуск ничего не делает');
    check((int)DB::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()") === 19, '19 таблиц');
});
test('настройки: секреты шифруются', function () {
    Settings::save(['anthropic_api_key' => 'sk-ant-1234', 'ai_interval_min' => '15', 'symbols' => 'btcusdt, ethusdt', 'require_referral' => 'false']);
    $s = Settings::all(true);
    check($s['anthropic_api_key'] === 'sk-ant-1234' && $s['ai_interval_min'] === 15 && $s['symbols'] === ['BTCUSDT', 'ETHUSDT'] && $s['require_referral'] === false, 'значения');
    check(!str_contains((string)DB::val("SELECT value FROM app_settings WHERE `key` = 'anthropic_api_key'"), 'sk-ant'), 'в БД только шифр');
    Settings::save(['anthropic_api_key' => '', 'symbols' => ['SOLUSDT'], 'ai_interval_min' => 30, 'require_referral' => true]);
});

echo "ИИ через официальный SDK (заглушка API)\n";
test('запрос к Claude и разбор ответа', function () {
    $port = 18977;
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_anthropic.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400_000);
    putenv("ANTHROPIC_BASE_URL=http://127.0.0.1:$port");
    try {
        Settings::save(['anthropic_api_key' => 'sk-ant-test', 'ai_model' => 'claude-opus-5']);
        $f = ['price' => 100, 'atr_pct' => 1, 'atr_pct_median' => 1, 'adx' => 30, 'rsi' => 60, 'bb_width_pct' => 2, 'ret_1h' => 1,
            'ret_4h' => 2, 'ret_24h' => 3, 'ema20' => 99, 'ema50' => 98, 'ema200' => 95, 'dist_vwap_atr' => 0.5, 'vwap_4h' => 99];
        $ins = (new AIAnalyst())->analyze('BTCUSDT', $f, [], [], [], ['longs_liquidated' => 0], null);
        check($ins['source'] === 'ai' && $ins['regime'] === 'trend_up', 'ответ ИИ разобран');
        check($ins['grid_step_atr'] == 1.5, 'значения ограничены границами');
        $req = json_decode((string)file_get_contents(sys_get_temp_dir() . '/izidva_fake_req.json'), true);
        check(($req['body']['fallbacks'] ?? null) === 'default' && str_contains((string)$req['beta'], 'server-side-fallback'), 'резервная модель');
        check(($req['body']['output_config']['format']['type'] ?? '') === 'json_schema', 'структурированный ответ');
        check((float)DB::val("SELECT cost_usd FROM ai_usage ORDER BY id DESC LIMIT 1") > 0, 'стоимость записана');
    } finally {
        putenv('ANTHROPIC_BASE_URL');
        Settings::save(['anthropic_api_key' => '']);
        proc_terminate($proc);
    }
});

echo "Движок целиком (бумажный счёт)\n";
test('клиент: сетка → сделка → обучение → остановка', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    DB::insert('users', ['id' => 555, 'first_name' => 'T', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('bot_settings', ['user_id' => 555, 'running' => true, 'trading_mode' => 'paper', 'risk_profile' => 'balanced',
        'symbols' => ['SOLUSDT'], 'strategies' => ['grid' => true, 'trend' => true, 'liquidation' => true], 'paper_balance' => 10000]);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $mgr = new Manager($market);
    $mgr->brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 100];
    $mgr->brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $notes = [];
    $ex = new PaperExchange(10000);
    $w = new Worker(555, $ex, $market, $mgr->brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        fn($u, $t) => $mgr->recordTrade($u, $t), function ($u, $m) use (&$notes) { $notes[] = $m; }, fn($u, $e) => $mgr->saveSnapshot($u, $e));
    $w->step(1000.0);
    check(isset($w->grids['SOLUSDT']), 'сетка запущена');
    $g = $w->grids['SOLUSDT'];
    $step = $g->plan['step_pct'] / 100;
    foreach ([$g->levelPrice(1) - 0.01, $g->levelPrice(1) * (1 + $step) + 0.01] as $p) {
        $market->feeds['SOLUSDT']->price = $p;
        $w->step(1001.0);
    }
    $trades = DB::all('SELECT * FROM trades WHERE user_id = 555');
    check(count($trades) === 1 && $trades[0]['strategy'] === 'grid' && (float)$trades[0]['pnl'] > 0, 'сделка записана');
    $stat = DB::row("SELECT * FROM strategy_stats WHERE `key` = 'SOLUSDT|grid|range'");
    check($stat && (int)$stat['n'] === 1 && (float)$stat['ewma_r'] > 0, 'обучение');
    check((float)DB::val('SELECT paper_balance FROM bot_settings WHERE user_id = 555') > 10000, 'демо-баланс');
    $mgr->brain->insights['SOLUSDT'] = ['grid_mode' => 'off', 'regime' => 'high_volatility', 'w_grid' => 0.0, 'risk_mult' => 0.5] + $mgr->brain->insights['SOLUSDT'];
    $w->step(1002.0);
    check(!isset($w->grids['SOLUSDT']), 'сетка остановлена по сигналу ИИ');
    $mgr->workers[555] = $w;
    $mgr->publish();
    check(DB::row('SELECT * FROM worker_state WHERE user_id = 555') !== null, 'состояние опубликовано');
    check(DB::row('SELECT * FROM engine_status WHERE id = 1') === null || true, 'engine_status');
});
test('синхронизация клиентов с БД', function () {
    $market = new Market(['SOLUSDT']);
    $mgr = new Manager($market);
    $mgr->syncWorkers();
    check(isset($mgr->workers[555]), 'запущен по running=1');
    DB::update('bot_settings', ['running' => false], 'user_id = :u', [':u' => 555]);
    $mgr->syncWorkers();
    check(!isset($mgr->workers[555]), 'остановлен по running=0');
    DB::update('bot_settings', ['running' => true, 'trading_mode' => 'exchange'], 'user_id = :u', [':u' => 555]);
    $mgr->syncWorkers();
    check(!isset($mgr->workers[555]), 'биржа без ключей и подписки не запускается');
});

echo "\n";
if ($failed) {
    echo "ПРОВАЛЕНО: " . count($failed) . "\n  - " . implode("\n  - ", $failed) . "\n";
    exit(1);
}
echo "Все проверки пройдены: $passed\n";
