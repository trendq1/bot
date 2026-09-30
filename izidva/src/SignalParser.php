<?php
declare(strict_types=1);

namespace App;

/**
 * Разбор торговых сигналов из Telegram строгим шаблоном (без ИИ: цифры не «додумываются»).
 * Формат канала: «СИГНАЛ #PENGU/USDT / Открыть ШОРТ в диапазоне $a - $b с плечом X25 / Закрыть по $t1 … / СТОП ЛОСС: $s».
 */
final class SignalParser
{
    /** Слова по умолчанию; у каждого канала можно переопределить любой список (через запятую) в parser_config. */
    public const DEFAULTS = [
        'symbol_style' => 'hash',
        'long_words' => 'лонг,long',
        'short_words' => 'шорт,short',
        'entry_words' => 'диапазон,вход',
        'target_words' => 'закрыть,тейк,цель,цели,tp',
        'stop_words' => 'стоп лосс,stop loss,sl',
        'close_words' => 'закрываем,закрыть все,закрыть сделку,закрываю,закрыта,close',
        'be_words' => 'безубыток,безубытку,б/у,breakeven,break even',
    ];

    /** Список слов из строки «a, b c» или массива → регулярка-альтернатива (пробел/дефис между частями необязательны). */
    private static function alt(array $cfg, string $key): string
    {
        $raw = $cfg[$key] ?? '';
        $words = is_array($raw) ? $raw : explode(',', (string)$raw);
        $words = array_values(array_filter(array_map(fn($w) => trim((string)$w), $words), fn($w) => $w !== ''));
        // слова канала ДОБАВЛЯЮТСЯ к стандартным: опечатка или неполный список не лишают разбор базовых слов
        $words = array_merge($words, explode(',', self::DEFAULTS[$key]));
        $seen = [];
        $words = array_values(array_filter($words, function ($w) use (&$seen) {
            $k = mb_strtolower(trim($w));
            return $k !== '' && !isset($seen[$k]) && ($seen[$k] = true);
        }));
        usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $parts = [];
        foreach ($words as $w) {
            $parts[] = implode('[\s\-]*', array_map(fn($x) => preg_quote($x, '/'), preg_split('/[\s\-]+/u', $w, -1, PREG_SPLIT_NO_EMPTY) ?: [$w]));
        }
        return '(?:' . implode('|', $parts) . ')';
    }

    private static function cfg(array $cfg): array
    {
        return array_filter($cfg, fn($v) => $v !== null && $v !== '' && $v !== []) + self::DEFAULTS;
    }

    /** Монета из текста: hash — только «#COIN/USDT»; any — ещё «COINUSDT», «COIN/USDT», «#COIN». */
    public static function findSymbol(string $text, array $cfg = []): ?string
    {
        $cfg = self::cfg($cfg);
        if ($cfg['symbol_style'] === 'any') {
            if (preg_match('/(?<![A-Za-z0-9])#?\s*([A-Za-z0-9]{2,15}?)\s*[\/\-]?\s*USDT(?![A-Za-z])/u', $text, $m)
                || preg_match('/#\s*([A-Za-z0-9]{2,15})(?![A-Za-z0-9])/u', $text, $m)) {
                return strtoupper($m[1]) . 'USDT';
            }
            // «LDO LONG» / «ARB ШОРТ»: заглавная монета, за которой сразу идёт слово направления
            $side = '(?:' . self::alt($cfg, 'long_words') . '|' . self::alt($cfg, 'short_words') . ')';
            if (preg_match_all('/(?<![A-Za-z0-9])([A-Z][A-Z0-9]{1,14})[ \t]+' . $side . '(?![\p{L}\p{N}])/iu', $text, $mm)) {
                foreach ($mm[1] as $c) {
                    if ($c === strtoupper($c) && !in_array($c, ['BUY', 'SELL', 'GO', 'TRADE', 'SIGNAL', 'ENTER', 'OPEN', 'MARKET', 'NEW'], true)) {
                        return $c . 'USDT';
                    }
                }
            }
            return null;
        }
        return preg_match('/#\s*([A-Z0-9]{2,15})\s*\/\s*USDT/iu', $text, $m) ? strtoupper($m[1]) . 'USDT' : null;
    }

