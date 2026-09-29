-- Результат команды из админки (ок / текст ошибки) и длинные аргументы (JSON для изменения SL/TP, отмены ордера).
ALTER TABLE engine_commands ADD COLUMN result TEXT NULL;
ALTER TABLE engine_commands MODIFY COLUMN arg TEXT NULL;
