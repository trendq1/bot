<?php
/**
 * Честный бэктест торговой логики по историческим 5м-свечам Bybit (или CSV-файлу).
 *
 * Прогоняет ТЕ ЖЕ классы, что и прод (Worker/Grid/Setups/Regime/Brain/PaperExchange), без упрощённых копий:
 *  - время такта — время свечи (Worker/PaperExchange не смешивают историю с текущими часами);
 *  - цена внутри свечи проходит путь open → (high/low в неблагоприятном порядке) → close, поэтому стопы и
 *    исполнения сетки внутри свечи не теряются;
 *  - часовые свечи для режима рынка и тренда собираются из 5м (последняя — незакрытая, как в API Bybit);
 *  - комиссии maker/taker, проскальзывание и гэпы — как в PaperExchange; funding — постоянная ставка --funding;
 *  - ИИ не вызывается: используется детерминированный AIAnalyst::ruleInsight — жёсткие ограничения (Regime,
 *    Risk Engine) в проде и так главнее весов ИИ.
 * Параметры НЕ оптимизируются ни на каком участке — поэтому каждый отрезок walk-forward для них out-of-sample.
 * Стратегия ликвидаций не тестируется: истории ликвидаций нет (--strategies по умолчанию grid,trend).
 *
 *   php bin/backtest.php SYMBOL [--days=90] [--profile=balanced] [--folds=4] [--mc=1000] [--funding=0.0001]
 *                               [--strategies=grid,trend] [--csv=path.csv] [--json=out.json]
 * CSV: ts_ms,open,high,low,close,volume[,turnover] — 5м свечи от старых к новым (заголовок допускается).
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Bybit;
use App\Engine\AIAnalyst;
use App\Engine\Brain;
use App\Engine\Instrument;
use App\Engine\Learner;
use App\Engine\Market;
use App\Engine\PaperExchange;
use App\Engine\Risk;
use App\Engine\TradeStats;
use App\Engine\Worker;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
App\Log::$muted = true;
ini_set('memory_limit', '2048M');                        // 2 года 5м-свечей — ~210 тыс. строк в памяти

const BT_START_BALANCE = 1000.0;
const BT_H1_BARS = 250;
const BT_M5_BARS = 300;

function btArg(array $argv, string $name, string $default): string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, "--$name=")) {
            return substr($a, strlen("--$name="));
        }
    }
    return $default;
}

/** @return list<array> свечи [ts, open, high, low, close, volume, turnover] от старых к новым */
function btFetchKlines(Bybit $api, string $symbol, int $days): array
{
    $endMs = (int)(microtime(true) * 1000);
    $startMs = $endMs - $days * 86400 * 1000;
    $candles = [];
    $cursor = $endMs;
    while ($cursor > $startMs) {
        // Публичный лимит Bybit и разовые сбои ("Too many visits", "Get kline failed") — повтор с паузой, а не падение
        for ($try = 1; ; $try++) {
            try {
                $r = $api->get('/v5/market/kline', ['category' => 'linear', 'symbol' => $symbol, 'interval' => '5', 'end' => $cursor, 'limit' => 1000]);
                break;
            } catch (\Throwable $e) {
                if ($try >= 6) {
                    throw $e;
                }
                fwrite(STDERR, "Bybit: {$e->getMessage()} — повтор через " . (2 ** $try) . " с\n");
                sleep(2 ** $try);
            }
        }
        $batch = $r['list'] ?? [];
        if (!$batch) {
            break;
        }
        foreach ($batch as $k) {
            $candles[(int)$k[0]] = $k;
        }
        $oldest = (int)end($batch)[0];
        if ($oldest >= $cursor) {
            break;
        }
        $cursor = $oldest;
        usleep(200_000);
    }
    ksort($candles);
    return array_values(array_filter($candles, fn($k) => (int)$k[0] >= $startMs));
}

function btLoadCsv(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $c = str_getcsv($line);
        if (count($c) < 6 || !is_numeric($c[0])) {
            continue;                                        // заголовок или мусор
        }
        $ts = (float)$c[0] < 1e11 ? (int)$c[0] * 1000 : (int)$c[0];   // секунды или миллисекунды
        $out[$ts] = [(string)$ts, $c[1], $c[2], $c[3], $c[4], $c[5], $c[6] ?? (string)((float)$c[5] * (float)$c[4])];
    }
    ksort($out);
    return array_values($out);
}

