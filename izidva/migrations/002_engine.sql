-- Связь веб-части (PHP-FPM) и торгового демона (bin/daemon.php).

-- Живое состояние процесса клиента: статус, сетки, позиции — пишет демон, читает веб.
CREATE TABLE IF NOT EXISTS worker_state (
	user_id BIGINT NOT NULL,
	status TEXT,
	equity DOUBLE,
	day_pnl_pct DOUBLE,
	live JSON,
	updated_at DATETIME NOT NULL,
	PRIMARY KEY (user_id),
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Команды из админ-панели демону: restart_user, force_ai, restart_engine, reload_settings.
CREATE TABLE IF NOT EXISTS engine_commands (
	id INTEGER NOT NULL AUTO_INCREMENT,
	cmd VARCHAR(32) NOT NULL,
	arg VARCHAR(64),
	created_at DATETIME NOT NULL,
	done_at DATETIME,
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_engine_commands_done ON engine_commands (done_at);

-- Пульс демона и общее состояние рынка (одна строка id=1).
CREATE TABLE IF NOT EXISTS engine_status (
	id INTEGER NOT NULL,
	heartbeat_at DATETIME NOT NULL,
	started_at DATETIME,
	pid INTEGER,
	state JSON,
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
