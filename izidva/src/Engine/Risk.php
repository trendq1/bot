<?php
declare(strict_types=1);

namespace App\Engine;

/** Риск-профили клиентов и защита депозита. */
final class Risk
{
    /**
     * code => параметры. grid_levels держим малым: полный стоп сетки = levels×(levels+2)/2 циклов прибыли
     * (6 уровней — 24 цикла, 4 — 12), иначе один стоп стирает целый день мелких плюсов.
     */
    public const PROFILES = [
        'conservative' => ['title' => 'Консервативный', 'risk_pct' => 0.3, 'rr' => 1.2, 'leverage' => 5, 'grid_alloc' => 0.30,
            'grid_levels' => 3, 'grid_max_loss_pct' => 1.5, 'max_grids' => 2, 'max_directional' => 1,
            'daily_loss_pct' => 2.0, 'max_consecutive_losses' => 3, 'max_directional_trades_per_day' => 4,
            'max_total_grid_risk_pct' => 3.0, 'max_floating_loss_pct' => 4.0],
        'balanced' => ['title' => 'Сбалансированный', 'risk_pct' => 0.5, 'rr' => 1.2, 'leverage' => 15, 'grid_alloc' => 0.45,
            'grid_levels' => 4, 'grid_max_loss_pct' => 2.5, 'max_grids' => 3, 'max_directional' => 2,
            'daily_loss_pct' => 3.0, 'max_consecutive_losses' => 4, 'max_directional_trades_per_day' => 6,
            'max_total_grid_risk_pct' => 7.5, 'max_floating_loss_pct' => 6.0],
        'aggressive' => ['title' => 'Агрессивный', 'risk_pct' => 1.0, 'rr' => 1.0, 'leverage' => 25, 'grid_alloc' => 0.60,
            'grid_levels' => 5, 'grid_max_loss_pct' => 4.0, 'max_grids' => 4, 'max_directional' => 3,
            'daily_loss_pct' => 5.0, 'max_consecutive_losses' => 5, 'max_directional_trades_per_day' => 10,
            'max_total_grid_risk_pct' => 16.0, 'max_floating_loss_pct' => 10.0],
    ];

    public static function profile(string $code): array
    {
        return ['code' => isset(self::PROFILES[$code]) ? $code : 'balanced'] + (self::PROFILES[$code] ?? self::PROFILES['balanced']);
    }
}
