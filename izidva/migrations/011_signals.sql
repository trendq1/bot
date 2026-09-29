-- Сигналы из Telegram (пересланные боту или посты канала): разбор, дедупликация и исполнение клиентам.
-- status: new -> processed | rejected (не распознан/не прошёл проверки) | duplicate
CREATE TABLE IF NOT EXISTS signals (
	id INTEGER NOT NULL AUTO_INCREMENT,
	symbol VARCHAR(24) NOT NULL,
	side VARCHAR(4) NOT NULL,
	entry_lo DOUBLE NOT NULL,
	entry_hi DOUBLE NOT NULL,
	stop_loss DOUBLE NOT NULL,
	targets JSON NOT NULL,
	channel_leverage INTEGER,
	raw_text TEXT NOT NULL,
	source VARCHAR(64) NOT NULL,
	reply_chat BIGINT,
	status VARCHAR(16) NOT NULL DEFAULT 'new',
	summary TEXT,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_signals_status ON signals (status, id);
CREATE INDEX ix_signals_symbol ON signals (symbol, created_at);

-- Результат сигнала по каждому клиенту: открыто / пропущено и почему.
CREATE TABLE IF NOT EXISTS signal_orders (
	id INTEGER NOT NULL AUTO_INCREMENT,
	signal_id INTEGER NOT NULL,
	user_id BIGINT NOT NULL,
	status VARCHAR(16) NOT NULL,
	detail TEXT,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	FOREIGN KEY(signal_id) REFERENCES signals (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_signal_orders_signal ON signal_orders (signal_id);