/**
 * Закрытые часовые свечи из 5м: [часовой ts => свеча]. Один раз на весь ряд, чтобы не пересобирать на каждой 5м-свече.
 * @return array{0:list<array>,1:array<int,int>} [часовые свечи по порядку, ts часа => индекс]
 */
function btHourlyIndex(array $candles): array
{
    $hours = [];
    foreach ($candles as $k) {
        $h = intdiv((int)$k[0], 3_600_000) * 3_600_000;
        if (!isset($hours[$h])) {
            $hours[$h] = [(string)$h, $k[1], $k[2], $k[3], $k[4], (float)$k[5], (float)$k[6]];
        } else {
            $hours[$h][2] = max((float)$hours[$h][2], (float)$k[2]);
            $hours[$h][3] = min((float)$hours[$h][3], (float)$k[3]);
            $hours[$h][4] = $k[4];
            $hours[$h][5] += (float)$k[5];
            $hours[$h][6] += (float)$k[6];
        }
    }
    return [array_values($hours), array_flip(array_keys($hours))];
}

/** Прогон одного отрезка с нуля (свежий счёт, свежий Worker, без обучения на прошлом). */
function btRun(array $candles, int $from, int $to, string $symbol, Instrument $inst, string $profile, array $enabled, float $funding): array
{
    $market = new Market([$symbol]);
    $market->instruments[$symbol] = $inst;
    $feed = $market->feeds[$symbol];
    $feed->funding = $funding;
    $brain = new Brain(new Learner(false));
    $ex = new PaperExchange(BT_START_BALANCE);
    $trades = [];
    $w = new Worker(1, $ex, $market, $brain, Risk::profile($profile), [$symbol], $enabled,
        function ($u, $t) use (&$trades, &$nowTs) { $trades[] = $t + ['closed_at' => gmdate('Y-m-d H:i:s', (int)$nowTs)]; },
        function ($u, $m) {}, function ($u, $e) {});
    [$hourly, $hourIdx] = btHourlyIndex($candles);
    $regimeBars = [];
    $nowTs = 0.0;
    $partial = null;
    for ($i = $from; $i < $to; $i++) {
        $k = $candles[$i];
        $t = (int)$k[0] / 1000;
        // текущая незакрытая часовая свеча — только из 5м-свечей этого часа до текущей включительно (без заглядывания вперёд)
        $hTs = intdiv((int)$k[0], 3_600_000) * 3_600_000;
        if ($partial === null || (int)$partial[0] !== $hTs) {
            $partial = [(string)$hTs, $k[1], $k[2], $k[3], $k[4], (float)$k[5], (float)$k[6]];
        } else {
            $partial[2] = max((float)$partial[2], (float)$k[2]);
            $partial[3] = min((float)$partial[3], (float)$k[3]);
            $partial[4] = $k[4];
            $partial[5] += (float)$k[5];
            $partial[6] += (float)$k[6];
        }
        $hi = $hourIdx[$hTs];
        [$o, $h, $l, $c] = [(float)$k[1], (float)$k[2], (float)$k[3], (float)$k[4]];
        // внутри свечи: сначала к экстремуму против открытой позиции/сетки нельзя знать заранее — берём порядок
        // по цвету свечи (зелёная: open→low→high→close, красная: open→high→low→close), стоп проверяется на каждой точке
        $path = $c >= $o ? [$o, $l, $h] : [$o, $h, $l];
        $ex->now = $t;
        foreach ($path as $p) {
            $feed->price = $p;
            $ex->updatePrices([$symbol => $p]);
        }
        $feed->price = $c;
        $feed->klines = array_slice($candles, max(0, $i - BT_M5_BARS + 1), BT_M5_BARS);
        $feed->klines1h = array_merge(array_slice($hourly, max(0, $hi - (BT_H1_BARS - 1)), min($hi, BT_H1_BARS - 1)), [$partial]);
        $brain->refresh($symbol, $feed);
        if (isset($brain->features[$symbol])) {
            $brain->insights[$symbol] = AIAnalyst::ruleInsight($brain->features[$symbol], []);
        }
        $nowTs = $t + 299;
        $w->step($nowTs);
        $regimeBars[$brain->regimes[$symbol] ?? 'no_trade'] = ($regimeBars[$brain->regimes[$symbol] ?? 'no_trade'] ?? 0) + 1;
    }
    return ['trades' => $trades, 'equity' => $ex->equity(), 'regime_bars' => $regimeBars];
}

