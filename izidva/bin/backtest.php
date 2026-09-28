<?php
/**
 * Backtest / walk-forward проверка торговой логики по историческим свечам Bybit.
 *
 * Прогоняет ТЕ ЖЕ классы, что и прод (Grid/Worker/Indicators/PaperExchange), — никакой отдельной
 * "упрощённой" копии стратегии, поэтому результат реально отражает то, что делает бот сейчас.
 *
 * ИИ (Claude) тут не вызывается — используется детерминированный алгоритмический запасной вариант
 * (AIAnalyst::ruleInsight), одинаковый на всей истории: так walk-forward не подглядывает в будущее,
 * а out-of-sample период честно проверяет, не переобучены ли параметры под конкретный кусок истории.
 * Не пишет ничего в БД (Learner здесь persist=false) — можно гонять сколько угодно раз без последствий.
 *
 * Использование:
 *   php bin/backtest.php SYMBOL [--days=30] [--profile=balanced] [--split=0.7] [--interval=5]
 *
 * Пример:
 *   php bin/backtest.php SOLUSDT --days=60 --profile=balanced --split=0.7
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Bybit;
use App\Engine\AIAnalyst;
use App\Engine\Brain;
use App\Engine\Indicators;
use App\Engine\Instrument;
use App\Engine\Learner;
use App\Engine\Market;
use App\Engine\PaperExchange;
use App\Engine\Risk;
use App\Engine\Worker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function btArg(array $argv, string $name, string $default): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen("--$name="));
        }
    }
    return $default;
}

/** Ключ по ts — так объединение соседних страниц истории не даёт дублей. @return array<int,array> */
function btFetchKlines(Bybit $api, string $symbol, string $interval, int $days): array
{
    $endMs = (int)(microtime(true) * 1000);
    $startMs = $endMs - $days * 86400 * 1000;
    $candles = [];
    $cursor = $endMs;
    while ($cursor > $startMs) {
        $r = $api->get('/v5/market/kline', ['category' => 'linear', 'symbol' => $symbol, 'interval' => $interval,
            'end' => $cursor, 'limit' => 1000]);
        $batch = $r['list'] ?? [];
        if (!$batch) {
            break;
        }
        foreach ($batch as $k) {
            $candles[(int)$k[0]] = $k;
        }
        $oldest = (int)end($batch)[0];
        if ($oldest >= $cursor) {
            break;                                          // защита от зацикливания
        }
        $cursor = $oldest;
        usleep(200_000);                                    // публичный rate-limit Bybit
    }
    ksort($candles);
    return array_values(array_filter($candles, fn($k) => (int)$k[0] >= $startMs));
}

/** @return array{n:int,sum_pnl:float,pf:?float,expectancy:float,largest_loss:float,max_dd:float,winrate:float} */
function btReport(array $trades, string $label): array
{
    $n = count($trades);
    $grossWin = 0.0;
    $grossLoss = 0.0;
    $sumPnl = 0.0;
    $largest = 0.0;
    $wins = 0;
    $peak = 0.0;
    $cum = 0.0;
    $maxDd = 0.0;
    foreach ($trades as $t) {
        $p = (float)$t['pnl'];
        $sumPnl += $p;
        $wins += $p > 0 ? 1 : 0;
        if ($p > 0) {
            $grossWin += $p;
        } else {
            $grossLoss += -$p;
        }
        $largest = min($largest, $p);
        $cum += $p;
        $peak = max($peak, $cum);
        $maxDd = min($maxDd, $cum - $peak);
    }
    $pf = $grossLoss > 0 ? $grossWin / $grossLoss : null;
    $winrate = $n ? round($wins / $n * 100, 1) : 0.0;
    printf("\n== %s ==\n", $label);
    printf("Сделок: %d, winrate: %s%%\n", $n, $winrate);
    printf("Net PnL: %.2f\$, Expectancy: %.4f\$/сделку\n", $sumPnl, $n ? $sumPnl / $n : 0.0);
    printf("Profit Factor: %s\n", $pf === null ? '—' : (string)round($pf, 2));
    printf("Крупнейший убыток за сделку: %.2f\$, просадка по реал. PnL: %.2f\$\n", $largest, $maxDd);
    return ['n' => $n, 'sum_pnl' => $sumPnl, 'pf' => $pf, 'expectancy' => $n ? $sumPnl / $n : 0.0,
        'largest_loss' => $largest, 'max_dd' => $maxDd, 'winrate' => $winrate];
}

