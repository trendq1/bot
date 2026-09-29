<?php
declare(strict_types=1);

namespace App\Engine;

/** Общее знание о рынке, которое демон обновляет для всех клиентов. */
final class Brain
{
    /** symbol => признаки рынка по 5м */
    public array $features = [];
    /** symbol => признаки рынка по 1h */
    public array $featuresH1 = [];
    /** symbol => жёсткий режим рынка (Regime::*), определяет, какие стратегии вообще можно открывать */
    public array $regimes = [];
    /** symbol => вывод ИИ/алгоритма */
    public array $insights = [];
    /** symbol => подстроенные параметры */
    public array $tuning = [];

    public function __construct(public Learner $learner) {}

    /** Пересчёт признаков и режима по свечам монеты — один и тот же код в демоне и в бэктесте. */
    public function refresh(string $sym, SymbolFeed $feed): void
    {
        $f = Indicators::features($feed->klines, $feed->price);
        if ($f) {
            $this->features[$sym] = $f;
        }
        $h1 = Indicators::features($feed->klines1h, $feed->price);
        if ($h1) {
            $this->featuresH1[$sym] = $h1;
        } else {
            unset($this->featuresH1[$sym]);
        }
        $this->regimes[$sym] = Regime::classify($h1, $f, Indicators::htfTrend($feed->klines1h, 4));
    }
}
