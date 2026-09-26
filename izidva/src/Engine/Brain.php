<?php
declare(strict_types=1);

namespace App\Engine;

/** Общее знание о рынке, которое демон обновляет для всех клиентов. */
final class Brain
{
    /** symbol => признаки рынка */
    public array $features = [];
    /** symbol => вывод ИИ/алгоритма */
    public array $insights = [];
    /** symbol => подстроенные параметры */
    public array $tuning = [];

    public function __construct(public Learner $learner) {}
}
