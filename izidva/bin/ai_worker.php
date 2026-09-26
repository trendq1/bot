<?php
/**
 * Процесс ИИ-аналитика. Запускается и перезапускается демоном автоматически.
 * Берёт признаки рынка из engine_status (их пишет демон), анализирует монеты через Claude
 * и сохраняет выводы в ai_insights. Раз в сутки — разбор сделок: уроки и подстройка параметров.
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\DB;
use App\Engine\AIAnalyst;
use App\Engine\Learner;
use App\Log;
use App\Settings;

if (PHP_SAPI !== 'cli' || !App\Env::configured()) {
    exit(1);
}
set_time_limit(0);

$ai = new AIAnalyst();
$learner = new Learner();
$lastLearnerLoad = 0;
$lastRun = [];

while (true) {
    try {
        DB::ping();
        $settings = Settings::all(true);
        if (time() - $lastLearnerLoad > 300) {
            $learner->cache = [];
            $learner->load();
            $lastLearnerLoad = time();
        }
        $force = false;
        foreach (DB::all("SELECT id FROM engine_commands WHERE done_at IS NULL AND cmd = 'force_ai'") as $c) {
            DB::update('engine_commands', ['done_at' => DB::now()], 'id = :id', [':id' => $c['id']]);
            $force = true;
        }
        if ($ai->enabled()) {
            $state = json_decode((string)DB::val('SELECT state FROM engine_status WHERE id = 1'), true) ?: [];
            $lessons = array_reverse(array_column(DB::all('SELECT text FROM lessons ORDER BY id DESC LIMIT 10'), 'text'));
            $interval = max(5, (int)$settings['ai_interval_min']) * 60;
            foreach ($state['symbols'] ?? [] as $sym => $s) {
                if (empty($s['features']) || (!$force && time() - ($lastRun[$sym] ?? 0) < $interval)) {
                    continue;
                }
                $lastRun[$sym] = time();
                $tuning = json_decode((string)DB::val('SELECT params FROM symbol_tuning WHERE symbol = ?', [$sym]), true) ?: [];
                $ins = $ai->analyze($sym, $s['features'], $learner->statsFor($sym), $lessons, $tuning,
                    ['longs_liquidated' => $s['liq_long_1h'] ?? 0, 'shorts_liquidated' => $s['liq_short_1h'] ?? 0], $s['funding'] ?? null);
                if ($ins['source'] === 'ai') {
                    DB::insert('ai_insights', ['symbol' => $sym, 'ts' => DB::now(), 'source' => 'ai', 'regime' => $ins['regime'], 'payload' => $ins]);
                }
            }
            dailyReview($ai);
        }
    } catch (Throwable $e) {
        Log::error('ИИ: ' . $e->getMessage());
        DB::reset();
        sleep(30);
    }
    sleep(20);
}

/** Раз в сутки (после 00:05 UTC): уроки и подстройка параметров по итогам сделок. */
function dailyReview(AIAnalyst $ai): void
{
    $today = gmdate('Y-m-d');
    if ((int)gmdate('Gi') < 5) {
        return;
    }
    $done = DB::val("SELECT value FROM app_settings WHERE `key` = '_ai_last_review'");
    if ($done !== null && json_decode((string)$done, true) === $today) {
        return;
    }
    DB::q("INSERT INTO app_settings (`key`, value, updated_at) VALUES ('_ai_last_review', ?, ?)
           ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", [json_encode($today), DB::now()]);
    $rows = DB::all("SELECT CONCAT(symbol, '|', strategy, '|', COALESCE(regime, 'range')) k, COUNT(*) trades,
            SUM(CASE WHEN pnl > 0 THEN 1 ELSE 0 END) wins, ROUND(SUM(pnl), 2) pnl, ROUND(SUM(r), 2) sum_r
        FROM trades WHERE closed_at >= ? GROUP BY k", [gmdate('Y-m-d H:i:s', time() - 86400)]);
    if (!$rows) {
        return;
    }
    $stats = [];
    foreach ($rows as $r) {
        $stats[$r['k']] = ['trades' => (int)$r['trades'], 'wins' => (int)$r['wins'], 'pnl' => (float)$r['pnl'], 'sum_r' => (float)$r['sum_r']];
    }
    $lessons = array_reverse(array_column(DB::all('SELECT text FROM lessons ORDER BY id DESC LIMIT 10'), 'text'));
    $result = $ai->reviewDay($stats, $lessons);
    if (!$result) {
        return;
    }
    foreach (array_slice($result['lessons'] ?? [], 0, 5) as $text) {
        DB::insert('lessons', ['ts' => DB::now(), 'text' => mb_substr((string)$text, 0, 500)]);
    }
    $symbols = Settings::get('symbols');
    foreach ($result['tuning'] ?? [] as $t) {
        if (!in_array($t['symbol'] ?? '', $symbols, true)) {
            continue;
        }
        $cur = json_decode((string)DB::val('SELECT params FROM symbol_tuning WHERE symbol = ?', [$t['symbol']]), true) ?: [];
        $new = AIAnalyst::applyTuning($cur, (string)$t['param'], (float)$t['value']);
        DB::q('INSERT INTO symbol_tuning (symbol, params, updated_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE params = VALUES(params), updated_at = VALUES(updated_at)', [$t['symbol'], json_encode($new), DB::now()]);
    }
    Log::info('ИИ: разбор дня выполнен, уроков: ' . count($result['lessons'] ?? []));
}
