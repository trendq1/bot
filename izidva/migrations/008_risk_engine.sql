-- Portfolio Risk Engine, Grid Session PnL, расширенная статистика Learner.

-- session_id = Grid::$tag: по нему группируются все сделки одной "жизни" сетки (циклы + финальный стоп/drain) —
-- Grid Session PnL считается SQL-агрегацией GROUP BY session_id, отдельная таблица не нужна.
-- kind — cycle (обычный цикл) / stop (закрыта по стопу) / reversal (досрочно при развороте) — для directional/manual NULL.
ALTER TABLE trades ADD COLUMN session_id VARCHAR(16) NULL AFTER regime;
ALTER TABLE trades ADD COLUMN kind VARCHAR(16) NULL AFTER session_id;
CREATE INDEX ix_trades_session ON trades (session_id);

-- Learner: не только win-rate — сумма PnL, отдельно gross win/loss в R (для Profit Factor/Expectancy/Avg Win-Loss), худший R (tail loss).
ALTER TABLE strategy_stats ADD COLUMN sum_pnl FLOAT NOT NULL DEFAULT 0 AFTER ewma_r;
ALTER TABLE strategy_stats ADD COLUMN gross_win_r FLOAT NOT NULL DEFAULT 0 AFTER sum_pnl;
ALTER TABLE strategy_stats ADD COLUMN gross_loss_r FLOAT NOT NULL DEFAULT 0 AFTER gross_win_r;
ALTER TABLE strategy_stats ADD COLUMN worst_r FLOAT NOT NULL DEFAULT 0 AFTER gross_loss_r;
