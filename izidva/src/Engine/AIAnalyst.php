<?php
declare(strict_types=1);

namespace App\Engine;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\DB;
use App\Log;
use App\Settings;

/**
 * ИИ-аналитик на Claude (официальный PHP SDK anthropic-ai/sdk).
 * 1) По каждой монете: режим рынка, веса стратегий, направление и шаг сетки, множитель риска.
 * 2) Раз в сутки: разбор сделок -> уроки и подстройка параметров в жёстких границах.
 * ИИ не отправляет ордера — лимиты риска проверяет код. Без ключа работает запасной алгоритм.
 */
final class AIAnalyst
{
    /** параметр => [мин, макс, по умолчанию] */
    public const TUNABLE = [
        'grid_step_atr' => [0.3, 1.5, 0.6],
        'trend_rr' => [0.8, 2.0, 1.2],
        'liq_threshold_mult' => [0.5, 3.0, 1.0],
    ];

    /** $ за 1 млн токенов (вход, выход) — для учёта расходов */
    public const PRICES = [
        'claude-fable-5-1' => [10.0, 50.0], 'claude-fable-5' => [10.0, 50.0],
        'claude-opus-5-5' => [4.0, 20.0], 'claude-opus-5' => [5.0, 25.0],
        'claude-sonnet-5' => [2.0, 10.0], 'claude-haiku-4-5' => [1.0, 5.0],
    ];

    private const SYSTEM = <<<TXT
Ты — риск-ориентированный аналитик крипторынка для автоматического бота на фьючерсах Bybit (USDT Perpetual).
Бот сам исполняет сделки и сам соблюдает лимиты риска. Твоя задача — для одной монеты выбрать режим рынка и распределить приоритет между стратегиями.

Стратегии бота:
- grid — сетка лимитных ордеров: много мелких прибыльных сделок в боковике. В одностороннем режиме: long (покупки ниже цены, продажи на шаг выше) или short (зеркально). Опасна при сильном тренде против направления сетки. grid_mode="off" выключает сетку.
- trend — вход по тренду после отката к EMA20 с коротким тейком (~1.2R). Хороша при ADX>23 и выстроенных EMA.
- liquidation — вход на отскок после каскада ликвидаций при сильном отклонении от VWAP. Хороша при высокой волатильности.

Режимы: trend_up, trend_down, range, high_volatility.

Правила:
- Веса w_grid, w_trend, w_liquidation — числа от 0 до 1 (приоритет стратегии, не доли).
- grid_step_atr — шаг сетки в долях ATR(5м), от 0.3 до 1.5. Шире при высокой волатильности.
- risk_mult — от 0.3 до 1.2. Снижай при неопределённости, новостной волатильности, экстремальном funding.
- confidence — от 0 до 1.
- Учитывай статистику обучения (как стратегии уже отработали на этой монете) и уроки прошлых дней.
- Не выдумывай данные, которых нет во входе. При сомнениях выбирай более осторожный вариант.
- summary — 1–2 предложения по-русски для клиента: что происходит на рынке и что делает бот.
TXT;

    private const REVIEW_SYSTEM = <<<TXT
Ты разбираешь итоги торгового дня автоматического бота на фьючерсах Bybit.
На входе — агрегированная статистика закрытых сделок по монетам, стратегиям и режимам рынка, а также текущие уроки.
Сформулируй до 5 коротких практичных уроков по-русски (что работало, что нет, чего избегать) — они будут подсказками для аналитика в следующие дни.
Предложи подстройку параметров только при достаточной статистике (от 10 сделок на связку). Допустимые параметры и границы:
grid_step_atr 0.3–1.5, trend_rr 0.8–2.0, liq_threshold_mult 0.5–3.0. Меняй плавно, не более чем на 20% за день.
Если данных мало — верни пустой список tuning.
TXT;

    private ?Client $client = null;
    private string $clientKey = '';

    public static function insightSchema(): array
    {
        $num = ['type' => 'number'];
        return [
            'type' => 'object',
            'properties' => [
                'regime' => ['type' => 'string', 'enum' => Indicators::REGIMES],
                'confidence' => $num, 'w_grid' => $num, 'w_trend' => $num, 'w_liquidation' => $num,
                'grid_mode' => ['type' => 'string', 'enum' => ['long', 'short', 'off']],
                'grid_step_atr' => $num, 'risk_mult' => $num,
                'summary' => ['type' => 'string'],
            ],
            'required' => ['regime', 'confidence', 'w_grid', 'w_trend', 'w_liquidation', 'grid_mode', 'grid_step_atr', 'risk_mult', 'summary'],
            'additionalProperties' => false,
        ];
    }

