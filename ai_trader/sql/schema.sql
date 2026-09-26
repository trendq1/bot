-- Схема базы AI Trader для MySQL 5.7+ / MariaDB 10.3+.
-- Обычно не нужна: таблицы создаются автоматически при установке (/setup).
-- Для ручного импорта через phpMyAdmin (в ПУСТУЮ базу): выберите базу -> Импорт -> этот файл.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admin_users (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	username VARCHAR(64) NOT NULL, 
	password_hash VARCHAR(255) NOT NULL, 
	created_at DATETIME NOT NULL, 
	last_login DATETIME, 
	PRIMARY KEY (id), 
	UNIQUE (username)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_insights (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	symbol VARCHAR(24) NOT NULL, 
	ts DATETIME NOT NULL, 
	source VARCHAR(8) NOT NULL, 
	regime VARCHAR(16) NOT NULL, 
	payload JSON NOT NULL, 
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_ai_insights_symbol ON ai_insights (symbol);
CREATE INDEX ix_ai_insights_ts ON ai_insights (ts);

CREATE TABLE IF NOT EXISTS ai_usage (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	ts DATETIME NOT NULL, 
	model VARCHAR(48) NOT NULL, 
	kind VARCHAR(16) NOT NULL, 
	input_tokens INTEGER NOT NULL, 
	output_tokens INTEGER NOT NULL, 
	cache_read_tokens INTEGER NOT NULL, 
	cache_write_tokens INTEGER NOT NULL, 
	cost_usd FLOAT NOT NULL, 
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_ai_usage_ts ON ai_usage (ts);

CREATE TABLE IF NOT EXISTS app_settings (
	`key` VARCHAR(64) NOT NULL, 
	value TEXT NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (`key`)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	ts DATETIME NOT NULL, 
	actor VARCHAR(64) NOT NULL, 
	action VARCHAR(64) NOT NULL, 
	details TEXT, 
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_audit_log_ts ON audit_log (ts);

CREATE TABLE IF NOT EXISTS finance_entries (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	date DATETIME NOT NULL, 
	kind VARCHAR(8) NOT NULL, 
	category VARCHAR(32) NOT NULL, 
	amount_usd FLOAT NOT NULL, 
	note VARCHAR(255), 
	created_by VARCHAR(64), 
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_finance_entries_date ON finance_entries (date);

CREATE TABLE IF NOT EXISTS lessons (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	ts DATETIME NOT NULL, 
	text TEXT NOT NULL, 
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS strategy_stats (
	`key` VARCHAR(64) NOT NULL, 
	n INTEGER NOT NULL, 
	wins INTEGER NOT NULL, 
	ewma_r FLOAT NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (`key`)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS symbol_tuning (
	symbol VARCHAR(24) NOT NULL, 
	params JSON NOT NULL, 
	updated_at DATETIME NOT NULL, 
	PRIMARY KEY (symbol)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
	id BIGINT NOT NULL, 
	username VARCHAR(64), 
	first_name VARCHAR(128), 
	created_at DATETIME NOT NULL, 
	last_seen DATETIME, 
	sub_until DATETIME, 
	blocked BOOL NOT NULL, 
	notes TEXT, 
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_users_created_at ON users (created_at);

CREATE TABLE IF NOT EXISTS bot_settings (
	user_id BIGINT NOT NULL, 
	running BOOL NOT NULL, 
	trading_mode VARCHAR(8) NOT NULL, 
	risk_profile VARCHAR(16) NOT NULL, 
	symbols JSON NOT NULL, 
	strategies JSON NOT NULL, 
	paper_balance FLOAT NOT NULL, 
	status_text TEXT, 
	PRIMARY KEY (user_id), 
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS equity_snapshots (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	user_id BIGINT NOT NULL, 
	ts DATETIME NOT NULL, 
	equity FLOAT NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_equity_user_ts ON equity_snapshots (user_id, ts);

CREATE TABLE IF NOT EXISTS exchange_accounts (
	user_id BIGINT NOT NULL, 
	bybit_uid VARCHAR(32), 
	api_key_enc TEXT NOT NULL, 
	api_secret_enc TEXT NOT NULL, 
	mode VARCHAR(8) NOT NULL, 
	referral_ok BOOL NOT NULL, 
	created_at DATETIME NOT NULL, 
	last_error TEXT, 
	PRIMARY KEY (user_id), 
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	user_id BIGINT NOT NULL, 
	plan VARCHAR(16) NOT NULL, 
	stars INTEGER NOT NULL, 
	usd FLOAT NOT NULL, 
	charge_id VARCHAR(128) NOT NULL, 
	created_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE, 
	UNIQUE (charge_id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_payments_user_id ON payments (user_id);
CREATE INDEX ix_payments_created_at ON payments (created_at);

CREATE TABLE IF NOT EXISTS trades (
	id INTEGER NOT NULL AUTO_INCREMENT, 
	user_id BIGINT NOT NULL, 
	symbol VARCHAR(24) NOT NULL, 
	strategy VARCHAR(16) NOT NULL, 
	side VARCHAR(4) NOT NULL, 
	qty FLOAT NOT NULL, 
	entry FLOAT NOT NULL, 
	`exit` FLOAT NOT NULL, 
	pnl FLOAT NOT NULL, 
	r FLOAT NOT NULL, 
	regime VARCHAR(16), 
	mode VARCHAR(8) NOT NULL, 
	opened_at DATETIME NOT NULL, 
	closed_at DATETIME NOT NULL, 
	PRIMARY KEY (id), 
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_trades_user_closed ON trades (user_id, closed_at);
CREATE INDEX ix_trades_closed_at ON trades (closed_at);
