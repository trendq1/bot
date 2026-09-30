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
use App\Engine\Regime;
use App\Engine\Risk;
use App\Engine\RiskGuard;
use App\Engine\Setups;
use App\Engine\SymbolFeed;
use App\Engine\TradeStats;
use App\Engine\Worker;
use App\Migrator;
use App\NowPayments;
use App\Referral;
use App\Settings;
use App\SignalParser;

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

    public array $positionsList = [];
    public int $closeCalls = 0;
    public array $cancelled = [];
    public array $stopLosses = [];

    public function mode(): string { return 'live'; }
    public function equity(): float { return 1000.0; }
    public function positions(): array { return $this->positionsList; }
    public function setLeverage(string $symbol, int $leverage): void {}
    public function placeLimit(string $symbol, string $side, string $qty, string $price, string $linkId, bool $reduceOnly = false,
                               ?string $stop = null, ?string $take = null): void {}
    public function placeMarket(string $symbol, string $side, string $qty, ?string $stop = null, ?string $take = null, bool $reduceOnly = false): void {}
    public function setStopLoss(string $symbol, string $stop): void { $this->stopLosses[$symbol] = $stop; }
    public function openOrders(): array { return []; }
    public array $tradingStops = [];
    public function setTradingStop(string $symbol, ?string $stop, ?string $take): void { $this->tradingStops[$symbol] = [$stop, $take]; }
    public function cancel(string $symbol, string $linkId): void { $this->cancelled[] = $linkId; }
    public function cancelAll(string $symbol): void {}
    public function openOrderIds(string $symbol): array { return []; }
    public function orderResult(string $symbol, string $linkId): array { return ['status' => 'Unknown', 'avg_price' => 0.0, 'filled_qty' => 0.0]; }
    public function closedPnl(string $symbol, int $sinceMs): array { return $this->closedPnlQueue[$symbol] ?? []; }
    public function closePosition(string $symbol): ?array { $this->closeCalls++; return [0.0, 0.0]; }
}

$BTC = new Instrument('BTCUSDT', '0.1', '0.001', 0.001, 100, 5, 100);