/** @return array{n:int,sum_pnl:float,pf:?float,expectancy:float,largest_loss:float,max_dd:float,winrate:float} */
function btRunPeriod(array $candles, int $from, int $to, string $symbol, Instrument $inst, string $profileCode, string $label): array
{
    $market = new Market([$symbol]);
    $market->instruments[$symbol] = $inst;
    $brain = new Brain(new Learner(false));                // persist=false — прогон не должен попадать в прод-статистику
    $ex = new PaperExchange(1000.0);
    $trades = [];
    $w = new Worker(1, $ex, $market, $brain, Risk::profile($profileCode), [$symbol],
        ['grid' => true, 'trend' => true, 'liquidation' => true],
        function ($u, $t) use (&$trades) { $trades[] = $t; }, function ($u, $m) {}, function ($u, $e) {});
    for ($i = $from; $i < $to; $i++) {
        $window = array_slice($candles, max(0, $i - 299), 300);
        $f = Indicators::features($window, (float)$candles[$i][4]);
        if ($f === null) {
            continue;
        }
        $price = (float)$candles[$i][4];
        $market->feeds[$symbol]->price = $price;
        $ex->updatePrices([$symbol => $price]);
        $brain->features[$symbol] = $f;
        $brain->insights[$symbol] = AIAnalyst::ruleInsight($f, []);
        $w->step((int)$candles[$i][0] / 1000);
    }
    return btReport($trades, $label);
}

$symbol = strtoupper($argv[1] ?? '');
if ($symbol === '') {
    fwrite(STDERR, "Использование: php bin/backtest.php SYMBOL [--days=30] [--profile=balanced] [--split=0.7] [--interval=5]\n");
    exit(1);
}
$days = max(1, (int)btArg($argv, 'days', '30'));
$profileCode = btArg($argv, 'profile', 'balanced');
$split = max(0.1, min(0.9, (float)btArg($argv, 'split', '0.7')));
$interval = btArg($argv, 'interval', '5');

echo "Загрузка исторических свечей $symbol ($interval мин, $days дн.)...\n";
$candles = btFetchKlines(new Bybit(), $symbol, $interval, $days);
$n = count($candles);
if ($n < 320) {
    fwrite(STDERR, "Недостаточно свечей ($n) — увеличьте --days или проверьте символ\n");
    exit(1);
}
echo "Свечей: $n (" . gmdate('Y-m-d H:i', (int)($candles[0][0] / 1000)) . " -> "
    . gmdate('Y-m-d H:i', (int)($candles[$n - 1][0] / 1000)) . " UTC)\n";

$market = new Market([$symbol]);
$market->loadInstruments();
if (!isset($market->instruments[$symbol])) {
    fwrite(STDERR, "Не удалось загрузить спецификацию инструмента $symbol\n");
    exit(1);
}
$inst = $market->instruments[$symbol];
$splitIdx = 300 + (int)(($n - 300) * $split);

echo "\nПрофиль риска: $profileCode | капитал: 1000\$ (paper) | сплит in/out-of-sample: "
    . round($split * 100) . "/" . round((1 - $split) * 100) . "%\n";

$in = btRunPeriod($candles, 300, $splitIdx, $symbol, $inst, $profileCode,
    'IN-SAMPLE (' . gmdate('Y-m-d', (int)($candles[300][0] / 1000)) . ' -> ' . gmdate('Y-m-d', (int)($candles[$splitIdx - 1][0] / 1000)) . ')');
$out = btRunPeriod($candles, $splitIdx, $n, $symbol, $inst, $profileCode,
    'OUT-OF-SAMPLE (' . gmdate('Y-m-d', (int)($candles[$splitIdx][0] / 1000)) . ' -> ' . gmdate('Y-m-d', (int)($candles[$n - 1][0] / 1000)) . ')');

echo "\n---\n";
if (($in['pf'] ?? 0) > 1 && ($out['pf'] ?? 0) < 1) {
    echo "⚠ In-sample прибыльно, out-of-sample — нет: возможен overfit под конкретный период, параметры требуют осторожности.\n";
} elseif (($out['pf'] ?? 0) >= 1) {
    echo "✓ Profit Factor ≥ 1 и на out-of-sample периоде тоже.\n";
} else {
    echo "✗ Profit Factor < 1 на обоих периодах — математика отрицательна, дело не в переобучении параметров.\n";
}
