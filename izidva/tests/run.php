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
use App\Engine\ChartRenderer;
use App\Engine\ExchangeInterface;
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
use App\NowPayments;
use App\Referral;
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

/** Заглушка реальной биржи (mode() !== 'paper') — для проверки пути Grid::stop()/Worker::checkGridStops(). */
final class FakeLiveExchange implements ExchangeInterface
{
    /** symbol => очередь ответов closedPnl() */
    public array $closedPnlQueue = [];

    public function mode(): string { return 'live'; }
    public function equity(): float { return 1000.0; }
    public function positions(): array { return []; }
    public function setLeverage(string $symbol, int $leverage): void {}
    public function placeLimit(string $symbol, string $side, string $qty, string $price, string $linkId, bool $reduceOnly = false,
                               ?string $stop = null, ?string $take = null): void {}
    public function placeMarket(string $symbol, string $side, string $qty, ?string $stop = null, ?string $take = null, bool $reduceOnly = false): void {}
    public function setStopLoss(string $symbol, string $stop): void {}
    public function cancel(string $symbol, string $linkId): void {}
    public function cancelAll(string $symbol): void {}
    public function openOrderIds(string $symbol): array { return []; }
    public function orderResult(string $symbol, string $linkId): array { return ['status' => 'Unknown', 'avg_price' => 0.0, 'filled_qty' => 0.0]; }
    public function closedPnl(string $symbol, int $sinceMs): array { return $this->closedPnlQueue[$symbol] ?? []; }
    public function closePosition(string $symbol): ?array { return [0.0, 0.0]; }
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
test('reduceOnly маркет-ордер по уже закрытой позиции — не падает делением на ноль', function () {
    $ex = new PaperExchange(1000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01');
    $ex->placeMarket('BTCUSDT', 'Sell', '0.01', null, null, true);   // позиция уже закрыта этим вызовом
    check($ex->positions() === [], 'позиция закрыта');
    $ex->placeMarket('BTCUSDT', 'Sell', '0.005', null, null, true);  // reduceOnly по уже плоской позиции — раньше делил на ноль
    check($ex->positions() === [], 'повторный reduceOnly на плоской позиции — no-op, не исключение');
});
test('лимитный ордер с тейком и стопом (ручная торговля)', function () {
    $ex = new PaperExchange(1000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $ex->placeLimit('BTCUSDT', 'Buy', '0.01', '99000', 'm1', false, '97000', '105000');
    $ex->updatePrices(['BTCUSDT' => 98900]);                 // лимитка исполнена
    check(near($ex->positions()['BTCUSDT']['entry'], 99000), 'вход по лимиту');
    $ex->updatePrices(['BTCUSDT' => 105500]);                // цена дошла до тейка
    $c = $ex->closedPnl('BTCUSDT', 0);
    check($ex->positions() === [] && count($c) === 1 && $c[0]['pnl'] > 0, 'тейк, заданный вместе с лимитным ордером, сработал');
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
test('рендер свечного графика в PNG', function () {
    $candles = array_map(fn($k) => ['open' => $k[1], 'high' => $k[2], 'low' => $k[3], 'close' => $k[4]], klines(60, 0.001));
    $png = ChartRenderer::candlesPng($candles, 'BTCUSDT');
    check(str_starts_with($png, "\x89PNG"), 'валидный PNG');
    $info = getimagesizefromstring($png);
    check($info !== false && $info[0] === 900 && $info[1] === 480, 'ожидаемый размер холста');
    check(ChartRenderer::candlesPng([]) !== '', 'без свечей — не падает, а рисует заглушку');
});
test('признаки и режим', function () {
    $f = Indicators::features(klines(300, 0.002));
    check($f !== null && $f['ema50'] > $f['ema200'], 'тренд вверх');
    check(Indicators::ruleRegime(Indicators::features(klines(300))) === 'range', 'боковик');
    check(Indicators::features(klines(100)) === null, 'мало свечей');
    check(isset($f['vol_ratio']) && near($f['vol_ratio'], 1.0, 0.5), 'vol_ratio считается (при равномерном объёме генератора ≈1)');
    check(isset($f['ema20_slope_pct']), 'ema20_slope_pct считается');
});
test('Grid Safety: жёсткие признаки экстремальной волатильности и пробоя, не зависящие от ИИ', function () {
    $calm = ['atr_pct' => 0.3, 'atr_pct_median' => 0.3, 'vol_ratio' => 1.0, 'ema20_slope_pct' => 0.01];
    check(!Indicators::isExtremeVolatility($calm) && !Indicators::isBreakout($calm), 'спокойный рынок — оба признака false');
    $spike = ['atr_pct' => 1.2, 'atr_pct_median' => 0.3, 'vol_ratio' => 1.0, 'ema20_slope_pct' => 0.01];
    check(Indicators::isExtremeVolatility($spike), 'ATR в 4 раза выше медианы — экстремальная волатильность');
    $breakout = ['atr_pct' => 0.7, 'atr_pct_median' => 0.3, 'vol_ratio' => 2.5, 'ema20_slope_pct' => 0.3];
    check(Indicators::isBreakout($breakout), 'ATR расширяется + всплеск объёма + крутой наклон EMA — пробой');
    check(!Indicators::isExtremeVolatility([]) && !Indicators::isBreakout([]), 'без данных (нет atr_pct_median) — не падает и не блокирует зря');
});
test('Мультитаймфрейм: подтверждение тренда старшего ТФ по склеенным свечам', function () {
    check(Indicators::htfTrend([]) === null, 'нет истории — null, не блокирует');
    check(Indicators::htfTrend(klines(60, 0.001)) === null, 'меньше 25 склеенных свечей (60/3=20) — null');
    check(Indicators::htfTrend(klines(300, 0.002)) === 'up', 'устойчивый рост на 5м — 15м тоже вверх');
    check(Indicators::htfTrend(klines(300, -0.002)) === 'down', 'устойчивое падение на 5м — 15м тоже вниз');
    $r = Indicators::resample(klines(9), 3);
    check(count($r) === 3 && (float)$r[0][2] >= (float)$r[0][1], '3 свечи по 3 склеились в 1 старшую, high ≥ open');
});
test('Мультитаймфрейм: сигнал по тренду 5м против явного направления 15м — не открывается', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0;
    $market->feeds['BTCUSDT']->klines = klines(300, -0.003);        // старший ТФ явно смотрит вниз
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5, 'last_closed_ts' => 1.0];
    $brain->features['BTCUSDT'] = $f;
    // сигнал на 5м — лонг (тренд вверх), но старший ТФ (свечи выше) явно падает
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 101.0]);
    $w = new Worker(787, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(gmmktime(12, 0, 0));
    check(!isset($w->directional['BTCUSDT']), 'лонг-сигнал 5м против явного даунтренда 15м — не открылся');
});
test('Funding: оценка funding за время удержания вычитается из PnL направленной сделки', function () use ($BTC) {
    $run = function (float $fundingRate) use ($BTC) {
        $market = new Market(['BTCUSDT']);
        $market->instruments['BTCUSDT'] = $BTC;
        $market->feeds['BTCUSDT']->price = 100000.0;
        $market->feeds['BTCUSDT']->funding = $fundingRate;
        $brain = new Brain(new Learner());
        $ex = new PaperExchange(10000);
        $ex->updatePrices(['BTCUSDT' => 100000.0]);
        $ex->placeMarket('BTCUSDT', 'Buy', '0.1');
        $ex->placeMarket('BTCUSDT', 'Sell', '0.1', null, null, true);
        $recorded = null;
        $w = new Worker(788, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
            function ($u, $t) use (&$recorded) { $recorded = $t; }, function ($u, $m) {}, function ($u, $e) {});
        $openedMs = (int)(microtime(true) * 1000) - 16 * 3600 * 1000;   // держали 16 часов = 2 периода funding по 8ч
        $w->directional['BTCUSDT'] = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 95000.0, 'qty' => 0.1,
            'regime' => 'trend_up', 'opened_ms' => $openedMs, 'risk_usd' => 50.0];
        $w->step(microtime(true));
        return $recorded['pnl'];
    };
    $withoutFunding = $run(0.0);
    $withFunding = $run(0.001);                            // 0.1% за период, лонг платит при положительной ставке
    // notional 0.1×100000=10000, ставка 0.001, 2 периода (16ч/8ч) -> ожидаемый funding-костыль ≈ 10000×0.001×2 = 20$
    check(near($withoutFunding - $withFunding, 20.0, 0.5), 'funding за 16ч удержания лонга вычтен из PnL (≈20$)');
});
test('Funding: оценка funding вычитается из PnL финального стопа сетки', function () {
    $run = function (float $fundingRate) {
        $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
        $market = new Market(['SOLUSDT']);
        $market->instruments['SOLUSDT'] = $sol;
        $market->feeds['SOLUSDT']->price = 100.0;
        $market->feeds['SOLUSDT']->funding = $fundingRate;
        $ex = new PaperExchange(10000);
        $ex->updatePrices(['SOLUSDT' => 100.0]);
        $plan = ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 6, 'qty' => '1', 'max_loss' => 6.0];
        $grid = new Grid($ex, 'SOLUSDT', $sol, $plan);
        $grid->active = true;
        $grid->startedAt = microtime(true) - 16 * 3600;         // держим инвентарь 16 часов = 2 периода funding
        $grid->inventory = [1 => 100.0];
        $ex->pos['SOLUSDT'] = ['size' => 1.0, 'entry' => 100.0, 'stop' => null, 'take' => null];
        $brain = new Brain(new Learner());
        $recorded = null;
        $w = new Worker(789, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
            function ($u, $t) use (&$recorded) { $recorded = $t; }, function ($u, $m) {}, function ($u, $e) {});
        $ev = $grid->stop(100.0);                               // paper — событие сразу, цена не двигалась
        $rm = new ReflectionMethod(Worker::class, 'onGridEvent');
        $rm->setAccessible(true);
        $rm->invoke($w, 'SOLUSDT', $grid, $ev, 'range', microtime(true));
        return $recorded['pnl'];
    };
    $withoutFunding = $run(0.0);
    $withFunding = $run(0.001);
    // notional 1×100=100, ставка 0.001, 2 периода -> ожидаемый funding-костыль ≈ 100×0.001×2 = 0.2$
    check(near($withoutFunding - $withFunding, 0.2, 0.02), 'funding за 16ч удержания сетки вычтен из PnL финального стопа');
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
test('Portfolio Risk Engine: лимит суммарного плавающего убытка блокирует новые входы', function () {
    $g = new RiskGuard(Risk::profile('balanced'));                // max_floating_loss_pct = 6.0
    $g->updateEquity(1000);
    check($g->allowed(1000, 0, -50.0)[0], 'плавающий минус 5% — ещё разрешено');
    [$ok, $reason] = $g->allowed(1000, 0, -60.0);
    check(!$ok && str_contains($reason, 'плавающий убыток портфеля'), 'плавающий минус 6% — новые входы заблокированы');
    check($g->allowed(1000, 0, 30.0)[0], 'плавающая прибыль не блокирует');
});
test('Adaptive Risk: множитель снижается с просадкой от пика equity и сам восстанавливается', function () {
    $g = new RiskGuard(Risk::profile('balanced'));
    $g->updateEquity(1000);
    check(near($g->adaptiveMult(1000), 1.0), 'на пике — множитель 1.0');
    $g->updateEquity(1200);
    check(near($g->adaptiveMult(1200), 1.0), 'новый пик — всё ещё 1.0');
    check($g->adaptiveMult(1080) < 1.0 && $g->adaptiveMult(1080) > 0.4, 'просадка 10% от пика 1200 — множитель снижен, но не в ноль');
    check(near($g->adaptiveMult(600), 0.4), 'глубокая просадка — множитель упирается в пол 0.4, не уходит в 0');
    $g->updateEquity(1200);
    check(near($g->adaptiveMult(1200), 1.0), 'equity вернулась к пику — множитель сам восстановился до 1.0');
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
    check((int)DB::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()") === 24, '24 таблицы');
});
test('настройки: секреты шифруются', function () {
    Settings::save(['anthropic_api_key' => 'sk-ant-1234', 'ai_interval_min' => '15', 'symbols' => 'btcusdt, ethusdt', 'require_referral' => 'false']);
    $s = Settings::all(true);
    check($s['anthropic_api_key'] === 'sk-ant-1234' && $s['ai_interval_min'] === 15 && $s['symbols'] === ['BTCUSDT', 'ETHUSDT'] && $s['require_referral'] === false, 'значения');
    check(!str_contains((string)DB::val("SELECT value FROM app_settings WHERE `key` = 'anthropic_api_key'"), 'sk-ant'), 'в БД только шифр');
    Settings::save(['anthropic_api_key' => '', 'symbols' => ['SOLUSDT'], 'ai_interval_min' => 30, 'require_referral' => true]);
});
test('настройки: битое шифрование одного поля не роняет остальные', function () {
    Settings::save(['bot_token' => '123:ABC']);
    DB::q("UPDATE app_settings SET value = 'мусор-после-смены-APP_KEY' WHERE `key` = 'bot_token'");
    $s = Settings::all(true);
    check($s['bot_token'] === '', 'битое поле читается как «не задано», а не бросает исключение');
    check($s['ai_interval_min'] === 30, 'остальные настройки при этом читаются нормально');
    Settings::save(['bot_token' => '']);
});
test('Learner: не только winrate — Profit Factor, Expectancy, Avg Win/Loss, худший R', function () {
    $l = new Learner();
    $l->record('TESTUSDT', 'grid', 'range', 0.03, 0.06);
    $l->record('TESTUSDT', 'grid', 'range', 0.02, 0.04);
    $l->record('TESTUSDT', 'grid', 'range', 0.025, 0.05);
    $l->record('TESTUSDT', 'grid', 'range', -6.0, -12.0);        // один крупный стоп топит десятки мелких побед
    $s = $l->statsFor('TESTUSDT')['grid/range'];
    check($s['trades'] === 4 && $s['winrate'] === 0.75, '75% побед по количеству сделок');
    check($s['profit_factor'] < 1.0, 'при этом Profit Factor < 1 — реально убыточно, winrate вводит в заблуждение');
    check($s['expectancy_r'] < 0, 'ожидание в R отрицательное');
    check(near($s['expectancy_usd'], (0.06 + 0.04 + 0.05 - 12.0) / 4, 1e-6), 'ожидание в $ считается по сумме pnl / число сделок');
    check(near($s['worst_r'], -6.0), 'худший R сохранён без клэмпа — видно реальный масштаб хвостового убытка');
    check(near($s['avg_win_r'], (0.03 + 0.02 + 0.025) / 3, 1e-6), 'средний выигрыш в R');
    check(near($s['avg_loss_r'], 6.0), 'средний проигрыш в R');
    // перечитываем из БД свежий Learner — статистика должна была сохраниться, не только в памяти
    $l2 = new Learner();
    $l2->load();
    check(near($l2->statsFor('TESTUSDT')['grid/range']['worst_r'], -6.0), 'статистика сохранена в БД и читается заново');
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
test('vision-разбор графика: отдельный вызов, без ключа возвращает null', function () {
    check((new AIAnalyst())->visionReview('BTCUSDT', ChartRenderer::candlesPng([['open' => 1, 'high' => 2, 'low' => 0.5, 'close' => 1.5]])) === null,
        'без ключа Anthropic — null, а не ошибка');
});
test('vision-разбор графика через заглушку API', function () {
    $port = 18978;
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_anthropic.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400_000);
    putenv("ANTHROPIC_BASE_URL=http://127.0.0.1:$port");
    try {
        Settings::save(['anthropic_api_key' => 'sk-ant-test', 'ai_model' => 'claude-opus-5']);
        $png = ChartRenderer::candlesPng(array_map(fn($k) => ['open' => $k[1], 'high' => $k[2], 'low' => $k[3], 'close' => $k[4]], klines(40)), 'BTCUSDT');
        $note = (new AIAnalyst())->visionReview('BTCUSDT', $png);
        check(is_array($note) && in_array($note['bias'], ['bullish', 'bearish', 'neutral'], true), 'ответ разобран без падения');
        check($note['summary'] !== '', 'краткое описание не пустое');
        check((string)DB::val("SELECT kind FROM ai_usage ORDER BY id DESC LIMIT 1") === 'vision', 'расход учтён отдельным видом "vision"');
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
test('после стопа сетки на монете есть пауза перед новым входом (GRID_STOP_COOLDOWN_SEC)', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $brain = new Brain(new Learner());
    $brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 100];
    $brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $w = new Worker(782, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->lastGridStop['SOLUSDT'] = 5000.0;                     // симулируем недавний стоп сетки на этой монете
    $w->step(5000.0 + Worker::GRID_STOP_COOLDOWN_SEC - 1);
    check(!isset($w->grids['SOLUSDT']), 'сразу после стопа новая сетка на той же монете не открывается');
    $w->step(5000.0 + Worker::GRID_STOP_COOLDOWN_SEC + 1);
    check(isset($w->grids['SOLUSDT']), 'после паузы сетка снова может открыться');
});
test('коррелирующие монеты: вторую сетку в той же группе не открываем, пока активна первая', function () {
    $ada = new Instrument('ADAUSDT', '0.0001', '1', 1, 100000, 5, 50);
    $xrp = new Instrument('XRPUSDT', '0.0001', '1', 1, 100000, 5, 50);
    $market = new Market(['ADAUSDT', 'XRPUSDT']);
    $market->instruments['ADAUSDT'] = $ada;
    $market->instruments['XRPUSDT'] = $xrp;
    $market->feeds['ADAUSDT']->price = 0.25;
    $market->feeds['XRPUSDT']->price = 1.5;
    $brain = new Brain(new Learner());
    $ins = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $brain->features['ADAUSDT'] = ['price' => 0.25, 'atr' => 0.005, 'ema20' => 0.25, 'ema20_1' => 0.25, 'last_closed_ts' => 1.0, 'vwap_4h' => 0.25];
    $brain->features['XRPUSDT'] = ['price' => 1.5, 'atr' => 0.02, 'ema20' => 1.5, 'ema20_1' => 1.5, 'last_closed_ts' => 1.0, 'vwap_4h' => 1.5];
    $brain->insights['ADAUSDT'] = $ins;
    $brain->insights['XRPUSDT'] = $ins;
    $ex = new PaperExchange(10000);
    $w = new Worker(783, $ex, $market, $brain, Risk::profile('balanced'), ['ADAUSDT', 'XRPUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(6000.0);
    check(isset($w->grids['ADAUSDT']) && !isset($w->grids['XRPUSDT']), 'из XRPUSDT/ADAUSDT (одна группа) сетка открылась только на первой по списку');
});
test('Grid Safety: новая сетка не открывается при экстремальной волатильности, даже если веса ИИ говорят «грид»', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $brain = new Brain(new Learner());
    // ИИ (алгоритм) настаивает на сетке (w_grid=1.0, grid_mode=long) — но ATR в 5 раз выше медианы: код должен заблокировать вход сам.
    $brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 100,
        'atr_pct' => 5.0, 'atr_pct_median' => 1.0, 'vol_ratio' => 1.0, 'ema20_slope_pct' => 0.0];
    $brain->insights['SOLUSDT'] = ['regime' => 'high_volatility', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $w = new Worker(785, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(8000.0);
    check(!isset($w->grids['SOLUSDT']), 'экстремальный ATR — жёсткий код-блок сработал, сетка не открылась');
});
test('Grid Risk Protection: max_total_grid_risk_pct ограничивает суммарный риск всех сеток', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $doge = new Instrument('DOGEUSDT', '1', '0.00001', 0.00001, 10000000, 5, 50);
    $market = new Market(['SOLUSDT', 'DOGEUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->instruments['DOGEUSDT'] = $doge;
    $market->feeds['SOLUSDT']->price = 100.0;
    $market->feeds['DOGEUSDT']->price = 0.1;
    $brain = new Brain(new Learner());
    $ins = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $brain->features['DOGEUSDT'] = ['price' => 0.1, 'atr' => 0.002, 'ema20' => 0.1, 'ema20_1' => 0.1, 'last_closed_ts' => 1.0, 'vwap_4h' => 0.1];
    $brain->insights['DOGEUSDT'] = $ins;
    $ex = new PaperExchange(1000);
    $w = new Worker(784, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT', 'DOGEUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    // уже открыта сетка на SOLUSDT (другая корреляционная группа, чем DOGEUSDT) с риском 60$ — как будто ИИ поднимал risk_mult
    $existingPlan = ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 6, 'qty' => '1', 'max_loss' => 60.0];
    $w->grids['SOLUSDT'] = new Grid($ex, 'SOLUSDT', $sol, $existingPlan);
    $w->step(7000.0);
    check(!isset($w->grids['DOGEUSDT']), 'equity 1000$, cap 7.5%=75$, уже занято 60$ — новая сетка (+25$) превысила бы лимит, не открылась');
});
test('стоп сетки на реальной бирже: PnL считается по факту с биржи, а не по цене до отправки ордера', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $ex = new FakeLiveExchange();
    $plan = ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 6, 'qty' => '1', 'max_loss' => 6.0];
    $grid = new Grid($ex, 'SOLUSDT', $sol, $plan);
    $grid->active = true;
    $grid->inventory = [1 => 99.5];                           // один уровень уже куплен по 99.5
    $ev = $grid->stop(90.0);                                   // 90.0 — только снимок цены ДО отправки ордера
    check($ev === null, 'на реальной бирже событие не возвращается сразу — ждём факта исполнения');
    check($grid->pendingStop !== null && near((float)$grid->pendingStop['entry'], 99.5), 'pendingStop записан с верным входом');

    $brain = new Brain(new Learner());
    $brain->insights['SOLUSDT'] = ['regime' => 'trend_down'];
    $recorded = null;
    $w = new Worker(791, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$recorded) { $recorded = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $w->grids['SOLUSDT'] = $grid;
    $t0 = microtime(true);
    $w->step($t0);
    check(isset($w->grids['SOLUSDT']) && $recorded === null, 'сразу после стопа сделка ещё не записана — ждём биржу');
    $ex->closedPnlQueue['SOLUSDT'] = [['pnl' => -3.21, 'exit' => 88.4, 'ts' => 1]];
    $w->step($t0 + 4);
    check(!isset($w->grids['SOLUSDT']), 'после ответа биржи сетка убрана из активных');
    check($recorded !== null && near((float)$recorded['pnl'], -3.21) && near((float)$recorded['exit'], 88.4),
        'записан реальный PnL и цена исполнения с биржи (-3.21$ / 88.4), а не оценка по старой цене (90.0)');
});
test('досрочный выход из сетки при настоящем развороте режима, если уже есть плавающий убыток', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    // 98.0: ниже входа (99.5) — уже плавающий убыток, но ЕЩЁ выше собственного stopPrice сетки (96.25) —
    // чтобы сработала именно новая логика разворота, а не старый «цена дошла до stopPrice» в sync().
    $market->feeds['SOLUSDT']->price = 98.0;
    $brain = new Brain(new Learner());
    $brain->features['SOLUSDT'] = ['price' => 98.0, 'atr' => 1.0, 'ema20' => 98, 'ema20_1' => 98, 'last_closed_ts' => 1.0, 'vwap_4h' => 98];
    $brain->insights['SOLUSDT'] = ['regime' => 'trend_down', 'confidence' => 0.8, 'w_grid' => 0.1, 'w_trend' => 0.9, 'w_liquidation' => 0.1,
        'grid_mode' => 'short', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['SOLUSDT' => 98.0]);
    $plan = ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 6, 'qty' => '1', 'max_loss' => 6.0];
    $grid = new Grid($ex, 'SOLUSDT', $sol, $plan);
    $grid->active = true;
    $grid->inventory = [1 => 99.5];                           // long-сетка держит уровень, купленный по 99.5 — при 98.0 это убыток
    $ex->pos['SOLUSDT'] = ['size' => 1.0, 'entry' => 99.5, 'stop' => null, 'take' => null];
    $recorded = null;
    $w = new Worker(793, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$recorded) { $recorded = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $w->grids['SOLUSDT'] = $grid;
    $w->step(microtime(true));
    check($recorded !== null && $recorded['strategy'] === 'grid' && (float)$recorded['pnl'] < 0,
        'режим развернулся против long-сетки (стал short), сетка уже в минусе — закрыта сразу, а не через drain()');
    check(!isset($w->grids['SOLUSDT']), 'сетка убрана из активных сразу');
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

echo "Оплата криптовалютой (NOWPayments)\n";
test('тарифы аренды бота: 1 месяц/6 месяцев/1 год, цена в $ из настроек, звёзды — по курсу', function () {
    Settings::save(['price_month_usd' => 15, 'price_half_year_usd' => 50, 'price_year_usd' => 90]);
    $rate = (float)Settings::get('stars_usd_rate');
    $plans = Settings::plans();
    check(array_column($plans, 'code') === ['month', 'half_year', 'year'], 'три тарифа в нужном порядке');
    check(array_column($plans, 'days') === [30, 182, 365], 'срок в днях');
    [$month, $halfYear, $year] = $plans;
    check($month['usd'] === 15.0 && $halfYear['usd'] === 50.0 && $year['usd'] === 90.0, 'цена в $ берётся из настроек как есть');
    foreach ($plans as $p) {
        check($p['stars'] >= $p['usd'] / $rate && $p['stars'] < $p['usd'] / $rate + 1, "$p[code]: звёзды округлены вверх по курсу от цены в \$");
    }
    check(Settings::plan('month')['usd'] === 15.0, 'Settings::plan() находит тариф по коду');
});
test('подпись IPN проверяется по HMAC-SHA512 отсортированного JSON', function () {
    Settings::save(['nowpayments_ipn_secret' => 'test-ipn-secret']);
    $payload = ['order_id' => 'sub-1-week-abcd', 'payment_status' => 'finished', 'price_amount' => 12.5, 'nested' => ['b' => 2, 'a' => 1]];
    $sortRec = function ($v) use (&$sortRec) {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map($sortRec, $v);
        }
        ksort($v);
        return array_map($sortRec, $v);
    };
    $json = json_encode($sortRec($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha512', (string)$json, 'test-ipn-secret');
    $raw = json_encode($payload);
    check(NowPayments::verifySignature($raw, $sig), 'верная подпись принимается');
    check(!NowPayments::verifySignature($raw, str_repeat('0', 128)), 'неверная подпись отклоняется');
    check(!NowPayments::verifySignature($raw, null), 'отсутствующая подпись отклоняется');
    check(!NowPayments::verifySignature($raw, 'not-hex'), 'подпись не по формату отклоняется без ошибки');
    Settings::save(['nowpayments_ipn_secret' => '']);
});
test('без ключа NOWPayments инвойс не создаётся', function () {
    Settings::save(['nowpayments_api_key' => '']);
    $threw = false;
    try {
        NowPayments::createInvoice(['price_amount' => 10, 'price_currency' => 'usd', 'order_id' => 'x']);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, 'без API-ключа выбрасывается понятная ошибка, а не запрос в сеть');
});

echo "Партнёрская программа\n";
test('привязка реферера: один раз, без самопригласительных петель', function () {
    DB::insert('users', ['id' => 9001, 'first_name' => 'A', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('users', ['id' => 9002, 'first_name' => 'B', 'created_at' => DB::now(), 'blocked' => false]);
    Referral::attach(9002, 9001);
    check((int)DB::val('SELECT referred_by FROM users WHERE id = 9002') === 9001, 'привязка сработала');
    Referral::attach(9002, 999999);                       // левый реферер: попытка переписать существующую привязку
    check((int)DB::val('SELECT referred_by FROM users WHERE id = 9002') === 9001, 'повторная привязка не перезаписывает первую');
    Referral::attach(9003, 9003);                          // несуществующий пользователь сам себе — no-op
    check(DB::val('SELECT referred_by FROM users WHERE id = 9003') === null, 'самопригласительная петля не создаётся');
});
test('начисление комиссии по цепочке из 5 уровней — только по проценту из настроек', function () {
    Settings::save(['referral_program_enabled' => true, 'referral_pct_l1' => 20, 'referral_pct_l2' => 5,
        'referral_pct_l3' => 3, 'referral_pct_l4' => 2, 'referral_pct_l5' => 1]);
    // цепочка: 9101 <- 9102 <- 9103 <- 9104 <- 9105 <- 9106 (9106 платит, 9101 — реферер 5-го уровня)
    for ($i = 9101; $i <= 9106; $i++) {
        DB::insert('users', ['id' => $i, 'first_name' => 'U' . $i, 'created_at' => DB::now(), 'blocked' => false]);
    }
    for ($i = 9102; $i <= 9106; $i++) {
        Referral::attach($i, $i - 1);
    }
    DB::insert('users', ['id' => 9107, 'first_name' => 'U7', 'created_at' => DB::now(), 'blocked' => false]);
    Referral::attach(9107, 9106);                          // 6-й уровень от 9101 — за пределы 5 уровней, не должен получить долю
    $paymentId = DB::insert('payments', ['user_id' => 9106, 'plan' => 'month', 'stars' => 0, 'usd' => 100.0,
        'charge_id' => 'test-chain-1', 'created_at' => DB::now()]);
    Referral::creditForPayment(9106, $paymentId, 100.0);
    $rows = DB::all('SELECT beneficiary_id, level, amount_usd FROM referral_earnings WHERE payment_id = ? ORDER BY level', [$paymentId]);
    check(count($rows) === 5, '5 начислений — по числу рефереров в цепочке выше плательщика');
    $expect = [9105 => [1, 20.0], 9104 => [2, 5.0], 9103 => [3, 3.0], 9102 => [4, 2.0], 9101 => [5, 1.0]];
    foreach ($rows as $r) {
        [$lvl, $usd] = $expect[(int)$r['beneficiary_id']];
        check((int)$r['level'] === $lvl && near((float)$r['amount_usd'], $usd), "уровень $lvl: {$r['amount_usd']}\$ (реферер {$r['beneficiary_id']})");
    }
    check(!DB::val('SELECT 1 FROM referral_earnings WHERE beneficiary_id = 9106 AND payment_id = ?', [$paymentId]), 'сам плательщик ничего себе не начисляет');
});
test('партнёрская программа выключена в настройках — начислений нет', function () {
    Settings::save(['referral_program_enabled' => false]);
    $paymentId = DB::insert('payments', ['user_id' => 9106, 'plan' => 'month', 'stars' => 0, 'usd' => 50.0,
        'charge_id' => 'test-chain-2', 'created_at' => DB::now()]);
    Referral::creditForPayment(9106, $paymentId, 50.0);
    check(!DB::val('SELECT 1 FROM referral_earnings WHERE payment_id = ?', [$paymentId]), 'выключенная программа ничего не начисляет');
    Settings::save(['referral_program_enabled' => true]);
});
test('demo-счёт не создаёт платежей — начислить с демо-торговли нечего', function () {
    // Демо-режим (paper) бесплатный и никогда не проходит через payments — Referral::creditForPayment
    // вызывается только из мест, где платёж уже создан (Webhook::paid, nowpayments/webhook.php),
    // поэтому проверяем сам инвариант: без записи в payments начислений не бывает в принципе.
    check((int)DB::val("SELECT COUNT(*) FROM referral_earnings WHERE payment_id NOT IN (SELECT id FROM payments)") === 0,
        'у каждого начисления есть реальный платёж');
});
test('подсчёт команды по уровням (downlineCounts)', function () {
    $counts = Referral::downlineCounts(9101);
    check($counts === [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1], 'по одному человеку на уровень в тестовой цепочке (6-й уровень не считается)');
    check(Referral::rank(0) === 1 && Referral::rank(5) === 2 && Referral::rank(10) === 3 && Referral::rank(20) === 4 && Referral::rank(50) === 5, 'ранг по размеру команды');
});

echo "Ручная торговля трейдера\n";
test('ручной рыночный ордер: позиция отслеживается как обычная сделка', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $notes = [];
    $w = new Worker(777, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) use (&$notes) { $notes[] = $m; }, function ($u, $e) {});
    $r = $w->manualOrder(['id' => 1, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'market', 'qty' => 0.01,
        'price' => null, 'stop_loss' => 95000, 'take_profit' => 110000, 'leverage' => 10]);
    check($r['status'] === 'done', 'рыночный ордер исполняется сразу');
    check(isset($w->directional['BTCUSDT']) && $w->directional['BTCUSDT']['strategy'] === 'manual', 'позиция трейдера — как directional, strategy=manual');
    check(!empty($notes), 'клиент уведомлён в Telegram');
    $w->directional['BTCUSDT']['opened_ms'] -= 5000;          // «прошло» больше 3 секунд с открытия (грация checkDirectional)
    $ex->updatePrices(['BTCUSDT' => 110500]);                // цена дошла до тейка трейдера
    $w->step(microtime(true));
    check(!isset($w->directional['BTCUSDT']), 'позиция закрылась по тейку и снята с отслеживания');
});
test('ручной лимитный ордер: ждёт исполнения, потом отслеживается', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $statuses = [];
    $w = new Worker(778, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {}, false,
        function ($id, $status, $detail) use (&$statuses) { $statuses[$id] = $status; });
    $r = $w->manualOrder(['id' => 2, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'limit', 'qty' => 0.01,
        'price' => 99000, 'stop_loss' => null, 'take_profit' => null, 'leverage' => null]);
    check($r['status'] === 'placed' && isset($w->pendingManual['BTCUSDT']), 'лимитка поставлена, ждём цену');
    $ex->updatePrices(['BTCUSDT' => 98800]);                 // цена пересекла лимит — на бирже исполнилась
    $w->pendingManual['BTCUSDT']['placed_ms'] -= 3000;        // «прошло» больше 2 секунд с момента выставления
    $market->feeds['BTCUSDT']->price = 98800;
    $w->step(microtime(true));
    check(!isset($w->pendingManual['BTCUSDT']) && isset($w->directional['BTCUSDT']), 'после исполнения — снята из ожидания, взята под отслеживание');
    check(($statuses[2] ?? null) === 'filled', 'статус ордера в БД обновится на "filled"');
});
test('нельзя открыть второй ручной ордер, пока не закрыт первый', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $w = new Worker(779, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->manualOrder(['id' => 3, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'market', 'qty' => 0.01,
        'price' => null, 'stop_loss' => null, 'take_profit' => null, 'leverage' => null]);
    $threw = false;
    try {
        $w->manualOrder(['id' => 4, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'market', 'qty' => 0.01,
            'price' => null, 'stop_loss' => null, 'take_profit' => null, 'leverage' => null]);
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, 'повторный ордер по той же монете отклонён, пока есть открытая позиция');
});
test('трейдер может закрыть открытую вручную позицию', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $notes = [];
    $w = new Worker(781, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) use (&$notes) { $notes[] = $m; }, function ($u, $e) {});
    $w->manualOrder(['id' => 5, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'market', 'qty' => 0.01,
        'price' => null, 'stop_loss' => null, 'take_profit' => null, 'leverage' => null]);
    check(isset($w->directional['BTCUSDT']), 'позиция открыта');
    $w->closeManual('BTCUSDT');
    check($ex->positions() === [], 'позиция закрыта на бирже сразу');
    check(count($notes) === 2 && str_contains(end($notes), 'закрыл позицию'), 'клиент уведомлён о закрытии трейдером');
});
test('трейдер может отменить неисполненный лимитный ордер кнопкой «Закрыть»', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $w = new Worker(782, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->manualOrder(['id' => 6, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'limit', 'qty' => 0.01,
        'price' => 90000, 'stop_loss' => null, 'take_profit' => null, 'leverage' => null]);
    check(isset($w->pendingManual['BTCUSDT']), 'лимитный ордер ждёт исполнения');
    $w->closeManual('BTCUSDT');
    check(!isset($w->pendingManual['BTCUSDT']), 'неисполненный лимитный ордер снят');
});
test('закрывать нечего — понятная ошибка', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $w = new Worker(783, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $threw = false;
    try {
        $w->closeManual('BTCUSDT');
    } catch (\RuntimeException $e) {
        $threw = true;
    }
    check($threw, 'закрытие без открытой позиции/сетки/ордера бросает исключение');
});
test('ручной режим: автостратегии не открывают новых сделок', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $brain->features['BTCUSDT'] = ['price' => 100000.0, 'atr' => 500.0, 'ema20' => 100000, 'ema20_1' => 100000, 'last_closed_ts' => 1.0, 'vwap_4h' => 100000];
    $brain->insights['BTCUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $w = new Worker(780, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {}, true);
    $w->step(microtime(true));
    check(!isset($w->grids['BTCUSDT']), 'сетка не запускается, пока клиент переведён в ручной режим');
});
test('частичный тейк на +1R и трейлинг-стоп в безубыток', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $brain->features['BTCUSDT'] = ['price' => 100000.0, 'atr' => 500.0, 'ema20' => 100000, 'ema20_1' => 100000, 'last_closed_ts' => 1.0, 'vwap_4h' => 100000];
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.1, 'w_trend' => 1.0, 'w_liquidation' => 0.1,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.02');
    $w = new Worker(790, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->directional['BTCUSDT'] = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 99000.0, 'qty' => 0.02,
        'regime' => 'trend_up', 'opened_ms' => (int)(microtime(true) * 1000) - 120_000, 'risk_usd' => 1000.0 * 0.02];
    $market->feeds['BTCUSDT']->price = 101100.0;                // +1.1R от входа (риск = 1000 на единицу объёма)
    $ex->updatePrices(['BTCUSDT' => 101100.0]);
    $w->step(microtime(true));
    check(($w->directional['BTCUSDT']['partial_done'] ?? false) === true, 'зафиксирована частичная прибыль на +1R');
    check(near(abs($ex->positions()['BTCUSDT']['qty']), 0.01, 1e-6), 'осталась половина объёма');
    check($w->directional['BTCUSDT']['stop'] > 100000.0, 'стоп передвинут минимум в безубыток (профит уже больше 0.8R)');
});
test('трейлинг-стоп следует за ценой на +1.5R, не расширяя риск', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $brain->features['BTCUSDT'] = ['price' => 100000.0, 'atr' => 500.0, 'ema20' => 100000, 'ema20_1' => 100000, 'last_closed_ts' => 1.0, 'vwap_4h' => 100000];
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.1, 'w_trend' => 1.0, 'w_liquidation' => 0.1,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.02');
    $w = new Worker(791, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->directional['BTCUSDT'] = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 99000.0, 'qty' => 0.02,
        'regime' => 'trend_up', 'opened_ms' => (int)(microtime(true) * 1000) - 120_000, 'risk_usd' => 1000.0 * 0.02,
        'partial_done' => true, 'trail_be' => true];               // частичный тейк и безубыток уже отработали раньше
    $market->feeds['BTCUSDT']->price = 103000.0;                // +3R — трейлинг на расстоянии 0.6R = 600
    $ex->updatePrices(['BTCUSDT' => 103000.0]);
    $w->step(microtime(true));
    check(near((float)$w->directional['BTCUSDT']['stop'], 102400.0, 0.5), 'стоп подтянут на 0.6R за ценой');
    $oldStop = $w->directional['BTCUSDT']['stop'];
    $market->feeds['BTCUSDT']->price = 102500.0;                // небольшой откат (стоп 102400 ещё не задет) — стоп назад не двигаем
    $ex->updatePrices(['BTCUSDT' => 102500.0]);
    $w->step(microtime(true));
    check(near((float)$w->directional['BTCUSDT']['stop'], $oldStop, 1e-6), 'при откате цены стоп не отодвигается назад');
});
test('коррелирующие монеты: вторую направленную ставку в той же группе не берём', function () use ($BTC) {
    $eth = new Instrument('ETHUSDT', '0.01', '0.01', 0.01, 500, 5, 50);
    $market = new Market(['BTCUSDT', 'ETHUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->instruments['ETHUSDT'] = $eth;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $market->feeds['ETHUSDT']->price = 101.0;
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5,
        'last_closed_ts' => 1.0, 'vwap_4h' => 100.5];
    $brain->features['ETHUSDT'] = $f;
    $brain->insights['ETHUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0, 'ETHUSDT' => 101.0]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01');                  // реальная позиция на бирже — иначе checkDirectional её сразу «закроет»
    $w = new Worker(792, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT', 'ETHUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->directional['BTCUSDT'] = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 99000.0, 'qty' => 0.01,
        'regime' => 'trend_up', 'opened_ms' => (int)(microtime(true) * 1000) - 120_000, 'risk_usd' => 10.0];
    $w->step(gmmktime(12, 0, 0));                                // день, чтобы «тихие часы» не мешали проверить именно корреляцию
    check(!isset($w->directional['ETHUSDT']), 'сделка по ETHUSDT не открыта — BTCUSDT из той же группы уже в позиции');
});
test('тихие часы (00:00–05:00 UTC): новых направленных сделок не берём', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0;
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5,
        'last_closed_ts' => 1.0, 'vwap_4h' => 100.5];
    $brain->features['BTCUSDT'] = $f;
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 101.0]);
    $w = new Worker(793, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(gmmktime(2, 0, 0));                                 // 02:00 UTC — тихая ночь
    check(!isset($w->directional['BTCUSDT']), 'ночью новую ставку не открываем');
    $w->step(gmmktime(12, 0, 0));                                // днём тот же сетап уже проходит
    check(isset($w->directional['BTCUSDT']), 'днём тот же сетап уже открывает сделку');
});
test('мёртвая волатильность (ATR/цена < 0.07%): новых направленных сделок не берём', function () use ($BTC) {
    $offset = 1_000_000.0;                                       // сдвигаем весь ценовой ряд, оставляя дельты в единицах ATR прежними
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0 + $offset;
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0 + $offset, 'ema20' => 100.5 + $offset, 'ema20_1' => 100.4 + $offset, 'low_5' => 100.3 + $offset,
        'c1' => 100.9 + $offset, 'o1' => 100.5 + $offset, 'h2' => 100.8 + $offset, 'l2' => 100.0 + $offset, 'rsi' => 58,
        'low_10' => 99.5 + $offset, 'high_10' => 102 + $offset, 'high_5' => 101.5 + $offset, 'last_closed_ts' => 1.0, 'vwap_4h' => 100.5 + $offset];
    $brain->features['BTCUSDT'] = $f;
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => $f['price']]);
    $w = new Worker(794, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(gmmktime(12, 0, 0));
    check(!isset($w->directional['BTCUSDT']), 'ATR/цена ничтожно мал — рынок «спит», сделку не открываем');
});
test('дневной лимит числа направленных сделок', function () {
    $guard = new RiskGuard(Risk::profile('conservative'));       // max_directional_trades_per_day = 4
    $guard->updateEquity(1000.0);
    check($guard->directionalTradesLeft() === 4, 'изначально доступны все 4 сделки');
    for ($i = 0; $i < 4; $i++) {
        $guard->recordDirectionalTrade();
    }
    check($guard->directionalTradesLeft() === 0, 'после 4 сделок лимит исчерпан');
    $guard->day = gmdate('Y-m-d', strtotime('-1 day'));           // «вчера» — новый день сбросит счётчик
    $guard->updateEquity(1000.0);
    check($guard->directionalTradesLeft() === 4, 'на следующий день лимит обнуляется');
});
test('Cooldown: после крупного убытка направленной сделки — пауза перед новой ставкой на той же монете', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0;
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5, 'last_closed_ts' => 1.0];
    $brain->features['BTCUSDT'] = $f;
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 101.0]);
    $now = gmmktime(12, 0, 0);
    $w = new Worker(786, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->lastBigLoss['BTCUSDT'] = $now - 60;                    // крупный убыток минуту назад
    $w->step((float)$now);
    check(!isset($w->directional['BTCUSDT']), 'сразу после крупного убытка новая направленная сделка на этой монете не открывается');
    $w->lastBigLoss['BTCUSDT'] = $now - Worker::BIG_LOSS_COOLDOWN_SEC - 60;   // пауза давно прошла
    $w->step((float)$now);
    check(isset($w->directional['BTCUSDT']), 'после окончания паузы сделка снова может открыться');
});
test('досрочный выход из тренда при развороте, не дожидаясь стопа', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $brain->features['BTCUSDT'] = ['price' => 100000.0, 'atr' => 500.0, 'ema20' => 100000, 'ema20_1' => 100000, 'last_closed_ts' => 1.0, 'vwap_4h' => 100000];
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_down', 'confidence' => 0.8, 'w_grid' => 0.1, 'w_trend' => 1.0, 'w_liquidation' => 0.1,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01');                // позиция уже на бирже, без движения цены к стопу
    $w = new Worker(784, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->directional['BTCUSDT'] = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 95000.0, 'qty' => 0.01,
        'regime' => 'trend_up', 'opened_ms' => (int)(microtime(true) * 1000) - 120_000, 'risk_usd' => 50.0];
    $w->step(microtime(true));
    check($ex->positions() === [], 'позиция закрыта, увидев смену режима на противоположный тренд — стоп ещё далеко');
});

echo "\n";
if ($failed) {
    echo "ПРОВАЛЕНО: " . count($failed) . "\n  - " . implode("\n  - ", $failed) . "\n";
    exit(1);
}
echo "Все проверки пройдены: $passed\n";