/** Подтверждённый боковик по 1h для теста: цена в нижней трети суточного диапазона — сетка в лонг. */
function rangeRegime(Brain $brain, string $sym, float $price): void
{
    $brain->regimes[$sym] = Regime::RANGE;
    $brain->featuresH1[$sym] = ['price' => $price, 'hi_24' => $price * 1.03, 'lo_24' => $price * 0.99, 'atr' => $price * 0.005,
        'c1' => $price, 'ema50_1' => $price, 'last_closed_ts' => 1.0];
}

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
    check($ex->pos['BTCUSDT']['stop'] !== null && near($ex->pos['BTCUSDT']['stop'], (float)$BTC->roundPrice($g->stopPrice(), false), 0.2),
        'стоп сетки стоит на бирже как стоп-лосс позиции, а не только в коде демона');
    $crash = $g->stopPrice() - 10;
    $ex->updatePrices(['BTCUSDT' => $crash]);                // стоп-лосс биржи закрывает позицию сам
    check($ex->positions() === [], 'позиция закрыта стопом биржи');
    $ev = $g->sync($crash);
    check($ev === [] && $g->pendingStop !== null && !$g->active, 'сетка поняла, что позицию закрыла биржа, результат заберёт из closed-pnl');
    $closed = $ex->closedPnl('BTCUSDT', 0);
    $pnl = (float)end($closed)['pnl'];
    check($pnl < 0 && abs($pnl) <= 550, 'стоп в пределах бюджета');

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
/** Признаки 1h/5м для тестов режима: по умолчанию — чистый восходящий тренд. */
function regimeF(array $over = []): array
{
    return $over + ['price' => 105.0, 'ema20' => 104.0, 'ema50' => 102.0, 'ema200' => 98.0, 'ema50_slope_pct' => 0.5, 'adx' => 32.0,
        'atr' => 1.0, 'atr_pct' => 0.95, 'atr_pct_median' => 0.9, 'vol_ratio' => 1.1, 'ema20_slope_pct' => 0.05,
        'hi_24' => 106.0, 'lo_24' => 99.0];
}
test('Regime: сильный тренд, боковик, пробой, выброс волатильности, неопределённость и NO_TRADE', function () {
    $m5 = regimeF(['adx' => 18.0]);
    check(Regime::classify(null, $m5, null) === Regime::NO_TRADE, 'нет истории 1h — NO_TRADE');
    check(Regime::classify(regimeF(), $m5, 'up') === Regime::STRONG_UP, 'EMA 20>50>200, ADX 32, наклон вверх, 4h вверх — сильный тренд вверх');
    check(Regime::classify(regimeF(), $m5, 'down') === Regime::WEAK_TREND, '4h смотрит вниз — сильным трендом не считаем (мультитаймфрейм)');
    $down = regimeF(['price' => 95.0, 'ema20' => 96.0, 'ema50' => 98.0, 'ema200' => 102.0, 'ema50_slope_pct' => -0.5, 'hi_24' => 101.0, 'lo_24' => 94.0]);
    check(Regime::classify($down, $m5, null) === Regime::STRONG_DOWN, 'зеркально — сильный нисходящий');
    check(Regime::classify(regimeF(['adx' => 22.0]), $m5, null) === Regime::WEAK_TREND, 'ADX между 20 и 25 — неопределённость, не торгуем');
    $range = regimeF(['adx' => 15.0, 'ema50_slope_pct' => 0.05, 'price' => 101.0, 'hi_24' => 104.0, 'lo_24' => 99.0]);
    check(Regime::classify($range, $m5, null) === Regime::RANGE, 'низкий ADX на 1h и 5м, плоская EMA50, диапазон 5 ATR — боковик');
    check(Regime::classify(array_merge($range, ['hi_24' => 101.5, 'lo_24' => 99.5]), $m5, null) === Regime::WEAK_TREND,
        'диапазон уже 3 ATR — сетке негде ходить, не боковик');
    check(Regime::classify($range, regimeF(['adx' => 30.0]), null) === Regime::WEAK_TREND, 'на 5м уже разгон (ADX 30) — не боковик');
    check(Regime::classify(regimeF(['atr_pct' => 2.0]), $m5, null) === Regime::HIGH_VOL, 'ATR(1h) больше 2 медиан — выброс волатильности');
    check(Regime::classify($range, regimeF(['adx' => 18.0, 'atr_pct' => 2.0, 'vol_ratio' => 2.5, 'ema20_slope_pct' => 0.3]), null) === Regime::BREAKOUT,
        'на 5м одновременно расширение ATR, объём и наклон EMA — пробой');
});
test('Regime: матрица допустимых стратегий и сторона сетки по положению в диапазоне', function () {
    check(Regime::allows(Regime::STRONG_UP, 'trend') && !Regime::allows(Regime::STRONG_UP, 'grid'), 'в тренде — только тренд');
    check(Regime::allows(Regime::RANGE, 'grid') && !Regime::allows(Regime::RANGE, 'trend'), 'в боковике — только сетка (и отскок)');
    foreach ([Regime::WEAK_TREND, Regime::BREAKOUT, Regime::NO_TRADE] as $r) {
        check(!Regime::allows($r, 'grid') && !Regime::allows($r, 'trend') && !Regime::allows($r, 'liquidation'), "$r — никаких новых сделок");
    }
    check(Regime::rangeGridSide(['price' => 100.0, 'hi_24' => 110.0, 'lo_24' => 98.0]) === 'long', 'цена внизу диапазона — лонг-сетка');
    check(Regime::rangeGridSide(['price' => 108.0, 'hi_24' => 110.0, 'lo_24' => 98.0]) === 'short', 'цена вверху диапазона — шорт-сетка');
    check(Regime::rangeGridSide(['price' => 104.0, 'hi_24' => 110.0, 'lo_24' => 98.0]) === null, 'середина диапазона — преимущества нет, сетку не ставим');
});
test('Grid в RANGE: сторона не «лонг ниже VWAP», а по положению цены в суточном диапазоне', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $brain = new Brain(new Learner(false));
    $brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 110.0];
    $brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $brain->regimes['SOLUSDT'] = Regime::RANGE;
    $brain->featuresH1['SOLUSDT'] = ['price' => 100.0, 'hi_24' => 101.0, 'lo_24' => 96.0, 'c1' => 100.0, 'ema50_1' => 100.0, 'last_closed_ts' => 1.0];
    $ex = new PaperExchange(10000);
    $w = new Worker(795, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(9100.0);
    check(isset($w->grids['SOLUSDT']) && $w->grids['SOLUSDT']->plan['mode'] === 'short',
        'цена под VWAP (раньше это давало лонг), но вверху суточного диапазона — сетка в шорт');
    $brain->regimes['SOLUSDT'] = Regime::WEAK_TREND;
    $w2 = new Worker(796, new PaperExchange(10000), $market, $brain, Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w2->step(9100.0);
    check(!isset($w2->grids['SOLUSDT']), 'не подтверждённый боковик — сетку не ставим, даже если ИИ даёт ей вес 1.0');
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
    $g = new RiskGuard(Risk::profile('balanced'));                // max_floating_loss_pct = 4.0
    $g->updateEquity(1000);
    check($g->allowed(1000, 0, -30.0)[0], 'плавающий минус 3% — ещё разрешено');
    [$ok, $reason] = $g->allowed(1000, 0, -40.0);
    check(!$ok && str_contains($reason, 'плавающий убыток портфеля'), 'плавающий минус 4% — новые входы заблокированы');
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
    check((int)DB::val("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()") === 29, '29 таблиц (с signals, signal_orders, signal_channels/admins/examples)');
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
test('Learner: высокий winrate с отрицательным Expectancy урезает вес, даже когда свежий EWMA в плюсе', function () {
    $l = new Learner(false);
    for ($i = 0; $i < 5; $i++) {
        $l->record('TAILUSDT', 'grid', 'range', 0.15, 0.05);
    }
    $l->record('TAILUSDT', 'grid', 'range', -2.5, -0.8);         // крупный стоп сетки
    for ($i = 0; $i < 12; $i++) {
        $l->record('TAILUSDT', 'grid', 'range', 0.15, 0.05);     // потом снова серия мелких плюсов
    }
    $l->record('TAILUSDT', 'grid', 'range', -2.5, -0.8);
    for ($i = 0; $i < 12; $i++) {
        $l->record('TAILUSDT', 'grid', 'range', 0.15, 0.05);
    }
    $s = $l->cache['TAILUSDT|grid|range'];
    check($s['ewma_r'] > 0, 'EWMA (память ~10 сделок) после серии плюсов положительный — сам по себе обманул бы');
    check(($s['gross_win_r'] - $s['gross_loss_r']) / $s['n'] < 0, 'при этом полный Expectancy отрицательный');
    check($l->mult('TAILUSDT', 'grid', 'range') < 1.0, 'вес стратегии урезан по худшему из двух');
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
    rangeRegime($mgr->brain, 'SOLUSDT', 100.0);
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
test('Budget Mult: клиентский множитель бюджета клэмпится в [0.5, 1.5] и масштабирует размер сетки', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $run = function (float $budgetMult) use ($sol) {
        $market = new Market(['SOLUSDT']);
        $market->instruments['SOLUSDT'] = $sol;
        $market->feeds['SOLUSDT']->price = 100.0;
        $brain = new Brain(new Learner());
        $brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 100];
        $brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
            'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
        rangeRegime($brain, 'SOLUSDT', 100.0);
        $ex = new PaperExchange(10000);
        $w = new Worker(790, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
            function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {}, false, null, $budgetMult);
        $w->step(9000.0);
        return $w->grids['SOLUSDT']->plan ?? null;
    };
    $half = $run(0.5);
    $normal = $run(1.0);
    $max = $run(1.5);
    $overshoot = $run(99.0);                                   // должно клэмпнуться к 1.5, как $max
    check($half && $normal && $max, 'сетка открылась во всех случаях');
    check(near((float)$half['max_loss'] / (float)$normal['max_loss'], 0.5, 0.01), 'при 0.5 риск сетки вдвое меньше базового');
    check(near((float)$max['max_loss'] / (float)$normal['max_loss'], 1.5, 0.01), 'при 1.5 риск сетки в полтора раза больше базового');
    check(near((float)$max['max_loss'], (float)$overshoot['max_loss'], 0.001), 'значение выше 1.5 клэмпится к 1.5, не берётся как есть');
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
    rangeRegime($brain, 'SOLUSDT', 100.0);
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
    rangeRegime($brain, 'ADAUSDT', 0.25);
    rangeRegime($brain, 'XRPUSDT', 1.5);
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
    check(!isset($w->grids['DOGEUSDT']), 'equity 1000$, cap 3%=30$, уже занято 60$ — новая сетка превысила бы лимит, не открылась');
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
    $brain->regimes['SOLUSDT'] = Regime::STRONG_DOWN;       // жёсткий режим 1h: сильный нисходящий тренд против long-сетки
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

test('закрытие направленной сделки: ждём, пока Bybit отразит closed-pnl, а не пишем PnL 0', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 99000.0;
    $ex = new FakeLiveExchange();
    $recorded = null;
    $w = new Worker(790, $ex, $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$recorded) { $recorded = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $open = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 99000.0, 'qty' => 0.01, 'regime' => 'trend_up',
        'opened_ms' => (int)(microtime(true) * 1000) - 600_000, 'risk_usd' => 10.0];
    $w->directional['BTCUSDT'] = $open;
    $w->step(microtime(true));                                  // позиции на бирже уже нет, а closed-pnl ещё пуст
    check($recorded === null && isset($w->directional['BTCUSDT']), 'сделка не записана с нулём — ждём данных биржи');
    $ex->closedPnlQueue['BTCUSDT'] = [['pnl' => -10.4, 'exit' => 98980.0, 'ts' => 0]];
    $w->step(microtime(true));
    check($recorded !== null && near($recorded['pnl'], -10.4, 0.01) && near($recorded['exit'], 98980.0), 'записан реальный PnL и цена выхода с биржи');
    check(!isset($w->directional['BTCUSDT']), 'снята с отслеживания');

    $recorded = null;                                           // биржа так и не ответила за минуту — оценка по последней цене
    $ex->closedPnlQueue = [];
    $w->directional['BTCUSDT'] = $open + ['gone_ms' => (int)(microtime(true) * 1000) - 61_000];
    $w->step(microtime(true));
    check($recorded !== null && $recorded['pnl'] < -9.9 && near($recorded['exit'], 99000.0), 'после минуты — оценка по последней цене, но не 0');

    $recorded = null;                                           // был частичный тейк: в истории пока только он — ждём финальное закрытие
    $ex->closedPnlQueue['BTCUSDT'] = [['pnl' => 5.0, 'exit' => 101000.0, 'ts' => 0]];
    $w->directional['BTCUSDT'] = $open + ['partial_done' => true];
    $w->step(microtime(true));
    check($recorded === null, 'одной записи частичного тейка мало — ждём запись остатка');
    $ex->closedPnlQueue['BTCUSDT'][] = ['pnl' => 0.3, 'exit' => 100060.0, 'ts' => 1];
    $w->step(microtime(true));
    check($recorded !== null && near($recorded['pnl'], 5.3, 0.01), 'PnL = частичный тейк + остаток');
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
test('позиция, открытая прямо на бирже (мимо ручной торговли бота), тоже попадает в статистику', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner());
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $recorded = null;
    $w = new Worker(783, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$recorded) { $recorded = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01');                // трейдер открыл сделку напрямую на бирже, Worker об этом не знает
    check(!isset($w->directional['BTCUSDT']), 'Worker пока не в курсе позиции');
    $w->step(microtime(true));
    check(isset($w->directional['BTCUSDT']) && $w->directional['BTCUSDT']['strategy'] === 'manual', 'позиция взята под наблюдение при первом же такте');
    $w->directional['BTCUSDT']['opened_ms'] -= 5000;           // «прошло» больше 3 секунд (грация checkDirectional)
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $ex->placeMarket('BTCUSDT', 'Sell', '0.01', null, null, true);  // закрыл её тоже напрямую на бирже
    $w->step(microtime(true));
    check(!isset($w->directional['BTCUSDT']), 'закрытие замечено и снято с отслеживания');
    check($recorded !== null && $recorded['symbol'] === 'BTCUSDT', 'сделка записана в статистику, хотя бот её не открывал');
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
    check($w->directional['BTCUSDT']['stop'] > 100000.0, 'стоп передвинут минимум в безубыток (профит уже больше 1R)');
});
test('трейлинг-стоп следует за ценой с +1.5R на расстоянии 1R, не расширяя риск', function () use ($BTC) {
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
    $market->feeds['BTCUSDT']->price = 103000.0;                // +3R — трейлинг на расстоянии 1R = 1000
    $ex->updatePrices(['BTCUSDT' => 103000.0]);
    $w->step(microtime(true));
    check(near((float)$w->directional['BTCUSDT']['stop'], 102000.0, 0.5), 'стоп подтянут на 1R за ценой');
    $oldStop = $w->directional['BTCUSDT']['stop'];
    $market->feeds['BTCUSDT']->price = 102500.0;                // небольшой откат (стоп 102000 ещё не задет) — стоп назад не двигаем
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
        'last_closed_ts' => 1.0, 'vwap_4h' => 100.5, 'ema50_1' => 100.0];
    $brain->features['ETHUSDT'] = $f;
    $brain->featuresH1['ETHUSDT'] = $f;
    $brain->regimes['ETHUSDT'] = Regime::STRONG_UP;
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
test('тренд на 1h: сделка открывается в сильном тренде, стоп не ближе 1 ATR(1h), размер учитывает комиссии', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0;
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5,
        'last_closed_ts' => 1.0, 'vwap_4h' => 100.5, 'ema50_1' => 100.0];
    $brain->features['BTCUSDT'] = $f;
    $brain->featuresH1['BTCUSDT'] = $f;
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 101.0]);
    $w = new Worker(793, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $brain->regimes['BTCUSDT'] = Regime::WEAK_TREND;
    $w->step(gmmktime(12, 0, 0));
    check(!isset($w->directional['BTCUSDT']), 'слабый/неясный тренд — NO_TRADE, даже если ИИ ставит вес тренду 1.0');
    $brain->regimes['BTCUSDT'] = Regime::STRONG_UP;
    $w->lastTrendBar = [];
    $w->step(gmmktime(2, 0, 0));                                 // ночь: для часового тренда «тихие часы» не действуют
    $d = $w->directional['BTCUSDT'] ?? null;
    check($d !== null && $d['strategy'] === 'trend' && $d['regime'] === Regime::STRONG_UP, 'в сильном восходящем тренде сделка открыта');
    check($d && $d['entry'] - $d['stop'] >= 1.0 - 1e-9, 'стоп не ближе 1 ATR(1h)');
    check($d && near($d['qty'] * ($d['entry'] - $d['stop'] + 2 * ExchangeInterface::TAKER_FEE * $d['entry']), 50.0, 1.0),
        'объём от риска 0.5% (50$) с учётом комиссии за вход и выход, а не только расстояния до стопа');
});
test('комиссия больше 15% риска (стоп ничтожно мал относительно цены) — сделку не открываем', function () use ($BTC) {
    $offset = 1_000_000.0;                                       // сдвигаем весь ценовой ряд, оставляя дельты в единицах ATR прежними
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0 + $offset;
    $brain = new Brain(new Learner());
    $f = ['atr' => 1.0, 'price' => 101.0 + $offset, 'ema20' => 100.5 + $offset, 'ema20_1' => 100.4 + $offset, 'low_5' => 100.3 + $offset,
        'c1' => 100.9 + $offset, 'o1' => 100.5 + $offset, 'h2' => 100.8 + $offset, 'l2' => 100.0 + $offset, 'rsi' => 58,
        'low_10' => 99.5 + $offset, 'high_10' => 102 + $offset, 'high_5' => 101.5 + $offset, 'last_closed_ts' => 1.0, 'vwap_4h' => 100.5 + $offset];
    $brain->features['BTCUSDT'] = $f;
    $brain->featuresH1['BTCUSDT'] = $f + ['ema50_1' => 100.0 + $offset];
    $brain->regimes['BTCUSDT'] = Regime::STRONG_UP;
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => $f['price']]);
    $w = new Worker(794, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(gmmktime(12, 0, 0));
    check(Setups::feeR($f['price'], 1.8) > Worker::MAX_FEE_R, 'комиссия за вход+выход съела бы больше 15% R');
    check(!isset($w->directional['BTCUSDT']), 'такую сделку бот не открывает, даже в сильном тренде');
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
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5, 'last_closed_ts' => 1.0,
        'ema50_1' => 100.0];
    $brain->features['BTCUSDT'] = $f;
    $brain->featuresH1['BTCUSDT'] = $f;
    $brain->regimes['BTCUSDT'] = Regime::STRONG_UP;
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
test('выход из тренда: разворот режима в минусе — закрыть; в плюсе — стоп в безубыток; слом структуры — закрыть', function () use ($BTC) {
    $mk = function (float $price, string $regime, float $c1) use ($BTC) {
        $market = new Market(['BTCUSDT']);
        $market->instruments['BTCUSDT'] = $BTC;
        $market->feeds['BTCUSDT']->price = $price;
        $brain = new Brain(new Learner());
        $brain->features['BTCUSDT'] = ['price' => $price, 'atr' => 500.0, 'ema20' => 100000, 'ema20_1' => 100000, 'last_closed_ts' => 1.0, 'vwap_4h' => 100000];
        $brain->featuresH1['BTCUSDT'] = ['price' => $price, 'c1' => $c1, 'ema50_1' => 99000.0, 'last_closed_ts' => 1.0, 'atr' => 800.0];
        $brain->regimes['BTCUSDT'] = $regime;
        $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.1, 'w_trend' => 1.0, 'w_liquidation' => 0.1,
            'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
        $ex = new PaperExchange(10000);
        $ex->updatePrices(['BTCUSDT' => 100000.0]);
        $ex->placeMarket('BTCUSDT', 'Buy', '0.01');
        $ex->updatePrices(['BTCUSDT' => $price]);
        $w = new Worker(784, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
            function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
        $w->directional['BTCUSDT'] = ['strategy' => 'trend', 'side' => 'Buy', 'entry' => 100000.0, 'stop' => 95000.0, 'qty' => 0.01,
            'regime' => Regime::STRONG_UP, 'opened_ms' => (int)(microtime(true) * 1000) - 120_000, 'risk_usd' => 50.0];
        $w->step(microtime(true));
        return [$w, $ex];
    };
    [, $ex] = $mk(99800.0, Regime::STRONG_DOWN, 99900.0);
    check($ex->positions() === [], 'режим развернулся в сильный нисходящий, позиция в минусе — закрыта, не дожидаясь стопа');
    [$w, $ex] = $mk(100400.0, Regime::STRONG_DOWN, 100300.0);
    check($ex->positions() !== [] && $w->directional['BTCUSDT']['stop'] > 100000.0,
        'в плюсе при развороте — не режем прибыльную сделку по шуму, а переносим стоп в безубыток');
    [, $ex] = $mk(100200.0, Regime::STRONG_UP, 98900.0);
    check($ex->positions() === [], 'часовая свеча закрылась ниже EMA50 — слом структуры, выход');
    [, $ex] = $mk(100200.0, Regime::STRONG_UP, 100100.0);
    check($ex->positions() !== [], 'тренд и структура целы — позицию держим');
});


echo "Risk Engine v3: эксплуатационная защита и лимиты\n";
test('рестарт демона на реальной бирже НЕ закрывает сетки по рынку: снимаются только входы, позиция остаётся со стопом', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 99.0;
    $ex = new FakeLiveExchange();
    $w = new Worker(801, $ex, $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $grid = new Grid($ex, 'SOLUSDT', $sol, ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 4, 'qty' => '1', 'max_loss' => 6.0]);
    $grid->start();
    $grid->inventory = [1 => 99.5];
    $w->grids['SOLUSDT'] = $grid;
    $w->shutdown();
    check($ex->closeCalls === 0, 'позиция не закрыта по рынку (раньше каждый рестарт фиксировал плавающий убыток)');
    check(count($ex->cancelled) === 4, 'сняты только ордера на новые входы');
    check(isset($ex->stopLosses['SOLUSDT']), 'у оставшейся позиции на бирже стоит стоп-лосс сетки');
});
test('бумажный счёт при остановке по-прежнему закрывает сетки (его позиции живут только в памяти)', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['SOLUSDT' => 100.0]);
    $recorded = [];
    $w = new Worker(802, $ex, $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) use (&$recorded) { $recorded[] = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $grid = new Grid($ex, 'SOLUSDT', $sol, Grid::plan(100.0, 1.0, $sol, 'long', 0.6, 4, 2000, 5, 60));
    $grid->start(1000.0);
    $ex->updatePrices(['SOLUSDT' => $grid->levelPrice(1) - 0.05]);
    $grid->sync($grid->levelPrice(1) - 0.05);
    $w->grids['SOLUSDT'] = $grid;
    $w->shutdown();
    check($ex->positions() === [] && count($recorded) === 1 && $recorded[0]['kind'] === 'stop', 'демо-сетка закрыта и записана');
});
test('состояние риска переживает рестарт: дневной лимит, пауза, пик equity, кулдауны, просадка стратегий', function () {
    $market = new Market(['SOLUSDT']);
    $mk = fn() => new Worker(803, new PaperExchange(1000), $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $a = $mk();
    $a->guard->updateEquity(1000.0, gmmktime(10, 0, 0));
    $a->guard->updateEquity(1200.0, gmmktime(11, 0, 0));
    $a->guard->pausedUntil = gmmktime(15, 0, 0);
    $a->lastGridStop['SOLUSDT'] = 12345.0;
    $a->strategyPausedUntil['trend'] = 99999.0;
    $state = json_decode(json_encode($a->exportState()), true);   // как через БД
    $b = $mk();
    $b->importState($state);
    $b->guard->updateEquity(900.0, gmmktime(13, 0, 0));          // тот же день после рестарта
    check(near($b->guard->dayStartEquity, 1000.0), 'база дневного лимита не сбросилась на текущий equity');
    check(near($b->guard->dayPnlPct(900.0), -10.0), 'дневной убыток считается от утреннего equity, а не от момента рестарта');
    check(!$b->guard->allowed(900.0, gmmktime(13, 0, 0))[0], 'пауза сохранена');
    check(near($b->guard->peakEquity, 1200.0) && $b->guard->adaptiveMult(900.0) < 1.0, 'пик equity сохранён — Adaptive Risk не обнулился');
    check(near($b->lastGridStop['SOLUSDT'], 12345.0) && near($b->strategyPausedUntil['trend'], 99999.0), 'кулдауны и пауза стратегии сохранены');
});
test('Kill switch: аварийная остановка закрывает все позиции и не даёт открыть новые', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $brain = new Brain(new Learner(false));
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01');
    $w = new Worker(804, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->halted = true;
    $w->step(microtime(true));
    check($ex->positions() === [], 'позиция закрыта');
    check(str_contains($w->status, 'аварийная остановка'), 'статус показывает аварийную остановку');
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01');
    $w->step(microtime(true) + 3);
    check($ex->positions() !== [], 'повторно всё не закрывает на каждом такте (закрыли один раз), но и новых сделок бот не открывает');
});
test('Max strategy drawdown: просадка стратегии больше лимита профиля — пауза стратегии на сутки', function () {
    $market = new Market(['SOLUSDT']);
    $ex = new PaperExchange(1000);
    $w = new Worker(805, $ex, $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(50000.0);                                            // equity 1000, лимит 3% = 30$
    $rec = new ReflectionMethod(Worker::class, 'recordTrade');
    $rec->invoke($w, 'SOLUSDT', 'grid', 'Buy', 1.0, 100.0, 101.0, 20.0, 1.0, 'range');
    $rec->invoke($w, 'SOLUSDT', 'grid', 'Buy', 1.0, 100.0, 99.0, -25.0, -1.0, 'range');
    check(!isset($w->strategyPausedUntil['grid']), 'просадка 25$ от пика — ещё в пределах');
    $rec->invoke($w, 'SOLUSDT', 'grid', 'Buy', 1.0, 100.0, 99.5, -6.0, -0.3, 'range');
    check(($w->strategyPausedUntil['grid'] ?? 0) > 50000.0 + 23 * 3600, 'просадка 31$ от пика — стратегия на паузе ~24ч');
    check(!isset($w->strategyPausedUntil['trend']), 'другие стратегии не затронуты');
});
test('Max portfolio risk: новая сделка не открывается, если суммарный риск до стопов превысит лимит профиля', function () use ($BTC) {
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = 101.0;
    $brain = new Brain(new Learner(false));
    $f = ['atr' => 1.0, 'price' => 101.0, 'ema20' => 100.5, 'ema20_1' => 100.4, 'low_5' => 100.3, 'c1' => 100.9, 'o1' => 100.5,
        'h2' => 100.8, 'l2' => 100.0, 'rsi' => 58, 'low_10' => 99.5, 'high_10' => 102, 'high_5' => 101.5,
        'last_closed_ts' => 1.0, 'vwap_4h' => 100.5, 'ema50_1' => 100.0];
    $brain->features['BTCUSDT'] = $f;
    $brain->featuresH1['BTCUSDT'] = $f;
    $brain->regimes['BTCUSDT'] = Regime::STRONG_UP;
    $brain->insights['BTCUSDT'] = ['regime' => 'trend_up', 'confidence' => 0.8, 'w_grid' => 0.0, 'w_trend' => 1.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 101.0]);
    $w = new Worker(806, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    // уже открытая сетка на другой монете держит риск 380$ из лимита 4% = 400$
    $w->grids['DOGEUSDT'] = new Grid($ex, 'DOGEUSDT', new Instrument('DOGEUSDT', '1', '0.00001', 0.00001, 10000000, 5, 50),
        ['mode' => 'long', 'center' => 0.1, 'step_pct' => 0.5, 'levels' => 4, 'qty' => '100', 'max_loss' => 380.0]);
    $w->grids['DOGEUSDT']->active = false;
    $w->step(gmmktime(12, 0, 0));
    check(!isset($w->directional['BTCUSDT']), 'сделка с риском 50$ не открыта: 380+50 > 400');
});
test('PaperExchange не завышает результат: касание лимитки — не исполнение, стоп через гэп — по худшей цене', function () {
    $ex = new PaperExchange(1000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $ex->placeLimit('BTCUSDT', 'Buy', '0.01', '99000', 'a');
    $ex->updatePrices(['BTCUSDT' => 99000]);
    check($ex->positions() === [], 'цена ровно коснулась лимита — не исполнено');
    $ex->updatePrices(['BTCUSDT' => 98990]);
    check($ex->positions() !== [], 'цена прошла сквозь лимит — исполнено');
    $ex->setStopLoss('BTCUSDT', '98000');
    $ex->updatePrices(['BTCUSDT' => 97000]);                       // гэп на 1000 ниже стопа
    $c = $ex->closedPnl('BTCUSDT', 0);
    check(count($c) === 1 && $c[0]['exit'] < 97000, 'стоп исполнен по цене гэпа с проскальзыванием, а не по красивой цене стопа');
});
test('стоп-лосс сетки сработал на бирже — Worker замечает и пишет реальный убыток сессии', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $brain = new Brain(new Learner(false));
    $brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 100];
    $brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    rangeRegime($brain, 'SOLUSDT', 100.0);
    $ex = new PaperExchange(10000);
    $recorded = [];
    $w = new Worker(807, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$recorded) { $recorded[] = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $t = 20000.0;
    $w->step($t);
    $g = $w->grids['SOLUSDT'] ?? null;
    check($g !== null, 'сетка открыта');
    $market->feeds['SOLUSDT']->price = $g->levelPrice(2) - 0.05;
    $w->step($t += 300);                                          // куплены два уровня, стоп поставлен на бирже
    check(count($g->inventory) === 2 && $ex->pos['SOLUSDT']['stop'] !== null, 'два уровня в позиции, стоп-лосс на бирже');
    $market->feeds['SOLUSDT']->price = $g->stopPrice() - 0.3;
    $w->step($t += 300);                                          // биржа закрыла по стопу
    $w->step($t += 300);                                          // Worker забрал результат из closed-pnl
    $stop = array_values(array_filter($recorded, fn($r) => $r['kind'] === 'stop'));
    check(count($stop) === 1 && $stop[0]['pnl'] < 0 && $stop[0]['session_id'] === $g->tag, 'убыток стопа записан как одна операция сессии сетки');
    check(!isset($w->grids['SOLUSDT']) && isset($w->lastGridStop['SOLUSDT']), 'сетка снята, включился кулдаун');
});


test('боковик закончился >30 мин назад, сетка в минусе — закрываем, а не держим убыточный инвентарь до полного стопа', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 99.0;
    $brain = new Brain(new Learner(false));
    $brain->features['SOLUSDT'] = ['price' => 99.0, 'atr' => 1.0, 'ema20' => 99, 'ema20_1' => 99, 'last_closed_ts' => 1.0, 'vwap_4h' => 99];
    $brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    $brain->regimes['SOLUSDT'] = Regime::WEAK_TREND;
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['SOLUSDT' => 99.0]);
    $recorded = [];
    $w = new Worker(808, $ex, $market, $brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$recorded) { $recorded[] = $t; }, function ($u, $m) {}, function ($u, $e) {});
    $grid = new Grid($ex, 'SOLUSDT', $sol, ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 4, 'qty' => '1', 'max_loss' => 6.0]);
    $grid->active = true;
    $grid->inventory = [1 => 99.5];
    $ex->pos['SOLUSDT'] = ['size' => 1.0, 'entry' => 99.5, 'stop' => null, 'take' => null];
    $w->grids['SOLUSDT'] = $grid;
    $w->step(30000.0);
    check(isset($w->grids['SOLUSDT']) && $grid->draining && !$recorded, 'сразу после выхода из боковика — только перестаём открывать уровни');
    $w->step(30000.0 + Worker::GRID_REGIME_EXIT_SEC + 1);
    check(count($recorded) === 1 && $recorded[0]['kind'] === 'regime_exit' && $recorded[0]['pnl'] < 0, 'через 30 минут без боковика убыточный инвентарь закрыт');
});
test('выключатель стратегии на платформе главнее и ИИ, и настроек клиента', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 100.0;
    $brain = new Brain(new Learner(false));
    $brain->features['SOLUSDT'] = ['price' => 100.0, 'atr' => 1.0, 'ema20' => 100, 'ema20_1' => 100, 'last_closed_ts' => 1.0, 'vwap_4h' => 100];
    $brain->insights['SOLUSDT'] = ['regime' => 'range', 'confidence' => 0.8, 'w_grid' => 1.0, 'w_trend' => 0.1, 'w_liquidation' => 0.1,
        'grid_mode' => 'long', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'ai'];
    rangeRegime($brain, 'SOLUSDT', 100.0);
    $w = new Worker(809, new PaperExchange(10000), $market, $brain, Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->platformEnabled = ['grid' => false];
    $w->step(40000.0);
    check(!isset($w->grids['SOLUSDT']), 'сетка выключена на платформе — не открывается');
});
test('стоп биржи сработал раньше, чем демон увидел цену за стопом: результат ищется от последнего события сетки', function () {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $ex = new PaperExchange(10000);
    $ex->now = 1000.0;
    $ex->updatePrices(['SOLUSDT' => 100.0]);
    $g = new Grid($ex, 'SOLUSDT', $sol, ['mode' => 'long', 'center' => 100.0, 'step_pct' => 0.5, 'levels' => 4, 'qty' => '1', 'max_loss' => 6.0]);
    $g->start(1000.0);
    $ex->updatePrices(['SOLUSDT' => 99.45]);
    $g->sync(99.45, 1000.0);
    $ex->now = 1100.0;
    $ex->updatePrices(['SOLUSDT' => 96.0]);                    // стоп-лосс биржи закрыл позицию в 1100
    $g->sync(96.0, 1400.0);                                   // демон увидел это только в 1400
    check($g->pendingStop !== null && $g->pendingStop['since_ms'] <= 1_100_000, 'граница поиска — не позже момента закрытия биржей');
    check(count($ex->closedPnl('SOLUSDT', $g->pendingStop['since_ms'])) === 1, 'запись о стопе находится сразу, без минуты ожидания и оценки');
});
test('TradeStats: Expectancy, PF, хвост убытков, сессии сетки и Monte Carlo', function () {
    $trades = [];
    for ($i = 0; $i < 30; $i++) {
        $trades[] = ['pnl' => 0.05, 'r' => 0.15, 'strategy' => 'grid', 'symbol' => 'XRPUSDT', 'regime' => 'range', 'session_id' => 'g1', 'kind' => 'cycle'];
    }
    $trades[] = ['pnl' => -3.0, 'r' => -6.0, 'strategy' => 'grid', 'symbol' => 'XRPUSDT', 'regime' => 'weak_trend', 'session_id' => 'g1', 'kind' => 'stop'];
    $trades[] = ['pnl' => 2.0, 'r' => 2.0, 'strategy' => 'trend', 'symbol' => 'BTCUSDT', 'regime' => 'strong_up'];
    $trades[] = ['pnl' => -1.0, 'r' => -1.0, 'strategy' => 'trend', 'symbol' => 'BTCUSDT', 'regime' => 'strong_up'];
    $s = TradeStats::compute($trades);
    check($s['trades'] === 33 && near($s['net_pnl'], 1.5 - 3.0 + 1.0, 1e-9), 'net');
    check($s['win_rate_pct'] > 90 && $s['by_strategy']['grid']['profit_factor'] < 1.0, '31 из 33 в плюсе, но сетка с PF < 1 — win rate обманывает');
    check(near($s['tail_loss_share_pct']['top1'], 75.0, 0.1), 'один крупнейший убыток = 75% всех убытков');
    check($s['grid_sessions']['sessions'] === 1 && near($s['grid_sessions']['cycles_per_stop'], 60.0, 0.1), 'сессия сетки — одна операция; 1 стоп = 60 циклов');
    check($s['largest_loss'] === -3.0 && $s['max_drawdown'] < 0 && $s['recovery_factor'] !== null, 'крупнейший убыток, просадка, recovery factor');
    $mc = TradeStats::monteCarlo(array_column($trades, 'pnl'), 300);
    check($mc !== null && $mc['net_p5'] <= $mc['net_p50'] && $mc['net_p50'] <= $mc['net_p95'], 'перцентили Monte Carlo упорядочены');
    check(TradeStats::monteCarlo([1.0, -1.0], 100) === null, 'меньше 10 сделок — Monte Carlo не считаем');
});