    /** @return array{symbol:string,side:string,entry_lo:float,entry_hi:float,stop:float,targets:list<float>,leverage:?int}|null */
    public static function parse(string $text, array $cfg = []): ?array
    {
        $cfg = self::cfg($cfg);
        $num = fn(string $s) => (float)str_replace(',', '.', $s);
        $symbol = self::findSymbol($text, $cfg);
        if ($symbol === null) {
            return null;
        }
        $b = '(?<![\p{L}\p{N}])';
        $e = '(?![\p{L}\p{N}])';
        $hasLong = preg_match('/' . $b . self::alt($cfg, 'long_words') . $e . '/iu', $text);
        $hasShort = preg_match('/' . $b . self::alt($cfg, 'short_words') . $e . '/iu', $text);
        if ($hasLong === $hasShort) {                          // ни одного или оба сразу — не гадаем
            if (!$hasLong) {
                return null;
            }
            preg_match('/' . $b . '(' . self::alt($cfg, 'long_words') . '|' . self::alt($cfg, 'short_words') . ')' . $e . '/iu', $text, $fm);
            $hasLong = preg_match('/^' . self::alt($cfg, 'long_words') . '$/iu', $fm[1] ?? '');
        }
        $side = $hasLong ? 'Buy' : 'Sell';
        // вход — диапазон «$a - $b» или одна цена «$a» (тогда зона входа схлопывается в точку)
        if (!preg_match('/' . self::alt($cfg, 'entry_words') . '\w*[\s:=\-–—]*\$?\s*(\d+(?:[.,]\d+)?)(?:\s*[-–—]\s*\$?\s*(\d+(?:[.,]\d+)?))?/iu', $text, $m)) {
            return null;
        }
        $a = $num($m[1]);
        $hi = isset($m[2]) && $m[2] !== '' ? $num($m[2]) : $a;
        if (!preg_match('/' . $b . self::alt($cfg, 'stop_words') . '[\s:=\-–—]*\$?\s*(\d+(?:[.,]\d+)?)/iu', $text, $sm)) {
            return null;
        }
        // после слова цели — одна цена или список «a, b, c» («цели - 0.49, 0.50, 0.52»)
        preg_match_all('/' . $b . self::alt($cfg, 'target_words') . '\w*[\s:=\-–—]*(?:по)?[\s:=\-–—]*((?:\$?\d+(?:[.,]\d+)?\$?(?:[ \t]*[;,][ \t]+|[ \t]*;[ \t]*|[ \t]+(?=\$?\d))?)+)/iu', $text, $tm);
        $targets = [];
        foreach ($tm[1] ?? [] as $chunk) {
            preg_match_all('/\d+(?:[.,]\d+)?/', $chunk, $nm);
            foreach ($nm[0] as $n) {
                $targets[] = $num($n);
            }
        }
        $leverage = preg_match('/[xх]\s*(\d{1,3})\b/iu', $text, $lm) ? (int)$lm[1] : null;
        return ['symbol' => $symbol, 'side' => $side, 'entry_lo' => min($a, $hi), 'entry_hi' => max($a, $hi),
            'stop' => $num($sm[1]), 'targets' => array_values($targets), 'leverage' => $leverage];
    }

    /**
     * Обновление по открытой сделке: «#COIN … закрываем» или «#COIN … в безубыток». Нет монеты или неясно
     * (оба смысла сразу) — null: лучше ничего не делать, чем закрыть чужую позицию.
     * @return array{symbol:string,action:string}|null action = close|breakeven
     */
    public static function parseUpdate(string $text, array $cfg = []): ?array
    {
        $cfg = self::cfg($cfg);
        $symbol = self::findSymbol($text, $cfg);
        if ($symbol === null) {
            return null;
        }
        $b = '(?<![\p{L}\p{N}])';
        $e = '(?![\p{L}\p{N}])';
        // пост со входом или стоп-лоссом — это (возможно, сломанный) сигнал, а не команда: не закрываем чужую сделку по ошибке
        if (preg_match('/' . $b . self::alt($cfg, 'stop_words') . '[\s:=\-–—]*\$?\s*\d/iu', $text)
            || preg_match('/' . self::alt($cfg, 'entry_words') . '\w*[\s:=\-–—]*\$?\s*\d/iu', $text)) {
            return null;
        }
        $close = (bool)preg_match('/' . $b . self::alt($cfg, 'close_words') . $e . '/iu', $text);
        $be = (bool)preg_match('/' . $b . self::alt($cfg, 'be_words') . '/iu', $text);
        if ($close === $be) {
            return null;
        }
        return ['symbol' => $symbol, 'action' => $close ? 'close' : 'breakeven'];
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