function btPrint(string $label, array $run): array
{
    $s = TradeStats::compute($run['trades']);
    $pf = $s['profit_factor'] === null ? '—' : (string)$s['profit_factor'];
    printf("\n== %s ==\n", $label);
    printf("Сделок %d | Net %.2f$ | PF %s | Expectancy %.4f$ (%sR) | WinRate %.1f%%\n", $s['trades'], $s['net_pnl'], $pf,
        $s['expectancy_usd'], $s['expectancy_r'] ?? '—', $s['win_rate_pct']);
    printf("Ср.плюс %.4f$ / ср.минус %.4f$ (x%s) | крупнейший минус %.2f$ | макс. просадка %.2f$ | Recovery %s\n",
        $s['avg_win'], $s['avg_loss'], $s['win_loss_ratio'] ?? '—', $s['largest_loss'], $s['max_drawdown'], $s['recovery_factor'] ?? '—');
    printf("Хвост: 1%% крупнейших убытков = %.1f%% всех убытков, 5%% = %.1f%%, 10%% = %.1f%%\n",
        $s['tail_loss_share_pct']['top1'], $s['tail_loss_share_pct']['top5'], $s['tail_loss_share_pct']['top10']);
    foreach ($s['by_strategy'] as $k => $v) {
        printf("  стратегия %-12s %4d сделок, net %8.2f$, PF %s\n", $k, $v['trades'], $v['net_pnl'], $v['profit_factor'] ?? '—');
    }
    foreach ($s['by_regime'] as $k => $v) {
        printf("  режим     %-12s %4d сделок, net %8.2f$, PF %s\n", $k, $v['trades'], $v['net_pnl'], $v['profit_factor'] ?? '—');
    }
    $g = $s['grid_sessions'];
    if ($g['sessions'] || $g['cycles']) {
        printf("  сетка: %d сессий, net %.2f$, PF сессий %s, худшая сессия %.2f$, 1 стоп = %s циклов прибыли\n",
            $g['sessions'], $g['net_pnl'], $g['profit_factor'] ?? '—', $g['worst_session'], $g['cycles_per_stop'] ?? '—');
    }
    $bars = array_sum($run['regime_bars']) ?: 1;
    $share = array_map(fn($v) => round($v / $bars * 100) . '%', $run['regime_bars']);
    echo '  время в режимах: ' . implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($share), $share)) . "\n";
    return $s;
}

$symbol = strtoupper($argv[1] ?? '');
if ($symbol === '' || str_starts_with($symbol, '--')) {
    fwrite(STDERR, "Использование: php bin/backtest.php SYMBOL [--days=90] [--profile=balanced] [--folds=4] [--mc=1000] "
        . "[--funding=0.0001] [--strategies=grid,trend] [--csv=path.csv] [--json=out.json]\n");
    exit(1);
}
$days = max(15, (int)btArg($argv, 'days', '90'));
$profile = btArg($argv, 'profile', 'balanced');
$folds = max(1, min(12, (int)btArg($argv, 'folds', '4')));
$mcRuns = max(0, (int)btArg($argv, 'mc', '1000'));
$funding = (float)btArg($argv, 'funding', '0.0001');
$strategies = array_filter(explode(',', btArg($argv, 'strategies', 'grid,trend')));
$enabled = ['grid' => in_array('grid', $strategies, true), 'trend' => in_array('trend', $strategies, true),
    'liquidation' => in_array('liquidation', $strategies, true), 'breakout' => in_array('breakout', $strategies, true)];
$csv = btArg($argv, 'csv', '');

$candles = $csv !== '' ? btLoadCsv($csv) : btFetchKlines(new Bybit(), $symbol, $days);
$n = count($candles);
$warm = (BT_H1_BARS + 2) * 12;
if ($n < $warm + 500) {
    fwrite(STDERR, "Мало свечей ($n): нужно минимум " . ($warm + 500) . " (≈" . ceil(($warm + 500) / 288) . " дней) — прогрев 250 часовых свечей\n");
    exit(1);
}
echo "=== $symbol ===\nСвечей 5м: $n (" . gmdate('Y-m-d', (int)($candles[0][0] / 1000)) . ' → ' . gmdate('Y-m-d', (int)($candles[$n - 1][0] / 1000)) . " UTC), "
    . "прогрев первых $warm свечей\n";

if ($csv !== '') {
    $inst = new Instrument($symbol, '0.0001', '0.001', 0.001, 1e9, 5, 50);
    echo "Спецификация инструмента: общая (CSV-режим, без запроса к Bybit)\n";
} else {
    $market = new Market([$symbol]);
    $market->loadInstruments();
    $inst = $market->instruments[$symbol] ?? null;
    if (!$inst) {
        fwrite(STDERR, "Не удалось загрузить спецификацию $symbol\n");
        exit(1);
    }
}
echo "Профиль: $profile | стратегии: " . implode(',', $strategies) . " | капитал " . BT_START_BALANCE . "$ | funding $funding за 8ч\n";
echo "Параметры фиксированы — подгонки под историю нет, каждый отрезок ниже для них out-of-sample.\n";