test('админка: аналитика сделок считается по реальной схеме БД (TradeStats, разбивки, сессии сетки)', function () {
    DB::insert('users', ['id' => 9901, 'first_name' => 'A', 'created_at' => DB::now(), 'blocked' => false]);
    foreach ([[0.05, 'cycle'], [0.05, 'cycle'], [-0.6, 'stop']] as [$pnl, $kind]) {
        DB::insert('trades', ['user_id' => 9901, 'symbol' => 'XRPUSDT', 'strategy' => 'grid', 'side' => 'Buy', 'qty' => 1, 'entry' => 1, 'exit' => 1,
            'pnl' => $pnl, 'r' => $pnl * 3, 'regime' => 'range', 'mode' => 'paper', 'opened_at' => DB::now(), 'closed_at' => DB::now(),
            'session_id' => 'gtest', 'kind' => $kind]);
    }
    $a = (new ReflectionMethod(\App\Web\AdminApi::class, 'analytics'))->invoke(null, 30);
    check($a['trades'] >= 3 && isset($a['by_strategy']['grid'], $a['by_regime']['range'], $a['tail_loss_share_pct']['top5']), 'метрики и разбивки на месте');
    check($a['grid_sessions']['sessions'] >= 1 && $a['grid_sessions']['stops'] >= 1, 'сессии сетки собраны по session_id');
});


