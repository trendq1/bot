<?php
declare(strict_types=1);

namespace App\Engine;

/**
 * Рисует свечной график в PNG средствами GD — чтобы отдать «картинку рынка» в vision-запрос Claude,
 * без похода в headless-браузер или сторонний сервис (TradingView не отдаёт скриншот по API).
 */
final class ChartRenderer
{
    /**
     * @param list<array{open:float,high:float,low:float,close:float}> $candles хронологический порядок
     */
    public static function candlesPng(array $candles, string $title = ''): string
    {
        $w = 900;
        $h = 480;
        $padL = 60;
        $padR = 20;
        $padT = 36;
        $padB = 20;
        $img = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($img, 11, 13, 18);
        $grid = imagecolorallocate($img, 40, 44, 54);
        $text = imagecolorallocate($img, 180, 186, 198);
        $up = imagecolorallocate($img, 34, 230, 160);
        $down = imagecolorallocate($img, 239, 83, 80);
        imagefilledrectangle($img, 0, 0, $w, $h, $bg);

        if (!$candles) {
            imagestring($img, 5, $padL, $padT, 'нет данных', $text);
            return self::toPng($img);
        }
        $highs = array_column($candles, 'high');
        $lows = array_column($candles, 'low');
        $max = max($highs);
        $min = min($lows);
        if ($max <= $min) {
            $max += 1;
            $min -= 1;
        }
        $pad = ($max - $min) * 0.06;
        $max += $pad;
        $min -= $pad;
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;
        $n = count($candles);
        $slot = $plotW / $n;
        $bodyW = max(1.0, $slot * 0.6);
        $y = fn(float $v) => $padT + (1 - ($v - $min) / ($max - $min)) * $plotH;

        for ($i = 0; $i <= 4; $i++) {
            $v = $min + ($max - $min) * $i / 4;
            $yy = (int)round($y($v));
            imageline($img, $padL, $yy, $w - $padR, $yy, $grid);
            imagestring($img, 2, 4, $yy - 6, number_format($v, $v < 10 ? 4 : 2), $text);
        }
        if ($title !== '') {
            imagestring($img, 5, $padL, 10, $title, $text);
        }

        foreach ($candles as $i => $c) {
            $cx = $padL + $slot * $i + $slot / 2;
            $color = $c['close'] >= $c['open'] ? $up : $down;
            imageline($img, (int)round($cx), (int)round($y($c['high'])), (int)round($cx), (int)round($y($c['low'])), $color);
            $top = (int)round($y(max($c['open'], $c['close'])));
            $bot = (int)round($y(min($c['open'], $c['close'])));
            imagefilledrectangle($img, (int)round($cx - $bodyW / 2), max($top, $padT), (int)round($cx + $bodyW / 2), max($bot, $top + 1), $color);
        }
        return self::toPng($img);
    }

    /** @param \GdImage $img */
    private static function toPng($img): string
    {
        ob_start();
        imagepng($img);
        $data = (string)ob_get_clean();
        imagedestroy($img);
        return $data;
    }
}