$report = ['symbol' => $symbol, 'profile' => $profile, 'strategies' => $strategies, 'folds' => []];
$all = btRun($candles, $warm, $n, $symbol, $inst, $profile, $enabled, $funding);
$report['full'] = btPrint('ВЕСЬ ПЕРИОД', $all);

// Walk-forward: независимые последовательные отрезки, каждый со свежим счётом — проверка устойчивости во времени
$len = intdiv($n - $warm, $folds);
$pfs = [];
for ($k = 0; $k < $folds; $k++) {
    $from = $warm + $k * $len;
    $to = $k === $folds - 1 ? $n : $from + $len;
    $label = sprintf('ОТРЕЗОК %d/%d (%s → %s)', $k + 1, $folds, gmdate('Y-m-d', (int)($candles[$from][0] / 1000)), gmdate('Y-m-d', (int)($candles[$to - 1][0] / 1000)));
    $s = btPrint($label, btRun($candles, $from, $to, $symbol, $inst, $profile, $enabled, $funding));
    $report['folds'][] = $s;
    $pfs[] = $s;
}

if ($mcRuns > 0) {
    $mc = TradeStats::monteCarlo(array_map(fn($t) => (float)$t['pnl'], $all['trades']), $mcRuns);
    $report['monte_carlo'] = $mc;
    echo "\n== MONTE CARLO ($mcRuns перестановок последовательности сделок) ==\n";
    echo $mc === null ? "Сделок меньше 10 — статистически бессмысленно\n"
        : sprintf("Net: 5%%-перцентиль %.2f$, медиана %.2f$, 95%% %.2f$ | просадка: медиана %.2f$, худшие 5%% %.2f$ | вероятность минуса %.1f%%\n",
            $mc['net_p5'], $mc['net_p50'], $mc['net_p95'], $mc['max_dd_p50'], $mc['max_dd_p5'], $mc['prob_loss_pct']);
}

echo "\n== ВЕРДИКТ ==\n";
$full = $report['full'];
$positiveFolds = count(array_filter($pfs, fn($s) => $s['trades'] > 0 && $s['net_pnl'] > 0));
$tradedFolds = count(array_filter($pfs, fn($s) => $s['trades'] > 0));
if ($full['trades'] < 30) {
    echo "Сделок меньше 30 — выборка слишком мала для выводов. Это не «стратегия прибыльна», это «данных недостаточно».\n";
} elseif (($full['profit_factor'] ?? 0) < 1.0) {
    echo "✗ Profit Factor < 1 — ожидание отрицательное. Не включать на реальном счёте.\n";
} elseif ($positiveFolds < max(1, (int)ceil($tradedFolds * 0.75))) {
    echo "⚠ В сумме плюс, но только $positiveFolds из $tradedFolds отрезков прибыльны — результат нестабилен во времени.\n";
} else {
    echo "✓ PF ≥ 1 и $positiveFolds из $tradedFolds отрезков в плюсе. Это НЕ гарантия прибыли: следующий шаг — demo/paper минимум 2–4 недели.\n";
}
// Критерий принятия, заданный ДО прогона (не подбирается по результату): PF ≥ 1.2 минимум в 2/3 отрезков,
// где были сделки, и минимум 30 сделок за весь период. Для 6 отрезков — 4 из 6.
$goodFolds = count(array_filter($pfs, fn($s) => $s['trades'] > 0 && ($s['profit_factor'] === null ? $s['net_pnl'] > 0 : $s['profit_factor'] >= 1.2)));
$need = max(1, (int)ceil($folds * 2 / 3));
$accepted = $full['trades'] >= 30 && ($full['profit_factor'] ?? 0) >= 1.2 && $goodFolds >= $need;
printf("Критерий принятия (PF ≥ 1.2 за весь период и минимум в %d из %d отрезков, ≥ 30 сделок): %s — отрезков с PF ≥ 1.2: %d\n",
    $need, $folds, $accepted ? 'ПРОЙДЕН' : 'НЕ ПРОЙДЕН', $goodFolds);
$report['accepted'] = $accepted;
$json = btArg($argv, 'json', '');
if ($json !== '') {
    file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Отчёт сохранён: $json\n";
}