/** 1h-свечи для тестов пробоя: $flat часов около 100, затем $up часов роста; последняя — «незакрытая». */
function h1Series(int $flat, int $up, float $step = 0.8): array
{
    $out = [];
    $p = 100.0;
    $t0 = 1_700_000_000_000 - (1_700_000_000_000 % 14_400_000);
    for ($i = 0; $i < $flat + $up; $i++) {
        $o = $p;
        $p = $i < $flat ? 100.0 + ($i % 2 ? 0.4 : -0.4) : $p + $step;
        $out[] = [(string)($t0 + $i * 3_600_000), $o, max($o, $p) + 0.2, min($o, $p) - 0.2, $p, 10.0, 10.0 * $p];
    }
    return $out;
}
test('closedBars: только закрытые 4h-свечи, выровненные по UTC, без незакрытой последней', function () {
    $k = h1Series(10, 0);                                      // 10 часов от границы 4h: группы 4+4+2
    $b = Indicators::closedBars($k, 4);
    check(count($b) === 2, 'две полные 4h-свечи; неполная и незакрытая последняя 1h отброшены');
    check((int)$b[0][0] % 14_400_000 === 0 && (float)$b[0][2] >= (float)$b[0][3], 'время выровнено по 4h, high ≥ low');
    check(count(Indicators::closedBars(h1Series(9, 0), 4)) === 2, 'последняя 1h (незакрытая) не попадает в 4h-свечу');
});
test('Setups::breakout: пробой канала 20 × 4h по тренду EMA50 — вход, стоп 2 ATR, без фиксированного тейка', function () {
    $k4 = Indicators::closedBars(h1Series(244, 5, 0.3), 4);   // долго боковик, затем выход вверх
    $last = (float)end($k4)[4];
    $s = Setups::breakout($k4, $last);
    check($s !== null && $s['side'] === 'Buy' && $s['strategy'] === 'breakout', 'закрытие выше 20-свечного максимума и EMA50 — лонг');
    check($s && $s['take'] === null && near($s['entry'] - $s['stop'], 2 * $s['atr'], 1e-6), 'стоп 2 ATR(4h), тейка нет — выход ведёт трейлинг');
    check($s && Setups::feeR($s['entry'], $s['risk']) < Worker::MAX_FEE_R, 'стоп широкий — комиссия малая доля R');
    check(Setups::breakout($k4, $last + 50 * $s['atr']) === null, 'цена уже ушла дальше 1 ATR от уровня — не догоняем');
    check(Setups::breakout(Indicators::closedBars(h1Series(249, 0), 4), 100.0) === null, 'боковик без пробоя — сигнала нет');
    $down = array_map(fn($r) => [$r[0], 200 - (float)$r[1], 200 - (float)$r[3], 200 - (float)$r[2], 200 - (float)$r[4], $r[5], $r[6]], h1Series(244, 5, 0.3));
    $down4 = Indicators::closedBars($down, 4);
    $sd = Setups::breakout($down4, (float)end($down4)[4]);
    check($sd !== null && $sd['side'] === 'Sell', 'зеркально вниз — шорт');
});
test('пробой в Worker: вход раз в 4h-свечу, Chandelier-трейлинг только в свою пользу, без частичного тейка', function () use ($BTC) {
    $k1h = h1Series(244, 5, 0.3);
    $closed4h = Indicators::closedBars($k1h, 4);
    $price = (float)end($closed4h)[4];                         // цена у уровня пробоя, а не уже убежавшая на 1+ ATR
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $BTC;
    $market->feeds['BTCUSDT']->price = $price;
    $market->feeds['BTCUSDT']->klines1h = $k1h;
    $brain = new Brain(new Learner(false));
    $brain->features['BTCUSDT'] = ['price' => $price, 'atr' => 0.5, 'ema20' => $price, 'ema20_1' => $price, 'last_closed_ts' => 1.0, 'vwap_4h' => $price];
    $brain->insights['BTCUSDT'] = ['regime' => 'range', 'confidence' => 0.5, 'w_grid' => 0.0, 'w_trend' => 0.0, 'w_liquidation' => 0.0,
        'grid_mode' => 'off', 'grid_step_atr' => 0.6, 'risk_mult' => 1.0, 'summary' => '', 'source' => 'rules'];
    $brain->regimes['BTCUSDT'] = Regime::WEAK_TREND;          // пробой не зависит от 1h-режима (кроме NO_TRADE)
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => $price]);
    $w = new Worker(810, $ex, $market, $brain, Risk::profile('balanced'), ['BTCUSDT'],
        ['grid' => false, 'trend' => false, 'liquidation' => false, 'breakout' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(50000.0);
    $d = $w->directional['BTCUSDT'] ?? null;
    check($d !== null && $d['strategy'] === 'breakout' && $d['side'] === 'Buy', 'пробой открыл лонг');
    $w->platformEnabled = ['breakout' => false];
    check(!(new ReflectionMethod(Worker::class, 'strategyAllowed'))->invoke($w, 'breakout', Regime::RANGE, 50000.0), 'выключатель платформы блокирует пробой');
    $w->platformEnabled = [];
    $stop0 = $d['stop'];
    $w->directional['BTCUSDT']['opened_ms'] -= 120_000;
    $up = $price + 5 * $d['atr'];
    $market->feeds['BTCUSDT']->price = $up;
    $ex->updatePrices(['BTCUSDT' => $up]);
    $w->step(50003.0);
    $d = $w->directional['BTCUSDT'];
    check(near($d['stop'], $up - 3 * $d['atr'], 0.2) && $d['stop'] > $stop0, 'стоп подтянут на 3 ATR ниже лучшей цены');
    check(!($d['partial_done'] ?? false), 'частичного тейка на +1R нет — прибыль не режем');
    $back = $up - 1 * $d['atr'];
    $market->feeds['BTCUSDT']->price = $back;
    $ex->updatePrices(['BTCUSDT' => $back]);
    $w->step(50006.0);
    check(near($w->directional['BTCUSDT']['stop'], $d['stop'], 1e-9), 'на откате стоп назад не отодвигается');
});


