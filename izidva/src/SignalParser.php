<?php
declare(strict_types=1);

namespace App;

/**
 * Разбор торговых сигналов из Telegram строгим шаблоном (без ИИ: цифры не «додумываются»).
 * Формат канала: «СИГНАЛ #PENGU/USDT / Открыть ШОРТ в диапазоне $a - $b с плечом X25 / Закрыть по $t1 … / СТОП ЛОСС: $s».
 */
final class SignalParser
{
    /** @return array{symbol:string,side:string,entry_lo:float,entry_hi:float,stop:float,targets:list<float>,leverage:?int}|null */
    public static function parse(string $text): ?array
    {
        $num = fn(string $s) => (float)str_replace(',', '.', $s);
        if (!preg_match('/#\s*([A-Z0-9]{2,15})\s*\/\s*USDT/iu', $text, $m)) {
            return null;
        }
        $symbol = strtoupper($m[1]) . 'USDT';
        if (!preg_match('/\b(лонг|long|шорт|short)\b/iu', $text, $m)) {
            return null;
        }
        $side = in_array(mb_strtolower($m[1]), ['лонг', 'long'], true) ? 'Buy' : 'Sell';
        if (!preg_match('/(?:диапазон\w*|вход\w*)\s*\$?\s*(\d+(?:[.,]\d+)?)\s*[-–—]\s*\$?\s*(\d+(?:[.,]\d+)?)/iu', $text, $m)) {
            return null;
        }
        $a = $num($m[1]);
        $b = $num($m[2]);
        if (!preg_match('/стоп[\s-]*лосс\s*:?\s*\$?\s*(\d+(?:[.,]\d+)?)/iu', $text, $sm)) {
            return null;
        }
        preg_match_all('/(?:закрыть|тейк|цель|tp)\w*\s*(?:по)?\s*:?\s*\$?\s*(\d+(?:[.,]\d+)?)/iu', $text, $tm);
        $targets = array_map($num, $tm[1] ?? []);
        $leverage = preg_match('/[xх]\s*(\d{1,3})\b/iu', $text, $lm) ? (int)$lm[1] : null;
        return ['symbol' => $symbol, 'side' => $side, 'entry_lo' => min($a, $b), 'entry_hi' => max($a, $b),
            'stop' => $num($sm[1]), 'targets' => array_values($targets), 'leverage' => $leverage];
    }

    /**
     * Логическая проверка разобранного сигнала — защита от опечаток канала и ошибок разбора.
     * @return string|null текст ошибки или null, если сигнал корректен
     */
    public static function validate(array $s): ?string
    {
        $long = $s['side'] === 'Buy';
        $mid = ($s['entry_lo'] + $s['entry_hi']) / 2;
        if ($s['entry_lo'] <= 0 || $s['stop'] <= 0 || !$s['targets']) {
            return 'нет цены входа, стопа или целей';
        }
        if (($s['entry_hi'] - $s['entry_lo']) / $mid > 0.03) {
            return 'диапазон входа шире 3% — похоже на ошибку в сообщении';
        }
        if ($long ? $s['stop'] >= $s['entry_lo'] : $s['stop'] <= $s['entry_hi']) {
            return 'стоп-лосс стоит не с той стороны от входа';
        }
        $prev = $mid;
        foreach ($s['targets'] as $t) {
            if ($long ? $t <= $prev - 1e-12 : $t >= $prev + 1e-12) {
                return 'цели идут не в сторону прибыли или не по порядку';
            }
            $prev = $t;
        }
        if (abs($mid - $s['stop']) / $mid > 0.15) {
            return 'стоп дальше 15% от входа — похоже на ошибку в сообщении';
        }
        return null;
    }
}
