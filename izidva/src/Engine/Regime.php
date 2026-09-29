<?php
declare(strict_types=1);

namespace App\Engine;

/**
 * Жёсткий (не зависящий от ИИ) режим рынка по часовым свечам + подтверждение 4h и всплески на 5м.
 * Отвечает на вопрос «есть ли здесь вообще преимущество для какой-то стратегии». По умолчанию — NO_TRADE:
 * торговля разрешается только когда режим однозначно подходит стратегии (см. allows()).
 */
final class Regime
{
    public const STRONG_UP = 'strong_up';
    public const STRONG_DOWN = 'strong_down';
    public const WEAK_TREND = 'weak_trend';
    public const RANGE = 'range';
    public const BREAKOUT = 'breakout';
    public const HIGH_VOL = 'high_volatility';
    public const NO_TRADE = 'no_trade';

    public const ALL = [self::STRONG_UP, self::STRONG_DOWN, self::WEAK_TREND, self::RANGE, self::BREAKOUT, self::HIGH_VOL, self::NO_TRADE];

    /** Пороги ADX на 1h: выше STRONG — тренд, ниже RANGE — боковик, между ними — неопределённость (не торгуем). */
    public const ADX_STRONG = 25.0;
    public const ADX_RANGE = 20.0;

    /**
     * @param ?array $h1  Indicators::features() по 1h-свечам
     * @param ?array $m5  Indicators::features() по 5м-свечам
     * @param ?string $htf 'up'|'down'|null — направление 4h (Indicators::htfTrend по 1h-свечам ×4)
     */
    public static function classify(?array $h1, ?array $m5, ?string $htf): string
    {
        if ($h1 === null || $m5 === null) {
            return self::NO_TRADE;                             // нет истории — нет оснований торговать
        }
        if (Indicators::isExtremeVolatility($m5) || $h1['atr_pct'] > 2.0 * $h1['atr_pct_median']) {
            return self::HIGH_VOL;
        }
        if (Indicators::isBreakout($m5) || Indicators::isBreakout($h1)) {
            return self::BREAKOUT;
        }
        $adx = $h1['adx'];
        $price = $h1['price'];
        if ($adx >= self::ADX_STRONG && $h1['ema20'] > $h1['ema50'] && $h1['ema50'] > $h1['ema200'] && $price > $h1['ema50']
            && $h1['ema50_slope_pct'] > 0 && $htf !== 'down') {
            return self::STRONG_UP;
        }
        if ($adx >= self::ADX_STRONG && $h1['ema20'] < $h1['ema50'] && $h1['ema50'] < $h1['ema200'] && $price < $h1['ema50']
            && $h1['ema50_slope_pct'] < 0 && $htf !== 'up') {
            return self::STRONG_DOWN;
        }
        // Боковик — не «тренд не найден», а подтверждённое отсутствие направления: низкий ADX на 1h и 5м,
        // плоская EMA50 и цена внутри суточного диапазона, который шире нескольких ATR (есть где ходить сетке).
        $rangeWidthAtr = $h1['atr'] > 0 ? ($h1['hi_24'] - $h1['lo_24']) / $h1['atr'] : 0.0;
        if ($adx < self::ADX_RANGE && $m5['adx'] < self::ADX_STRONG && abs($h1['ema50_slope_pct']) < 0.3
            && $rangeWidthAtr >= 3.0 && $price <= $h1['hi_24'] && $price >= $h1['lo_24']) {
            return self::RANGE;
        }
        return self::WEAK_TREND;
    }

    /** Какие стратегии вообще допустимы в режиме. Ручные сделки трейдера сюда не относятся. */
    public static function allows(string $regime, string $strategy): bool
    {
        return match ($strategy) {
            'trend' => in_array($regime, [self::STRONG_UP, self::STRONG_DOWN], true),
            'grid' => $regime === self::RANGE,
            // отскок после каскада ликвидаций — только когда нет сильного тренда, который его задавит
            'liquidation' => in_array($regime, [self::RANGE, self::HIGH_VOL], true),
            default => false,
        };
    }

    /**
     * Сторона сетки в RANGE — по положению цены внутри суточного диапазона, а не «ниже VWAP — лонг»
     * (из-за этого 130 из 135 сделок сетки были покупками на падающем рынке). В середине диапазона — null:
     * преимущества нет ни в одну сторону.
     * @return 'long'|'short'|null
     */
    public static function rangeGridSide(array $h1): ?string
    {
        $width = $h1['hi_24'] - $h1['lo_24'];
        if ($width <= 0) {
            return null;
        }
        $pos = ($h1['price'] - $h1['lo_24']) / $width;
        if ($pos <= 0.35) {
            return 'long';
        }
        if ($pos >= 0.65) {
            return 'short';
        }
        return null;
    }
}