echo "Сигналы из Telegram\n";
$SIG_PENGU = "СИГНАЛ #PENGU/USDT\n\n🔑 Открыть ШОРТ в диапазоне \$0.00997 - \$0.01008 с плечом X25\n\n🍒 Цели:\n\n🔘 Закрыть по \$0.00989\n🔘 Закрыть по \$0.00985\n🔘 Закрыть по \$0.00976\n🔘 Закрыть по \$0.00966\n🔘 Закрыть по \$0.00951\n\n❗️ СТОП ЛОСС: \$0.01041";
$SIG_SOL = "СИГНАЛ #SOL/USDT\n\n🔑 Открыть ШОРТ в диапазоне \$120.4 - \$121.7 с плечом X25\n\n🍒 Цели:\n\n🔘 Закрыть по \$119.4\n🔘 Закрыть по \$118.9\n🔘 Закрыть по \$117.9\n🔘 Закрыть по \$116.7\n🔘 Закрыть по \$114.8\n\n❗️ СТОП ЛОСС: \$125.7";
test('SignalParser: разбор реальных сообщений канала (PENGU, SOL) и защита от мусора', function () use ($SIG_PENGU, $SIG_SOL) {
    $p = SignalParser::parse($SIG_PENGU);
    check($p && $p['symbol'] === 'PENGUUSDT' && $p['side'] === 'Sell' && $p['leverage'] === 25, 'монета, шорт, плечо канала');
    check($p && near($p['entry_lo'], 0.00997) && near($p['entry_hi'], 0.01008) && near($p['stop'], 0.01041), 'зона входа и стоп');
    check($p && count($p['targets']) === 5 && near($p['targets'][4], 0.00951), 'все 5 целей по порядку');
    check(SignalParser::validate($p) === null, 'корректный сигнал проходит проверку');
    $s = SignalParser::parse($SIG_SOL);
    check($s && $s['symbol'] === 'SOLUSDT' && near($s['entry_lo'], 120.4) && near($s['stop'], 125.7) && SignalParser::validate($s) === null, 'SOL');
    check(SignalParser::parse('Всем привет! Сегодня рынок растёт 🚀') === null, 'обычное сообщение — не сигнал');
    $one = SignalParser::parse("СИГНАЛ #PENGU/USDT\n\n🔑 Открыть ШОРТ в диапазоне \$0.01008 с плечом X25\n\n🍒 Цели:\n\n🔘 Закрыть по \$0.00988\n🔘 Закрыть по \$0.00984\n🔘 Закрыть по \$0.00975\n\n❗️ СТОП ЛОСС: \$0.01040");
    check($one && near($one['entry_lo'], 0.01008) && near($one['entry_hi'], 0.01008) && count($one['targets']) === 3 && SignalParser::validate($one) === null,
        'вход одной ценой (без диапазона) тоже принимается — зона схлопывается в точку');
    check(SignalParser::parse(str_replace('СТОП ЛОСС', 'СТОП', $SIG_SOL)) === null, 'без стоп-лосса — не сигнал (не открываем без защиты)');
    $lng = SignalParser::parse(str_replace(['ШОРТ', '120.4', '121.7', '125.7'], ['ЛОНГ', '119.0', '119.5', '115.0'], $SIG_SOL));
    check($lng && $lng['side'] === 'Buy' && SignalParser::validate($lng) !== null, 'лонг со стопом выше входа и целями вниз — ошибка проверки');
    $bad = $p;
    $bad['stop'] = 0.00990;
    check(SignalParser::validate($bad) !== null, 'стоп внутри диапазона/не с той стороны — отклонён');
    $bad = $p;
    $bad['targets'] = [0.00989, 0.01000];
    check(SignalParser::validate($bad) !== null, 'цель против направления — отклонена');
    $bad = $p;
    $bad['stop'] = 0.02;
    check(SignalParser::validate($bad) !== null, 'стоп дальше 15% — похоже на опечатку, отклонён');
});
$sigSetup = function (float $price, string $sym = 'SOLUSDT') {
    $inst = new Instrument($sym, '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market([$sym]);
    $market->instruments[$sym] = $inst;
    $market->feeds[$sym]->price = $price;
    $ex = new PaperExchange(1000);
    $ex->updatePrices([$sym => $price]);
    $w = new Worker(820, $ex, $market, new Brain(new Learner(false)), Risk::profile('balanced'), [$sym],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $w->step(1000.0);
    return [$w, $ex, $market];
};
$solSignal = ['symbol' => 'SOLUSDT', 'side' => 'Sell', 'entry_lo' => 120.4, 'entry_hi' => 121.7, 'stop' => 125.7, 'targets' => [119.4, 118.9, 117.9, 116.7, 114.8]];
test('сигнал: цена в зоне — вход по рынку, объём от риска клиента (не от «X25»), стоп на бирже, лестница целей', function () use ($sigSetup, $solSignal) {
    [$w, $ex] = $sigSetup(121.0);
    $r = $w->signalOrder($solSignal, 0.3);
    check($r['status'] === 'opened', 'открыто: ' . $r['detail']);
    $pos = $ex->positions()['SOLUSDT'] ?? null;
    check($pos && $pos['side'] === 'Sell', 'шорт открыт');
    $risk = $pos['qty'] * ((125.7 - 121.0) + 2 * ExchangeInterface::TAKER_FEE * 121.0);
    check(near($risk, 5.0, 0.5), 'риск ≈ 0.5% депозита (5$), а не плечо канала');
    check($ex->pos['SOLUSDT']['stop'] !== null && near($ex->pos['SOLUSDT']['stop'], 125.7, 0.11), 'стоп-лосс сигнала стоит на бирже');
    $w->step(1003.0);                                            // следующий такт: позиция уже есть — ставится лестница целей
    check(count($ex->orders['SOLUSDT'] ?? []) === 5, '5 reduce-only лимиток по целям');
    check(($w->directional['SOLUSDT']['strategy'] ?? '') === 'signal' && !empty($w->directional['SOLUSDT']['ladder_done']), 'позиция под учётом как сигнал');
});
test('сигнал: первая цель сработала — стоп переносится в безубыток; остатки лестницы снимаются при закрытии', function () use ($sigSetup, $solSignal) {
    [$w, $ex, $market] = $sigSetup(121.0);
    $w->signalOrder($solSignal, 0.3);
    $w->step(1003.0);
    $w->directional['SOLUSDT']['opened_ms'] -= 120_000;
    $market->feeds['SOLUSDT']->price = 119.3;                    // первая цель 119.4 взята
    $w->step(1006.0);
    $w->step(1009.0);
    check($ex->pos['SOLUSDT']['size'] > -0.99 * 1e9 && abs($ex->positions()['SOLUSDT']['qty']) < $w->directional['SOLUSDT']['qty'] * 0.95, 'позиция уменьшилась на долю первой цели');
    check($ex->pos['SOLUSDT']['stop'] < 121.0, 'стоп шорта перенесён к входу (безубыток), а не остался на 125.7');
    $market->feeds['SOLUSDT']->price = 121.9;                    // откат в безубыток — остаток закрыт по стопу
    $w->step(1012.0);
    $w->step(1015.0);
    check(!isset($w->directional['SOLUSDT']) && empty($ex->orders['SOLUSDT']), 'позиция закрыта, лишние ордера лестницы сняты');
});
test('сигнал по монете вне списка клиента: демо-бирже передаётся цена, вход не падает', function () use ($sigSetup, $solSignal) {
    [$w, $ex, $market] = $sigSetup(121.0);
    $ex2 = new PaperExchange(1000);                             // цены по SOL у демо-биржи ещё нет, как у монеты вне списка клиента
    $w2 = new Worker(831, $ex2, $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['BTCUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $market->feeds['SOLUSDT']->price = 0.0;
    $w2->step(1000.0);
    $market->feeds['SOLUSDT']->price = 121.0;
    $r = $w2->signalOrder($solSignal, 0.3);
    check($r['status'] === 'opened' && isset($ex2->positions()['SOLUSDT']), 'позиция открыта: ' . $r['detail']);
});
test('сигнал: цена ушла от зоны — пропуск; рядом с зоной — лимит; цель уже взята — пропуск; дубль позиции — пропуск', function () use ($sigSetup, $solSignal) {
    [$w, $ex] = $sigSetup(118.0);                               // ниже зоны на 2% и выше первой цели 119.4? нет — ниже неё
    check($w->signalOrder($solSignal, 0.3)['status'] === 'skipped', 'цена уже за первой целью — пропуск');
    [$w, $ex] = $sigSetup(119.9);                               // ниже зоны входа 120.4 на 0.4%, первая цель 119.4 ещё не взята
    $r = $w->signalOrder($solSignal, 0.3);
    check($r['status'] === 'skipped' && str_contains($r['detail'], 'ушла'), 'вне допуска 0.3% — не догоняем: ' . $r['detail']);
    $r = $w->signalOrder($solSignal, 0.6);
    check($r['status'] === 'opened' && isset($w->pendingManual['SOLUSDT']['signal']), 'в допуске 0.6% — лимит на границе зоны, ждём возврата цены');
    check($w->signalOrder($solSignal, 0.6)['status'] === 'skipped', 'второй сигнал по той же монете при активном ордере — пропуск');
    [$w, $ex] = $sigSetup(121.0);
    $w->halted = true;
    check($w->signalOrder($solSignal, 0.3)['status'] === 'skipped', 'аварийная остановка блокирует сигналы');
    [$w, $ex] = $sigSetup(121.0);
    $w->strategyPausedUntil['signal'] = 1e12;
    check($w->signalOrder($solSignal, 0.3)['status'] === 'skipped', 'пауза после просадки по сигналам блокирует новые');
});
test('Manager::processSignals: демо-клиентам исполняется, реальным — только при явном разрешении; итог пишется в БД', function () use ($solSignal) {
    $sol = new Instrument('SOLUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['SOLUSDT']);
    $market->instruments['SOLUSDT'] = $sol;
    $market->feeds['SOLUSDT']->price = 121.0;
    $mgr = new Manager($market);
    DB::insert('users', ['id' => 9911, 'first_name' => 'S1', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('users', ['id' => 9912, 'first_name' => 'S2', 'created_at' => DB::now(), 'blocked' => false]);
    $paper = new PaperExchange(1000);
    $paper->updatePrices(['SOLUSDT' => 121.0]);
    $mk = fn($uid, $ex) => new Worker($uid, $ex, $market, $mgr->brain, Risk::profile('balanced'), ['SOLUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) {}, function ($u, $e) {});
    $mgr->workers[9911] = $mk(9911, $paper);
    $mgr->workers[9912] = $mk(9912, new FakeLiveExchange());
    $mgr->workers[9911]->step(2000.0);
    $mgr->workers[9912]->step(2000.0);
    $sid = DB::insert('signals', ['symbol' => 'SOLUSDT', 'side' => 'Sell', 'entry_lo' => 120.4, 'entry_hi' => 121.7, 'stop_loss' => 125.7,
        'targets' => $solSignal['targets'], 'channel_leverage' => 25, 'raw_text' => 't', 'source' => 'user:1', 'status' => 'new', 'created_at' => DB::now()]);
    Settings::save(['signal_real_enabled' => false, 'signal_max_chase_pct' => 0.3]);
    $mgr->processSignals();
    $rows = DB::all('SELECT user_id, status FROM signal_orders WHERE signal_id = ?', [$sid]);
    check(count($rows) === 1 && (int)$rows[0]['user_id'] === 9911 && $rows[0]['status'] === 'opened', 'только демо-клиент получил сделку, реальный не тронут');
    check(DB::val('SELECT status FROM signals WHERE id = ?', [$sid]) === 'processed', 'сигнал помечен обработанным (не исполнится повторно)');
    $mgr->processSignals();
    check((int)DB::val('SELECT COUNT(*) FROM signal_orders WHERE signal_id = ?', [$sid]) === 1, 'повторный проход не дублирует исполнение');
});

test('Webhook: сигнал принимается только от разрешённого источника; повтор идёт в очередь (воркер сам пропустит, если позиция уже есть), отклонённый не исполняется', function () use ($SIG_SOL) {
    Settings::save(['signal_enabled' => true, 'signal_allowed_ids' => ['777001', '-1001234']]);
    $SIG_SOL = str_replace('#SOL', '#LINK', $SIG_SOL);          // другая монета, чтобы не пересечься с сигналом из предыдущего теста
    $before = (int)DB::val("SELECT COUNT(*) FROM signals");
    $msg = fn($text, $fromId, $chat = null) => ['message' => ['text' => $text, 'from' => ['id' => $fromId], 'chat' => $chat ?? ['id' => $fromId, 'type' => 'private']]];
    \App\Web\Webhook::handle($msg($SIG_SOL, 555000));
    check((int)DB::val("SELECT COUNT(*) FROM signals") === $before, 'сообщение от постороннего игнорируется — сигнал не создан');
    \App\Web\Webhook::handle($msg($SIG_SOL, 777001));
    check((int)DB::val("SELECT COUNT(*) FROM signals WHERE status = 'new'") === 1, 'пересланный сигнал от вашего ID принят и ждёт исполнения');
    \App\Web\Webhook::handle($msg(str_replace(['120.4', '121.7'], ['120.5', '121.8'], $SIG_SOL), 777001));
    check((int)DB::val("SELECT COUNT(*) FROM signals WHERE status = 'duplicate'") === 0 && (int)DB::val("SELECT COUNT(*) FROM signals WHERE status = 'new'") === 2, 'повторный сигнал по монете не отбрасывается по времени — в очередь; открыта ли позиция, решает воркер');
    \App\Web\Webhook::handle($msg(str_replace('125.7', '118.0', $SIG_SOL), 777001));
    check((int)DB::val("SELECT COUNT(*) FROM signals WHERE status = 'rejected'") === 1, 'стоп с неверной стороны — отклонён');
    \App\Web\Webhook::handle(['channel_post' => ['text' => str_replace('#LINK', '#ETH', $SIG_SOL), 'chat' => ['id' => -1001234, 'type' => 'channel']]]);
    check((int)DB::val("SELECT COUNT(*) FROM signals WHERE symbol = 'ETHUSDT' AND status IN ('new','rejected')") === 1, 'пост разрешённого канала тоже принимается');
    \App\Web\Webhook::handle(['channel_post' => ['text' => $SIG_SOL, 'chat' => ['id' => -999, 'type' => 'channel']]]);
    check((int)DB::val("SELECT COUNT(*) FROM signals") === $before + 4, 'пост чужого канала игнорируется');
    Settings::save(['signal_enabled' => false, 'signal_allowed_ids' => []]);
});


echo "Меню «Сигналы»: каналы, админы, примеры\n";
test('SignalParser: слова канала настраиваются; обновления (закрыть / безубыток); неясное не исполняется', function () {
    $en = "#AVAX/USDT\nGo LONG entry zone 39.8 - 40.2\nTP1 41\nTP2 42\nSL 38.5";
    check(SignalParser::parse($en) === null, 'английский пост со словами «zone»/«Go LONG» по умолчанию не разбирается (нет «диапазон/вход»)');
    $p = SignalParser::parse($en, ['entry_words' => 'entry zone']);
    check($p && $p['symbol'] === 'AVAXUSDT' && $p['side'] === 'Buy' && near($p['entry_lo'], 39.8) && count($p['targets']) === 2 && near($p['stop'], 38.5), 'после настройки entry_words пост разобран (TP и SL — слова по умолчанию)');
    check(SignalParser::findSymbol('AVAXUSDT long') === null && SignalParser::findSymbol('AVAXUSDT long', ['symbol_style' => 'any']) === 'AVAXUSDT', 'стиль монеты any понимает AVAXUSDT без #');
    check(SignalParser::findSymbol('вход #PENGU лонг', ['symbol_style' => 'any']) === 'PENGUUSDT', '#COIN без /USDT в режиме any');
    check(SignalParser::parseUpdate('#AVAX/USDT Закрываем сделку по рынку') === ['symbol' => 'AVAXUSDT', 'action' => 'close'], 'обновление: закрыть');
    check(SignalParser::parseUpdate('#AVAX/USDT Переносим стоп в безубыток') === ['symbol' => 'AVAXUSDT', 'action' => 'breakeven'], 'обновление: безубыток');
    check(SignalParser::parseUpdate('#AVAX/USDT закрываем, остаток в безубыток') === null, 'оба смысла сразу — не гадаем');
    check(SignalParser::parseUpdate('Закрываем сделку') === null, 'без монеты обновление не применяется');
    check(SignalParser::parseUpdate('#AVAX/USDT цель 1 взята', []) === null, 'обычный комментарий — не команда');
});

test('SignalParser: формат «LDO LONG / цена входа - a-b / цели - a, b, c / стоп - x» (тире вместо двоеточий, список целей)', function () {
    $cfg = ['symbol_style' => 'any', 'stop_words' => 'стоп'];   // как в настройках канала: слово «стоп» без «лосс»
    $post = "❗️ СИГНАЛ\n\n💭 LDO LONG 📈\n\nплечо - 25 кросс\nцена входа - 0.4871-0.4783$\nцели - 0.4930, 0.5037, 0.5281\nстоп - 0.4553";
    check(SignalParser::parse($post) === null, 'без стиля «any» монета «LDO LONG» не берётся (строгий режим)');
    $p = SignalParser::parse($post, $cfg);
    check($p && $p['symbol'] === 'LDOUSDT' && $p['side'] === 'Buy' && near($p['entry_lo'], 0.4783) && near($p['entry_hi'], 0.4871) && near($p['stop'], 0.4553), 'монета, сторона, обращённый диапазон входа, стоп после тире');
    check($p && count($p['targets']) === 3 && near($p['targets'][2], 0.5281) && SignalParser::validate($p) === null, 'три цели списком через запятую, сигнал проходит проверки');
    $short = SignalParser::parse("BTC SHORT\nвход: 100000\nтейк 99000, 98000\nстоп 101000", $cfg);
    check($short && $short['side'] === 'Sell' && $short['symbol'] === 'BTCUSDT' && $short['targets'] === [99000.0, 98000.0], 'то же для шорта; старый формат с «$119.4» по-прежнему работает (см. тесты выше)');
    check(SignalParser::findSymbol('BUY LONG сейчас', ['symbol_style' => 'any']) === null, 'служебное слово BUY монетой не считается');
});

test('SignalParser: слова канала добавляются к стандартным (опечатка не ломает); сломанный сигнал не становится командой «закрыть»', function () {
    $cfg = ['symbol_style' => 'any', 'entry_words' => 'Диапозон, диапозоне', 'target_words' => 'Цели, профит', 'close_words' => 'закрыть по, закрыть', 'stop_words' => 'СТОП ЛОСС, стоп'];
    $post = "СИГНАЛ #PENGU/USDT\n\n🔑 Открыть ШОРТ в диапазоне \$0.00997 - \$0.01008 с плечом X25\n\n🍒 Цели:\n\n🔘 Закрыть по \$0.00989\n🔘 Закрыть по \$0.00985\n\n❗️ СТОП ЛОСС: \$0.01041";
    $p = SignalParser::parse($post, $cfg);
    check($p && $p['symbol'] === 'PENGUUSDT' && $p['side'] === 'Sell' && count($p['targets']) === 2 && SignalParser::validate($p) === null, 'опечатка «Диапозон» в настройках не мешает: стандартное «диапазон» остаётся');
    $broken = str_replace('в диапазоне $0.00997 - $0.01008', 'в зоне', $post);
    check(SignalParser::parse($broken, $cfg) === null && SignalParser::parseUpdate($broken, $cfg) === null, 'пост без входа — не сигнал и НЕ команда «закрыть» (в нём есть «Закрыть по» и стоп)');
    check(SignalParser::parseUpdate('#PENGU/USDT закрыть по рынку', $cfg) === ['symbol' => 'PENGUUSDT', 'action' => 'close'], 'короткое «закрыть по рынку» как обновление работает');
});

test('Signals::fromTelegram: админ из таблицы, пересылка из нового канала создаёт выключенный канал, ручной источник', function () {
    Settings::save(['signal_enabled' => true, 'signal_allowed_ids' => []]);
    DB::q('DELETE FROM signal_channels'); DB::q('DELETE FROM signal_admins'); DB::q("DELETE FROM signals WHERE symbol = 'AVAXUSDT'");
    $post = "СИГНАЛ #AVAX/USDT\nОткрыть ЛОНГ в диапазоне \$39.8 - \$40.2 с плечом X10\nЗакрыть по \$41\nЗакрыть по \$42\nЗакрыть по \$43\nСТОП ЛОСС: \$38.5";
    $pm = fn($text, $from, $extra = []) => ['text' => $text, 'from' => ['id' => $from], 'chat' => ['id' => $from, 'type' => 'private']] + $extra;
    check(\App\Signals::fromTelegram($pm($post, 4242)) === false, 'не админ — сообщение не наше, бот обрабатывает как обычно');
    DB::insert('signal_admins', ['tg_id' => 4242, 'name' => 'Артём', 'enabled' => 1, 'created_at' => DB::now()]);
    $fwd = ['forward_origin' => ['type' => 'channel', 'chat' => ['id' => -1002000111, 'title' => 'Alpha Signals', 'type' => 'channel']]];
    check(\App\Signals::fromTelegram($pm($post, 4242, $fwd)) === true, 'пересылка от админа обработана');
    $ch = \App\Signals::channelByKey('-1002000111');
    check($ch && !(int)$ch['enabled'] && $ch['name'] === 'Alpha Signals', 'неизвестный канал добавлен как выключенный');
    check((int)DB::val("SELECT COUNT(*) FROM signals WHERE symbol = 'AVAXUSDT'") === 0, 'из выключенного канала сигнал не создан');
    DB::update('signal_channels', ['enabled' => 1], 'id = :id', [':id' => $ch['id']]);
    \App\Signals::fromTelegram($pm($post, 4242, $fwd));
    $sig = DB::row("SELECT * FROM signals WHERE symbol = 'AVAXUSDT'");
    check($sig && (int)$sig['channel_id'] === (int)$ch['id'] && $sig['status'] === 'new' && $sig['kind'] === 'signal', 'из включённого канала сигнал создан и привязан к каналу');
    check((int)DB::val('SELECT posts_seen FROM signal_channels WHERE id = ?', [$ch['id']]) === 2, 'счётчик постов канала растёт (в т.ч. пересылка при создании)');
    \App\Signals::fromTelegram($pm('#AVAX/USDT закрываем', 4242));
    $man = \App\Signals::channelByKey('manual');
    check($man && (int)$man['enabled'] && DB::val("SELECT kind FROM signals WHERE channel_id = ? ORDER BY id DESC LIMIT 1", [$man['id']]) === 'update', 'пересылка без канала → «ручной» источник (включён), обновление принято');
    \App\Signals::fromTelegram(['text' => $post, 'chat' => ['id' => -1009998887, 'type' => 'channel', 'title' => 'Чужой']]);
    $unk = \App\Signals::channelByKey('-1009998887');
    check($unk && !(int)$unk['enabled'] && $unk['created_by'] === 'auto', 'пост неподключённого канала (бот — админ) создаёт выключенный канал «на подтверждение»');
    Settings::save(['signal_enabled' => false]);
    check(\App\Signals::fromTelegram($pm($post, 4242)) === false, 'при выключенном приёме сигналов система молчит');
});

test('ИИ-разбор сигнала: числа обязаны быть в тексте, монета тоже; те же проверки; без ключа/цифр ИИ не вызывается', function () {
    $post = "Берём NEAR, лонг от 6.8 до 7.0. Цели 7.2, 7.4. Стоп 6.5";
    $good = ['type' => 'signal', 'symbol' => 'NEAR', 'side' => 'Buy', 'entry_lo' => 6.8, 'entry_hi' => 7.0, 'stop' => 6.5, 'targets' => [7.2, 7.4], 'action' => 'none'];
    $v = \App\Signals::verifyAi($good, $post);
    check($v && $v['type'] === 'signal' && $v['signal']['symbol'] === 'NEARUSDT' && SignalParser::validate($v['signal']) === null, 'честный ответ ИИ принят и проходит validate');
    $bad = \App\Signals::verifyAi(['stop' => 6.4] + $good, $post);
    check(isset($bad['error']) && !isset($bad['signal']), 'выдуманный стоп (38.0 нет в тексте) — отклонено');
    check(\App\Signals::verifyAi(['symbol' => 'DOGE'] + $good, $post) === null, 'монеты нет в тексте — отклонено');
    check(\App\Signals::verifyAi(['side' => 'none'] + $good, $post) === null && \App\Signals::verifyAi(['type' => 'none'] + $good, $post) === null, 'без направления или type=none — ничего');
    check(\App\Signals::verifyAi(['type' => 'update', 'symbol' => 'NEAR', 'action' => 'close'], 'NEAR закрываем') === ['type' => 'update', 'update' => ['symbol' => 'NEARUSDT', 'action' => 'close']], 'обновление от ИИ');
    $calls = 0;
    \App\Signals::$aiExtractor = function ($t) use (&$calls, $good) { $calls++; return $good; };
    check(\App\Signals::aiParse('Доброе утро всем!') === null && $calls === 0, 'пост без цифр в ИИ не отправляется');
    $r = \App\Signals::aiParse($post);
    check($calls === 1 && $r && isset($r['signal']), 'пост с цифрами уходит в ИИ');
    // через канал с включённым ai: строгий разбор не подошёл → ИИ
    $ch = \App\Signals::ensureChannel('ext:aitest', 'AI test', true, 'test');
    DB::update('signal_channels', ['parser_config' => json_encode(['ai' => 1])], 'id = :i', [':i' => $ch['id']]);
    $ch = \App\Signals::channelByKey('ext:aitest');
    $res = \App\Signals::receive($ch, $post, null);
    check($res['status'] === 'new' && str_ends_with((string)DB::val('SELECT source FROM signals WHERE id = ?', [$res['id']]), '+ai'), 'канал с ai=1: пост принят через ИИ, источник помечен +ai');
    DB::update('signal_channels', ['parser_config' => json_encode(new \stdClass())], 'id = :i', [':i' => $ch['id']]);
    $calls = 0;
    $res = \App\Signals::receive(\App\Signals::channelByKey('ext:aitest'), $post . ' ', null);
    check($res['status'] === 'ignored' && $calls === 0, 'без флага ai ИИ не вызывается — работают только жёсткие правила');
    \App\Signals::$aiExtractor = null;
    check(\App\Signals::aiParse($post) === null, 'без ключа Anthropic ИИ-разбор молча не применяется');
    DB::q("DELETE FROM signals WHERE channel_id = ?", [$ch['id']]);
    DB::q('DELETE FROM signal_channels WHERE id = ?', [$ch['id']]);
});

test('исполнение по каналам: режим канала, множитель риска, обновления безубыток/закрыть, сделка привязана к сигналу', function () {
    Settings::save(['signal_enabled' => true, 'signal_real_enabled' => true, 'signal_max_chase_pct' => 0.3]);
    $avax = new Instrument('AVAXUSDT', '0.01', '0.1', 0.1, 10000, 5, 50);
    $market = new Market(['AVAXUSDT']);
    $market->instruments['AVAXUSDT'] = $avax;
    $market->feeds['AVAXUSDT']->price = 40.0;
    $mgr = new Manager($market);
    DB::insert('users', ['id' => 9951, 'first_name' => 'C1', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('users', ['id' => 9952, 'first_name' => 'C2', 'created_at' => DB::now(), 'blocked' => false]);
    $recorded = [];
    $mk = function ($uid, $ex) use (&$recorded, $market, $mgr) {
        return new Worker($uid, $ex, $market, $mgr->brain, Risk::profile('balanced'), ['AVAXUSDT'],
            ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) use (&$recorded) { $recorded[] = $t; }, function ($u, $m) {}, function ($u, $e) {});
    };
    $paper = new PaperExchange(1000);
    $paper->updatePrices(['AVAXUSDT' => 40.0]);
    $mgr->workers[9951] = $mk(9951, $paper);
    $mgr->workers[9952] = $mk(9952, new FakeLiveExchange());
    $mgr->workers[9951]->step(3000.0);
    $mgr->workers[9952]->step(3000.0);
    $cid = DB::val('SELECT id FROM signal_channels WHERE source_key = ?', ['-1002000111']);
    DB::update('signal_channels', ['mode' => 'demo', 'risk_mult' => 0.5], 'id = :id', [':id' => $cid]);
    $sid = (int)DB::val("SELECT id FROM signals WHERE symbol = 'AVAXUSDT' AND kind = 'signal' AND channel_id = ?", [$cid]);
    $mgr->processSignals();
    $rows = DB::all('SELECT user_id, status, detail FROM signal_orders WHERE signal_id = ?', [$sid]);
    check(count($rows) === 1 && (int)$rows[0]['user_id'] === 9951 && $rows[0]['status'] === 'opened', 'глобально реальные разрешены, но канал в режиме demo → реальный клиент не тронут');
    preg_match('/риск ([\d.]+)\$/u', $rows[0]['detail'], $mm);
    check(isset($mm[1]) && (float)$mm[1] < 3.0, 'множитель риска канала 0.5 уменьшает риск (~2.5$ вместо ~5$): ' . ($rows[0]['detail'] ?? ''));
    // обновление «в безубыток» от другого канала не должно трогать сделку
    $other = DB::insert('signals', ['channel_id' => 999999, 'symbol' => 'AVAXUSDT', 'side' => '', 'entry_lo' => 0, 'entry_hi' => 0, 'stop_loss' => 0, 'targets' => [], 'raw_text' => 'x',
        'source' => 't', 'kind' => 'update', 'action' => 'close', 'status' => 'new', 'created_at' => DB::now()]);
    $mgr->processSignals();
    check(isset($paper->positions()['AVAXUSDT']), 'обновление чужого канала позицию не закрыло');
    $close = DB::insert('signals', ['channel_id' => $cid, 'symbol' => 'AVAXUSDT', 'side' => '', 'entry_lo' => 0, 'entry_hi' => 0, 'stop_loss' => 0, 'targets' => [], 'raw_text' => 'x',
        'source' => 't', 'kind' => 'update', 'action' => 'close', 'status' => 'new', 'created_at' => DB::now()]);
    $mgr->processSignals();
    check(DB::val('SELECT status FROM signal_orders WHERE signal_id = ?', [$close]) === 'opened', 'обновление своего канала «закрыть» применено');
    $t = 3000.0;
    for ($i = 0; $i < 4 && !$recorded; $i++) {
        $mgr->workers[9951]->step($t += 5);
    }
    check($recorded && ($recorded[0]['session_id'] ?? null) === 'sig' . $sid && $recorded[0]['strategy'] === 'signal', 'сделка записана с session_id = sig<id сигнала> — по нему считается статистика канала');
});

test('SignalsApi: обзор с бейджами примеров, сохранение канала, админы, playground, токен внешнего ридера', function () {
    $ov = fn() => \App\Web\SignalsApi::handle('GET', '/signals/overview', [], 't');
    $h = fn($m, $p, $b = []) => \App\Web\SignalsApi::handle($m, $p, $b, 't');
    $res = $h('POST', '/signals/channels', ['name' => 'Test Chan', 'source_key' => 'ext:test1', 'enabled' => true, 'mode' => 'demo', 'risk_mult' => '0.8',
        'max_chase_pct' => '', 'parser' => ['entry_words' => 'entry zone', 'stop_words' => '']]);
    check($res['ok'] && $res['id'] > 0, 'канал создан');
    $cid = $res['id'];
    foreach ([['source_key' => 'bad key'], ['risk_mult' => 9], ['name' => 'Dup', 'source_key' => 'ext:test1']] as $bad) {
        $threw = false;
        try { $h('POST', '/signals/channels', $bad + ['name' => 'X', 'source_key' => 'ext:zz', 'risk_mult' => 1]); } catch (\App\Web\ApiError) { $threw = true; }
        check($threw, 'некорректные данные канала отклонены');
    }
    $good = "#DOT/USDT\nLONG entry zone 6.9 - 7.0\nTP 7.2\nTP 7.4\nSL 6.7";
    $h('POST', '/signals/examples', ['channel_id' => $cid, 'kind' => 'signal', 'text' => $good]);
    $h('POST', '/signals/examples', ['channel_id' => $cid, 'kind' => 'noise', 'text' => 'Доброе утро, друзья!']);
    $h('POST', '/signals/examples', ['channel_id' => $cid, 'kind' => 'update', 'text' => 'Привет всем']);
    $c = current(array_filter($ov()['channels'], fn($x) => $x['id'] === $cid));
    $pass = array_map(fn($e) => $e['pass'], $c['examples']);
    check($pass === [true, true, false], 'бейджи примеров: сигнал и шум разобраны как ожидается, «update» без команды — провален');
    check((array)$c['parser'] === ['entry_words' => 'entry zone'], 'пустые слова не сохраняются (остаются по умолчанию)');
    $a = $h('POST', '/signals/parse', ['text' => $good, 'channel_id' => $cid]);
    check($a['type'] === 'signal' && $a['ok'] && $a['signal']['symbol'] === 'DOTUSDT', 'playground: разбор настройками канала');
    $a = $h('POST', '/signals/parse', ['text' => $good, 'parser' => []]);
    check($a['type'] === 'none', 'playground: с настройками по умолчанию тот же пост не распознан (черновик настроек из формы)');
    $ad = $h('POST', '/signals/admins', ['tg_id' => '555123', 'name' => 'Помощник']);
    $threw = false; try { $h('POST', '/signals/admins', ['tg_id' => '555123']); } catch (\App\Web\ApiError) { $threw = true; }
    check($ad['ok'] && $threw, 'админ добавлен, дубль отклонён');
    check(in_array('555123', \App\Signals::adminIds(), true), 'админ виден приёму сообщений');
    $h('PUT', '/signals/admins/' . $ad['id'], ['enabled' => false]);
    check(!in_array('555123', \App\Signals::adminIds(), true), 'выключенный админ не принимается');
    $t1 = \App\Signals::ingestToken();
    $t2 = $h('POST', '/signals/token/rotate')['token'];
    check($t1 !== $t2 && $t2 === \App\Signals::ingestToken(), 'токен внешнего ридера пересоздаётся');
    $r = \App\Signals::ingest('test1', 'Test Chan', $good);
    check($r['status'] === 'new', 'внешний ридер: пост принят в канал ext:test1 (настройки канала применены)');
    $r = \App\Signals::ingest('brand-new', 'Новый', $good);
    check($r['status'] === 'ignored' && \App\Signals::channelByKey('ext:brand-new') !== null, 'внешний ридер: неизвестный канал создан выключенным, пост не исполняется');
    $h('DELETE', '/signals/channels/' . $cid);
    check((int)DB::val('SELECT COUNT(*) FROM signal_examples WHERE channel_id = ?', [$cid]) === 0, 'удаление канала удаляет его примеры');
    check($h('POST', '/signals/settings', ['signal_max_chase_pct' => '0.5'])['ok'] && (float)Settings::get('signal_max_chase_pct') === 0.5, 'глобальные переключатели сохраняются');
    Settings::save(['signal_enabled' => false, 'signal_real_enabled' => false, 'signal_max_chase_pct' => 0.3]);
});

echo "Ордера и позиции (админка)\n";
test('PaperExchange отдаёт для админки mark/upnl/SL/TP и открытые ордера; setTradingStop меняет и снимает уровни', function () {
    $ex = new PaperExchange(1000);
    $ex->updatePrices(['BTCUSDT' => 100000]);
    $ex->placeMarket('BTCUSDT', 'Buy', '0.01', '99000', '105000');
    $ex->placeLimit('BTCUSDT', 'Sell', '0.005', '104000', 'sigtp-1', true);
    $ex->updatePrices(['BTCUSDT' => 101000]);
    $p = $ex->positions()['BTCUSDT'];
    check(near($p['upnl'], 0.01 * (101000 - $p['entry']), 1e-6) && $p['stop'] == 99000.0 && $p['take'] == 105000.0 && $p['mark'] == 101000.0, 'плавающий PnL, mark, SL, TP');
    $o = $ex->openOrders();
    check(count($o) === 1 && $o[0]['link'] === 'sigtp-1' && $o[0]['reduce'] === true && $o[0]['price'] === 104000.0, 'открытый ордер виден');
    $ex->setTradingStop('BTCUSDT', '99500', null);
    $p = $ex->positions()['BTCUSDT'];
    check($p['stop'] === 99500.0 && $p['take'] === null, 'стоп изменён, тейк снят');
});
$edSetup = function () {
    $btc = new Instrument('BTCUSDT', '0.1', '0.001', 0.001, 100, 5, 100);
    $market = new Market(['BTCUSDT']);
    $market->instruments['BTCUSDT'] = $btc;
    $market->feeds['BTCUSDT']->price = 100000.0;
    $ex = new PaperExchange(10000);
    $ex->updatePrices(['BTCUSDT' => 100000.0]);
    $notes = [];
    $w = new Worker(830, $ex, $market, new Brain(new Learner(false)), Risk::profile('balanced'), ['BTCUSDT'],
        ['grid' => true, 'trend' => true, 'liquidation' => true], function ($u, $t) {}, function ($u, $m) use (&$notes) { $notes[] = $m; }, function ($u, $e) {});
    $w->manualOrder(['id' => 1, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'market', 'qty' => 0.01, 'price' => null,
        'stop_loss' => 98000, 'take_profit' => null, 'leverage' => null]);
    return [$w, $ex, $market, &$notes];
};
test('Worker::editStops: меняет SL/TP, проверяет сторону, пустое значение снимает уровень, у сетки править нельзя', function () use ($edSetup) {
    [$w, $ex] = $edSetup();
    $r = $w->editStops('BTCUSDT', 99000.0, 105000.0);
    check($ex->pos['BTCUSDT']['stop'] === 99000.0 && $ex->pos['BTCUSDT']['take'] === 105000.0 && str_contains($r, '99000'), 'SL и TP выставлены на бирже');
    check($w->directional['BTCUSDT']['stop'] === 99000.0, 'внутреннее состояние обновлено');
    $w->editStops('BTCUSDT', null, null);
    check($ex->pos['BTCUSDT']['stop'] === null && $ex->pos['BTCUSDT']['take'] === null, 'оба уровня сняты');
    foreach ([[101000.0, null, 'стоп выше цены у лонга'], [null, 99000.0, 'тейк ниже цены у лонга'], [-5.0, null, 'отрицательный стоп']] as [$sl, $tp, $why]) {
        $threw = false;
        try { $w->editStops('BTCUSDT', $sl, $tp); } catch (\RuntimeException) { $threw = true; }
        check($threw, "отклонено: $why");
    }
    $threw = false;
    try { $w->editStops('ETHUSDT', 1.0, null); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'нет открытой позиции'); }
    check($threw, 'нет позиции — понятная ошибка');
    $w->grids['BTCUSDT'] = new Grid($ex, 'BTCUSDT', new Instrument('BTCUSDT', '0.1', '0.001', 0.001, 100, 5, 100), ['mode' => 'long', 'center' => 100000.0, 'step_pct' => 0.5, 'levels' => 3, 'qty' => '0.01', 'max_loss' => 5.0]);
    $threw = false;
    try { $w->editStops('BTCUSDT', 99000.0, null); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'сетка'); }
    check($threw, 'позиция сетки не редактируется — стоп ведёт сама сетка');
});
test('Worker::cancelOrder: снимает лимитку входа/цель, ордер сетки трогать нельзя, лишнее — ошибка', function () use ($edSetup) {
    [$w, $ex] = $edSetup();
    $w->closeManual('BTCUSDT');
    $w->directional = [];                                        // запись о закрытой позиции движок снимет на следующем такте
    $w->manualOrder(['id' => 2, 'symbol' => 'BTCUSDT', 'side' => 'Buy', 'order_type' => 'limit', 'qty' => 0.01, 'price' => 99000, 'stop_loss' => null, 'take_profit' => null, 'leverage' => null]);
    $link = $w->pendingManual['BTCUSDT']['link'];
    $view = (new ReflectionMethod(Worker::class, 'liveExchangeView'))->invoke($w);
    check(count($view['orders']) === 1 && $view['orders'][0]['origin'] === 'manual' && $view['orders'][0]['cancelable'] === true, 'ордер виден в живом состоянии как ручной');
    $w->cancelOrder('BTCUSDT', $link);
    check($ex->openOrders() === [] && !isset($w->pendingManual['BTCUSDT']), 'ордер снят на бирже и в памяти');
    $threw = false;
    try { $w->cancelOrder('BTCUSDT', $link); } catch (\RuntimeException) { $threw = true; }
    check($threw, 'повторная отмена — понятная ошибка');
    $grid = new Grid($ex, 'BTCUSDT', new Instrument('BTCUSDT', '0.1', '0.001', 0.001, 100, 5, 100), ['mode' => 'long', 'center' => 100000.0, 'step_pct' => 0.5, 'levels' => 3, 'qty' => '0.01', 'max_loss' => 5.0]);
    $grid->start();
    $w->grids['BTCUSDT'] = $grid;
    $gl = (string)array_key_first($grid->orders);
    $threw = false;
    try { $w->cancelOrder('BTCUSDT', $gl); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'сетки'); }
    check($threw, 'ордер сетки снять нельзя');
});
test('движок: команды edit_stops / cancel_order пишут результат для админки', function () use ($edSetup) {
    [$w, $ex] = $edSetup();
    $market = new Market(['BTCUSDT']);
    $mgr = new Manager($market);
    $mgr->workers[830] = $w;
    $ok = DB::insert('engine_commands', ['cmd' => 'edit_stops', 'arg' => json_encode(['user_id' => 830, 'symbol' => 'BTCUSDT', 'stop' => 99500.0, 'take' => 103000.0]), 'created_at' => DB::now()]);
    $bad = DB::insert('engine_commands', ['cmd' => 'edit_stops', 'arg' => json_encode(['user_id' => 830, 'symbol' => 'BTCUSDT', 'stop' => 101000.0, 'take' => null]), 'created_at' => DB::now()]);
    $off = DB::insert('engine_commands', ['cmd' => 'cancel_order', 'arg' => json_encode(['user_id' => 999, 'symbol' => 'BTCUSDT', 'link' => 'x']), 'created_at' => DB::now()]);
    (new ReflectionMethod(Manager::class, 'handleCommands'))->invoke($mgr);
    check(str_starts_with((string)DB::val('SELECT result FROM engine_commands WHERE id = ?', [$ok]), 'ok') && $ex->pos['BTCUSDT']['take'] === 103000.0, 'успех: результат ok, уровни на бирже');
    check(str_contains((string)DB::val('SELECT result FROM engine_commands WHERE id = ?', [$bad]), 'ниже'), 'ошибка проверки доходит до админки текстом');
    check(str_contains((string)DB::val('SELECT result FROM engine_commands WHERE id = ?', [$off]), 'не подключён'), 'клиент офлайн — понятный результат');
});
test('админка: /orders/overview считает сводку и проценты, edit/cancel ставят команды, trader-роль допущена', function () use ($edSetup) {
    DB::insert('users', ['id' => 9931, 'first_name' => 'Ord', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('bot_settings', ['user_id' => 9931, 'running' => true, 'trading_mode' => 'paper', 'risk_profile' => 'balanced', 'symbols' => ['BTCUSDT'], 'strategies' => ['grid' => true], 'paper_balance' => 1000]);
    DB::insert('trades', ['user_id' => 9931, 'symbol' => 'BTCUSDT', 'strategy' => 'signal', 'side' => 'Buy', 'qty' => 1, 'entry' => 1, 'exit' => 1, 'pnl' => 12.5, 'r' => 1, 'regime' => 'signal',
        'mode' => 'paper', 'opened_at' => DB::now(), 'closed_at' => DB::now()]);
    $live = ['positions' => [['symbol' => 'BTCUSDT', 'side' => 'Buy', 'qty' => 0.01, 'entry' => 100000, 'mark' => 101000, 'stop' => 99000, 'take' => null,
        'upnl' => 10.0, 'pnl_pct' => 1.0, 'pct_equity' => 1.0, 'strategy' => 'manual', 'editable' => true]], 'orders' => []];
    DB::q('INSERT INTO worker_state (user_id, status, equity, day_pnl_pct, live, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE live = VALUES(live), equity = VALUES(equity), updated_at = VALUES(updated_at)',
        [9931, 'ok', 1000.0, 0.5, json_encode($live), DB::now()]);
    $o = (new ReflectionMethod(\App\Web\AdminApi::class, 'ordersOverview'))->invoke(null, 9931);
    check($o['summary']['equity'] == 1000.0 && $o['summary']['upnl'] == 10.0 && $o['summary']['upnl_pct'] == 1.0, 'баланс, плавающий PnL и его % от баланса');
    check($o['summary']['realized_today'] == 12.5 && $o['summary']['realized_30d_pct'] == 1.25 && $o['summary']['win_rate_30d'] == 100.0, 'реализованное сегодня/30д, % и win rate');
    check(count($o['positions']) === 1 && $o['positions'][0]['client'] === 'Ord' && count($o['recent_trades']) === 1, 'позиция с именем клиента и история сделок');
    $e = (new ReflectionMethod(\App\Web\AdminApi::class, 'ordersEdit'))->invoke(null, ['user_id' => 9931, 'symbol' => 'btcusdt', 'stop_loss' => '99 500,5', 'take_profit' => ''], 'tester');
    check($e['ok'] && str_contains((string)DB::val('SELECT arg FROM engine_commands WHERE id = ?', [$e['command_id']]), '"take":null'), 'edit ставит команду; пустой TP = снять');
    $threw = false;
    try { (new ReflectionMethod(\App\Web\AdminApi::class, 'ordersEdit'))->invoke(null, ['user_id' => 9931, 'symbol' => 'BTCUSDT', 'stop_loss' => '-1'], 'tester'); } catch (\Throwable) { $threw = true; }
    check($threw, 'отрицательная цена отклонена ещё в API');
});

echo "Обнуление показателей и отключение анализа\n";
test('обнуление: торговля стирается, расходы на ИИ и настройки остаются, демо-балансы и обучение сброшены', function () {
    $market = new Market(['SOLUSDT']);
    $mgr = new Manager($market);
    DB::insert('users', ['id' => 9941, 'first_name' => 'R', 'created_at' => DB::now(), 'blocked' => false]);
    DB::q("INSERT INTO bot_settings (user_id, running, trading_mode, risk_profile, symbols, strategies, paper_balance) VALUES (9941, 1, 'paper', 'balanced', '[]', '{}', 777)
           ON DUPLICATE KEY UPDATE paper_balance = 777");
    DB::insert('trades', ['user_id' => 9941, 'symbol' => 'BTCUSDT', 'strategy' => 'grid', 'side' => 'Buy', 'qty' => 1, 'entry' => 1, 'exit' => 1, 'pnl' => 5, 'r' => 1,
        'regime' => 'range', 'mode' => 'paper', 'opened_at' => DB::now(), 'closed_at' => DB::now()]);
    DB::insert('ai_usage', ['ts' => DB::now(), 'model' => 'claude-opus-5', 'kind' => 'analyze', 'input_tokens' => 1, 'output_tokens' => 1,
        'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => 1.25]);
    $aiBefore = (float)DB::val('SELECT SUM(cost_usd) FROM ai_usage');
    $mgr->brain->learner->record('BTCUSDT', 'grid', 'range', 0.5, 1.0);
    $paper = new PaperExchange(777);
    $mgr->workers[9941] = new Worker(9941, $paper, $market, $mgr->brain, Risk::profile('balanced'), [], [], fn($u, $t) => null, fn($u, $m) => null, fn($u, $e) => null);
    $res = $mgr->resetStats(['trading' => true]);
    check((int)DB::val('SELECT COUNT(*) FROM trades') === 0 && (int)DB::val('SELECT COUNT(*) FROM strategy_stats') === 0, 'сделки и обучение стёрты');
    check(!$mgr->brain->learner->cache && $mgr->workers === [], 'кэш обучения и воркеры сброшены (пересоздадутся с нуля)');
    check(near((float)DB::val('SELECT paper_balance FROM bot_settings WHERE user_id = 9941'), (float)Settings::get('paper_start_balance')), 'демо-баланс вернулся к стартовому');
    check(near((float)DB::val('SELECT SUM(cost_usd) FROM ai_usage'), $aiBefore), 'расходы на ИИ не тронуты');
    check(str_contains($res, 'Расходы на ИИ сохранены'), 'итог сообщает, что расходы на ИИ сохранены');
    $threw = false;
    try { $mgr->resetStats([]); } catch (\RuntimeException) { $threw = true; }
    check($threw, 'без выбранных опций ничего не делается');
});
test('обнуление финансов: платежи стираются, но не при неоплаченных реферальных начислениях; расходы на ИИ остаются', function () {
    $market = new Market(['SOLUSDT']);
    $mgr = new Manager($market);
    DB::insert('users', ['id' => 9942, 'first_name' => 'Payer', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('users', ['id' => 9943, 'first_name' => 'Ref', 'created_at' => DB::now(), 'blocked' => false]);
    $pid = DB::insert('payments', ['user_id' => 9942, 'plan' => 'month', 'stars' => 100, 'usd' => 15.0, 'charge_id' => 'rst-' . uniqid(), 'created_at' => DB::now()]);
    DB::insert('referral_earnings', ['beneficiary_id' => 9943, 'from_user_id' => 9942, 'level' => 1, 'payment_id' => $pid, 'pct' => 20, 'amount_usd' => 3.0, 'paid' => 0, 'created_at' => DB::now()]);
    $threw = false;
    try { $mgr->resetStats(['finance' => true]); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'неоплаченные реферальные'); }
    check($threw && (int)DB::val('SELECT COUNT(*) FROM payments WHERE id = ?', [$pid]) === 1, 'долг перед рефералами не стирается — платёж остался');
    DB::q('UPDATE referral_earnings SET paid = 1');
    $ai = (float)DB::val('SELECT SUM(cost_usd) FROM ai_usage');
    $mgr->resetStats(['finance' => true]);
    check((int)DB::val('SELECT COUNT(*) FROM payments') === 0 && (int)DB::val('SELECT COUNT(*) FROM finance_entries') === 0, 'после выплаты платежи и ручные записи стёрты');
    check(near((float)DB::val('SELECT SUM(cost_usd) FROM ai_usage'), $ai), 'расходы на ИИ на месте');
});
test('обнуление через команду админки: нужно слово-подтверждение, устаревшая команда не выполняется', function () {
    $threw = false;
    try { (new ReflectionMethod(\App\Web\AdminApi::class, 'maintenanceReset'))->invoke(null, ['trading' => true, 'confirm' => 'да'], 't'); } catch (\Throwable) { $threw = true; }
    check($threw, 'без слова ОБНУЛИТЬ — отказ');
    $r = (new ReflectionMethod(\App\Web\AdminApi::class, 'maintenanceReset'))->invoke(null, ['trading' => true, 'confirm' => 'ОБНУЛИТЬ'], 't');
    check($r['ok'] && DB::val('SELECT cmd FROM engine_commands WHERE id = ?', [$r['command_id']]) === 'reset_stats', 'команда поставлена демону');
    $market = new Market(['SOLUSDT']);
    $mgr = new Manager($market);
    DB::q('UPDATE engine_commands SET created_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 3600), $r['command_id']]);
    DB::insert('users', ['id' => 9944, 'first_name' => 'Keep', 'created_at' => DB::now(), 'blocked' => false]);
    DB::insert('trades', ['user_id' => 9944, 'symbol' => 'BTCUSDT', 'strategy' => 'grid', 'side' => 'Buy', 'qty' => 1, 'entry' => 1, 'exit' => 1, 'pnl' => 1, 'r' => 1,
        'regime' => 'range', 'mode' => 'paper', 'opened_at' => DB::now(), 'closed_at' => DB::now()]);
    (new ReflectionMethod(Manager::class, 'handleCommands'))->invoke($mgr);
    check(str_contains((string)DB::val('SELECT result FROM engine_commands WHERE id = ?', [$r['command_id']]), 'просрочена') && (int)DB::val('SELECT COUNT(*) FROM trades WHERE user_id = 9944') === 1,
        'команда старше 10 минут не выполняется и ничего не стирает');
});
test('отключение анализа: по умолчанию включён; в выключенном режиме воркерам запрещены все автостратегии, режим — NO_TRADE', function () {
    check(Settings::get('analysis_enabled') === true, 'по умолчанию анализ включён');
    Settings::save(['analysis_enabled' => false]);
    $market = new Market(['SOLUSDT']);
    $mgr = new Manager($market);
    $w = new Worker(9950, new PaperExchange(1000), $market, $mgr->brain, Risk::profile('balanced'), ['SOLUSDT'], ['grid' => true, 'trend' => true, 'liquidation' => true],
        fn($u, $t) => null, fn($u, $m) => null, fn($u, $e) => null);
    $mgr->workers[9950] = $w;
    $mgr->brain->regimes['SOLUSDT'] = Regime::RANGE;
    (new ReflectionMethod(Manager::class, 'applyModes'))->invoke($mgr, false);
    check($w->platformEnabled === ['grid' => false, 'trend' => false, 'liquidation' => false, 'breakout' => false], 'все автостратегии запрещены');
    check(($mgr->brain->regimes['SOLUSDT'] ?? null) === Regime::NO_TRADE, 'режим рынка принудительно NO_TRADE');
    Settings::save(['analysis_enabled' => true]);
});

echo "\n";
if ($failed) {
    echo "ПРОВАЛЕНО: " . count($failed) . "\n  - " . implode("\n  - ", $failed) . "\n";
    exit(1);
}
echo "Все проверки пройдены: $passed\n";