    public static function reviewSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lessons' => ['type' => 'array', 'items' => ['type' => 'string']],
                'tuning' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'symbol' => ['type' => 'string'],
                        'param' => ['type' => 'string', 'enum' => array_keys(self::TUNABLE)],
                        'value' => ['type' => 'number'],
                    ],
                    'required' => ['symbol', 'param', 'value'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['lessons', 'tuning'],
            'additionalProperties' => false,
        ];
    }

    /** Приведение ответа ИИ к допустимым границам. */
    public static function clamp(array $d, string $source): array
    {
        $c = fn($v, $lo, $hi, $def) => $v === null || !is_numeric($v) ? $def : max($lo, min($hi, (float)$v));
        [$slo, $shi, $sdef] = self::TUNABLE['grid_step_atr'];
        return [
            'regime' => in_array($d['regime'] ?? '', Indicators::REGIMES, true) ? $d['regime'] : 'range',
            'confidence' => $c($d['confidence'] ?? null, 0, 1, 0.5),
            'w_grid' => $c($d['w_grid'] ?? null, 0, 1, 0.5),
            'w_trend' => $c($d['w_trend'] ?? null, 0, 1, 0.5),
            'w_liquidation' => $c($d['w_liquidation'] ?? null, 0, 1, 0.5),
            'grid_mode' => in_array($d['grid_mode'] ?? '', ['long', 'short', 'off'], true) ? $d['grid_mode'] : 'off',
            'grid_step_atr' => $c($d['grid_step_atr'] ?? null, $slo, $shi, $sdef),
            'risk_mult' => $c($d['risk_mult'] ?? null, 0.3, 1.2, 1.0),
            'summary' => mb_substr((string)($d['summary'] ?? ''), 0, 300),
            'source' => $source,
        ];
    }

    /** Запасной вариант без ИИ. */
    public static function ruleInsight(array $f, array $tuning = []): array
    {
        $regime = Indicators::ruleRegime($f);
        $step = $tuning['grid_step_atr'] ?? self::TUNABLE['grid_step_atr'][2];
        $below = $f['price'] < $f['vwap_4h'];
        $t = [
            'trend_up' => [0.4, 0.9, 0.5, 'long', 1.0, 'Восходящий тренд: сетка в лонг, входы на откатах.'],
            'trend_down' => [0.4, 0.9, 0.5, 'short', 1.0, 'Нисходящий тренд: сетка в шорт, входы на откатах.'],
            'range' => [1.0, 0.2, 0.7, $below ? 'long' : 'short', 1.0, 'Боковик: основной заработок — сетка.'],
            'high_volatility' => [0.0, 0.2, 0.9, 'off', 0.5, 'Высокая волатильность: сетка выключена, риск снижен.'],
        ][$regime];
        return ['regime' => $regime, 'confidence' => 0.5, 'w_grid' => $t[0], 'w_trend' => $t[1], 'w_liquidation' => $t[2],
            'grid_mode' => $t[3], 'grid_step_atr' => $step, 'risk_mult' => $t[4], 'summary' => $t[5], 'source' => 'rules'];
    }

    /** Изменение не больше 20% за раз и в пределах границ. */
    public static function applyTuning(array $current, string $param, float $value): array
    {
        if (!isset(self::TUNABLE[$param])) {
            return $current;
        }
        [$lo, $hi, $def] = self::TUNABLE[$param];
        $old = (float)($current[$param] ?? $def);
        $value = max($old * 0.8, min($old * 1.2, $value));
        $current[$param] = round(max($lo, min($hi, $value)), 4);
        return $current;
    }

    public static function usageCost(string $model, int $in, int $out, int $cacheRead, int $cacheWrite): float
    {
        [$pi, $po] = self::PRICES[$model] ?? self::PRICES['claude-opus-5'];
        return ($in * $pi + $cacheRead * $pi * 0.1 + $cacheWrite * $pi * 1.25 + $out * $po) / 1_000_000;
    }

    private function client(): ?Client
    {
        $key = (string)Settings::get('anthropic_api_key');
        if ($key === '') {
            return null;
        }
        if ($key !== $this->clientKey) {
            $this->client = new Client(apiKey: $key);
            $this->clientKey = $key;
        }
        return $this->client;
    }

    public function enabled(): bool
    {
        return $this->client() !== null;
    }

    private function jsonCall(string $system, array $schema, array $payload, int $maxTokens, string $kind): ?array
    {
        $client = $this->client();
        if ($client === null) {
            return null;
        }
        $model = (string)Settings::get('ai_model');
        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        if (!str_starts_with($model, 'claude-haiku')) {
            $outputConfig['effort'] = 'low';
        }
        $args = [
            'maxTokens' => $maxTokens,
            'messages' => [['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE)]],
            'model' => $model,
            'outputConfig' => $outputConfig,
            'system' => [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
        ];
        if (str_starts_with($model, 'claude-opus-5') || str_starts_with($model, 'claude-fable-5')) {
            // при отказе фильтров запрос автоматически повторяется на резервной модели
            $args['betas'] = ['server-side-fallback-2026-07-01'];
            $args['fallbacks'] = 'default';
        }
        try {
            $msg = $client->beta->messages->create(...$args);
        } catch (RateLimitException) {
            Log::warn('Claude: лимит запросов — используем запасной алгоритм');
            return null;
        } catch (APIStatusException $e) {
            Log::warn('Claude: ошибка API ' . $e->getMessage());
            return null;
        } catch (APIConnectionException) {
            Log::warn('Claude: нет соединения');
            return null;
        }
        $this->logUsage($kind, $msg->model ?: $model, $msg->usage);
        if (in_array($msg->stopReason, ['refusal', 'max_tokens'], true)) {
            Log::warn("Claude: ответ не получен ({$msg->stopReason})");
            return null;
        }
        foreach ($msg->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                return is_array($data) ? $data : null;
            }
        }
        return null;
    }

    private function logUsage(string $kind, string $model, object $u): void
    {
        $in = (int)($u->inputTokens ?? 0);
        $out = (int)($u->outputTokens ?? 0);
        $cr = (int)($u->cacheReadInputTokens ?? 0);
        $cw = (int)($u->cacheCreationInputTokens ?? 0);
        DB::insert('ai_usage', ['ts' => DB::now(), 'model' => $model, 'kind' => $kind, 'input_tokens' => $in,
            'output_tokens' => $out, 'cache_read_tokens' => $cr, 'cache_write_tokens' => $cw,
            'cost_usd' => self::usageCost($model, $in, $out, $cr, $cw)]);
    }

    public function analyze(string $symbol, array $f, array $learnerStats, array $lessons, array $tuning,
                            array $liq1h, ?float $funding): array
    {
        $fallback = self::ruleInsight($f, $tuning);
        if (!$this->enabled()) {
            return $fallback;
        }
        $r = fn($v) => round((float)$v, 4);
        $payload = [
            'symbol' => $symbol,
            'market' => [
                'price' => $f['price'], 'atr_pct' => $r($f['atr_pct']), 'atr_pct_median_17h' => $r($f['atr_pct_median']),
                'adx' => $r($f['adx']), 'rsi' => $r($f['rsi']), 'bb_width_pct' => $r($f['bb_width_pct']),
                'ret_1h_pct' => $r($f['ret_1h']), 'ret_4h_pct' => $r($f['ret_4h']), 'ret_24h_pct' => $r($f['ret_24h']),
                'price_vs_ema20_pct' => $r(($f['price'] / $f['ema20'] - 1) * 100),
                'ema50_vs_ema200_pct' => $r(($f['ema50'] / $f['ema200'] - 1) * 100),
                'distance_from_vwap4h_atr' => $r($f['dist_vwap_atr']),
                'funding_rate' => $funding,
                'liquidations_1h_usd' => array_map(fn($v) => round($v), $liq1h),
            ],
            'rule_based_regime' => $fallback['regime'],
            'learning_stats' => (object)$learnerStats,
            'lessons' => array_slice($lessons, -8),
            'current_tuning' => (object)$tuning,
        ];
        $data = $this->jsonCall(self::SYSTEM, self::insightSchema(), $payload, 8000, 'analyze');
        if ($data === null) {
            return $fallback;
        }
        $ins = self::clamp($data, 'ai');
        if (isset($tuning['grid_step_atr']) && $ins['grid_step_atr'] == self::TUNABLE['grid_step_atr'][2]) {
            $ins['grid_step_atr'] = $tuning['grid_step_atr'];
        }
        return $ins;
    }

    public function reviewDay(array $stats, array $lessons): ?array
    {
        return $this->jsonCall(self::REVIEW_SYSTEM, self::reviewSchema(),
            ['stats' => $stats, 'current_lessons' => array_slice($lessons, -10)], 16000, 'review');
    }
}
