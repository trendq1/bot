<?php
declare(strict_types=1);

namespace App;

/**
 * Разбор торговых сигналов из Telegram правилами (без ИИ: цифры не «додумываются»); ИИ — только запасной вариант, см. Signals::aiParse.
 * Формат канала: «СИГНАЛ #PENGU/USDT / Открыть ШОРТ в диапазоне $a - $b с плечом X25 / Закрыть по $t1 … / СТОП ЛОСС: $s».
 */
final class SignalParser
{
    /** Слова по умолчанию; слова канала из parser_config ДОБАВЛЯЮТСЯ к ним (через запятую). */
    public const DEFAULTS = [
        'symbol_style' => 'any',
        'long_words' => 'лонг,long,buy,покупка,покупаем,лонгуем',
        'short_words' => 'шорт,short,sell,продажа,продаем,шортим',
        'entry_words' => 'диапазон,вход,зона входа,зоне,entry,enter,buy zone,sell zone,по цене',
        'target_words' => 'закрыть,тейк профит,тейк,цель,цели,tp,take profit,takeprofit,target,targets,тп,профит',
        'stop_words' => 'стоп лосс,stop loss,stoploss,sl,стоп,stop',
        'close_words' => 'закрываем,закрыть все,закрыть сделку,закрываю,закрыта,close',
        'be_words' => 'безубыток,безубытку,б/у,breakeven,break even',
    ];

    private const NUM = '\d+(?:[.,]\d+)?';
    /** Хэштеги и слова, которые не считаются монетой. */
    private const NOT_COINS = ['BUY', 'SELL', 'GO', 'TRADE', 'SIGNAL', 'SIGNALS', 'ENTER', 'OPEN', 'MARKET', 'NEW', 'CRYPTO', 'FUTURES', 'BINANCE', 'BYBIT',
        'VIP', 'FREE', 'LONG', 'SHORT', 'SCALP', 'SWING', 'SPOT', 'USDT', 'USD', 'TP', 'SL', 'PNL', 'ROI', 'STOP', 'LOSS', 'TARGET', 'ENTRY'];

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

