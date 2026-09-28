-- Клиентская настройка: множитель бюджета/риска на открытие сделки (0.5–1.5, см. Worker::BUDGET_MULT_MIN/MAX).
-- Масштабирует и капитал сетки, и риск-бюджет направленной сделки пропорционально — Risk Engine (Grid Risk
-- Protection, Portfolio Risk Engine) всё равно ограничивает суммарный риск сверху, так что диапазон безопасен.
ALTER TABLE bot_settings ADD COLUMN budget_mult FLOAT NOT NULL DEFAULT 1.0 AFTER risk_profile;