    /**
     * Монета из текста. hash — только «#COIN/USDT». any (по умолчанию) — также «COINUSDT», «COIN/USDT», «$COIN», «#COIN»,
     * «COIN LONG», «LONG COIN», «Монета: COIN».
     */
    public static function findSymbol(string $text, array $cfg = []): ?string
    {
        $cfg = self::cfg($cfg);
        if ($cfg['symbol_style'] !== 'any') {
            return preg_match('/#\s*([A-Z0-9]{2,15})\s*\/\s*USDT/iu', $text, $m) ? strtoupper($m[1]) . 'USDT' : null;
        }
        $ok = fn(string $c) => !in_array(strtoupper($c), self::NOT_COINS, true) && preg_match('/[A-Za-z]/', $c);
        if (preg_match_all('/(?<![A-Za-z0-9])[#$]?\s*([A-Za-z0-9]{2,15}?)\s*[\/\-:]?\s*USDT(?![A-Za-z])/u', $text, $mm)) {
            foreach ($mm[1] as $c) {
                if ($ok($c)) {
                    return strtoupper($c) . 'USDT';
                }
            }
        }
        $side = '(?:' . self::alt($cfg, 'long_words') . '|' . self::alt($cfg, 'short_words') . ')';
        $patterns = [
            '/#\s*([A-Za-z0-9]{2,15})(?![A-Za-z0-9])/u',                                       // #COIN
            '/\$([A-Za-z][A-Za-z0-9]{1,14})(?![A-Za-z0-9])/u',                                  // $COIN
            '/(?:монета|coin|пара|pair|токен|token)\s*[:=\-–—]?\s*([A-Za-z0-9]{2,15})(?![A-Za-z0-9])/iu',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $text, $mm)) {
                foreach ($mm[1] as $c) {
                    if ($ok($c)) {
                        return strtoupper($c) . 'USDT';
                    }
                }
            }
        }
        // «LDO LONG» / «LONG LDO»: заглавная монета рядом со словом направления
        foreach (['/(?<![A-Za-z0-9])([A-Z][A-Z0-9]{1,14})[ \t]+' . $side . '(?![\p{L}\p{N}])/iu',
                  '/(?<![\p{L}\p{N}])' . $side . '[ \t]+#?([A-Z][A-Z0-9]{1,14})(?![A-Za-z0-9])/iu'] as $re) {
            if (preg_match_all($re, $text, $mm)) {
                foreach (end($mm) as $c) {
                    if ($c === strtoupper($c) && $ok($c)) {
                        return $c . 'USDT';
                    }
                }
            }
        }
        return null;
    }

    /**
     * Разбор по частям; отсутствующая часть = null. Используется и разбором, и подсказкой «чего не хватает».
     * @return array{symbol:?string,side:?string,entry:?array,stop:?float,targets:list<float>,leverage:?int}
     */
    private static function extract(string $text, array $cfg): array
    {
        $cfg = self::cfg($cfg);
        $num = fn(string $s) => (float)str_replace(',', '.', $s);
        $N = self::NUM;
        $b = '(?<![\p{L}\p{N}])';
        $e = '(?![\p{L}\p{N}])';
        $symbol = self::findSymbol($text, $cfg);
        // направление
        $longRe = $b . self::alt($cfg, 'long_words') . $e;
        $shortRe = $b . self::alt($cfg, 'short_words') . $e;
        $hasLong = (bool)preg_match('/' . $longRe . '/iu', $text);
        $hasShort = (bool)preg_match('/' . $shortRe . '/iu', $text);
        $side = null;
        if ($hasLong !== $hasShort) {
            $side = $hasLong ? 'Buy' : 'Sell';
        } elseif ($hasLong && $hasShort && preg_match('/' . $b . '(' . self::alt($cfg, 'long_words') . '|' . self::alt($cfg, 'short_words') . ')' . $e . '/iu', $text, $fm)) {
            $side = preg_match('/^' . self::alt($cfg, 'long_words') . '$/iu', $fm[1]) ? 'Buy' : 'Sell';   // оба слова — берём то, что раньше
        }
        // вход: диапазон «a - b» / «от a до b», одна цена, либо «по рынку»
        $entryRe = self::alt($cfg, 'entry_words') . '\w*(?:[\s\-]+(?:входа|вход|цены|цена|entry|zone|зона|зоны|range))?';
        $entry = null;
        if (preg_match('/' . $entryRe . '[\s:=\-–—@]*(?:от|from|at)?\s*\$?\s*(' . $N . ')\$?(?:\s*(?:[-–—]|до|to)\s*\$?\s*(' . $N . '))?/iu', $text, $m)) {
            $a = $num($m[1]);
            $hi = isset($m[2]) && $m[2] !== '' ? $num($m[2]) : $a;
            $entry = ['lo' => min($a, $hi), 'hi' => max($a, $hi), 'market' => false];
        } elseif (preg_match('/' . $entryRe . '[^\n\d]{0,25}?(?:по\s+рынку|рыночн\w*|по\s+текущей|market|сейчас)/iu', $text)
            || preg_match('/(?:по\s+рынку|at\s+market|market\s+price|по\s+текущей\s+цене)/iu', $text)) {
            $entry = ['lo' => 0.0, 'hi' => 0.0, 'market' => true];
        }
        if ($entry === null && $side !== null
            && preg_match('/' . ($side === 'Buy' ? $longRe : $shortRe) . '[\s:=@]*(?:от|from|at|по|в)?\s*\$?\s*(' . $N . ')\$?(?:\s*(?:[-–—]|до|to)\s*\$?\s*(' . $N . '))?/iu', $text, $m)) {
            $a = $num($m[1]);                                      // «ETH Buy от 3000 до 3010», «BTC лонг 62000»
            $hi = isset($m[2]) && $m[2] !== '' ? $num($m[2]) : $a;
            $entry = ['lo' => min($a, $hi), 'hi' => max($a, $hi), 'market' => false];
        }
        // стоп
        $stop = preg_match('/' . $b . self::alt($cfg, 'stop_words') . '\w*[\s:=\-–—]*\$?\s*(' . $N . ')/iu', $text, $sm) ? $num($sm[1]) : null;
        // цели: одна цена или список «a, b, c» / «a / b / c» / «a b c»
        preg_match_all('/' . $b . self::alt($cfg, 'target_words') . '\w*[\s:=\-–—]*(?:по)?[\s:=\-–—]*(?:\d{1,2}[ \t]*[:.)\-–—](?!\d)[ \t]*)?((?:\$?' . $N . '\$?(?:[ \t]*[;,\/][ \t]+|[ \t]*;[ \t]*|[ \t]+(?=\$?\d))?)+)/iu', $text, $tm);
        $targets = [];
        foreach ($tm[1] ?? [] as $chunk) {
            preg_match_all('/' . $N . '/', $chunk, $nm);
            foreach ($nm[0] as $n) {
                $targets[] = $num($n);
            }
        }
        // направление не написано словом — выводим из стопа и целей (стоп ниже целей = лонг)
        if ($side === null && $stop !== null && $targets) {
            $ref = $entry && !$entry['market'] ? ($entry['lo'] + $entry['hi']) / 2 : $targets[0];
            $side = $targets[0] > $stop && $ref > $stop ? 'Buy' : ($targets[0] < $stop && $ref < $stop ? 'Sell' : null);
        }
        $leverage = preg_match('/[xх]\s*(\d{1,3})\b/iu', $text, $lm) ? (int)$lm[1] : null;
        return ['symbol' => $symbol, 'side' => $side, 'entry' => $entry, 'stop' => $stop, 'targets' => array_values($targets), 'leverage' => $leverage];
    }

    /** @return array{symbol:string,side:string,entry_lo:float,entry_hi:float,market:bool,stop:float,targets:list<float>,leverage:?int}|null */
    public static function parse(string $text, array $cfg = []): ?array
    {
        $x = self::extract($text, $cfg);
        if ($x['symbol'] === null || $x['side'] === null || $x['entry'] === null || $x['stop'] === null) {
            return null;
        }
        return ['symbol' => $x['symbol'], 'side' => $x['side'], 'entry_lo' => $x['entry']['lo'], 'entry_hi' => $x['entry']['hi'], 'market' => $x['entry']['market'],
            'stop' => $x['stop'], 'targets' => $x['targets'], 'leverage' => $x['leverage']];
    }

    /** Чего не хватило в посте, чтобы он стал сигналом (для понятного ответа человеку). @return list<string> */
    public static function missing(string $text, array $cfg = []): array
    {
        $x = self::extract($text, $cfg);
        $out = [];
        foreach ([['symbol', 'монета'], ['side', 'направление (лонг/шорт)'], ['entry', 'вход (цена, диапазон или «по рынку»)'], ['stop', 'стоп-лосс']] as [$k, $l]) {
            if ($x[$k] === null) {
                $out[] = $l;
            }
        }
        if (!$x['targets']) {
            $out[] = 'цели (тейк-профит)';
        }
        return $out;
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
        if ($s['entry_lo'] <= 0 && $s['entry_hi'] <= 0) {            // вход по рынку: цены входа нет, проверяем стоп и цели между собой
            if ($s['stop'] <= 0 || !$s['targets']) {
                return 'нет стопа или целей';
            }
            $prev = $s['stop'];
            foreach ($s['targets'] as $t) {
                if ($long ? $t <= $prev : $t >= $prev) {
                    return 'стоп и цели расположены не по порядку (стоп → цели в сторону прибыли)';
                }
                $prev = $t;
            }
            return null;
        }
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
