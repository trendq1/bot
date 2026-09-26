-- Роль администратора (admin | trader), ручной режим клиента и очередь ручных ордеров трейдера.

ALTER TABLE admin_users ADD COLUMN role VARCHAR(16) NOT NULL DEFAULT 'admin' AFTER password_hash;

-- manual_mode = 1: автостратегии (сетка/тренд/ликвидации) не открывают новых сделок клиенту,
-- сделками управляет только трейдер вручную из админ-панели. Существующие сетки/позиции движок
-- по-прежнему сопровождает (тейк/стоп, закрытие) — переключение не бросает клиента посреди сделки.
ALTER TABLE bot_settings ADD COLUMN manual_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER trading_mode;

-- Очередь ручных ордеров: трейдер создаёт строку в админке, демон (Manager::processManualOrders)
-- исполняет её через Worker клиента и обновляет статус.
-- status: pending -> open (лимитка выставлена, ждёт исполнения) -> filled | cancelled | error
--                  -> done (рыночный ордер исполнен сразу)
-- cancel_requested — трейдер попросил снять ещё не исполнённую лимитку.
CREATE TABLE IF NOT EXISTS manual_orders (
	id INTEGER NOT NULL AUTO_INCREMENT,
	user_id BIGINT NOT NULL,
	symbol VARCHAR(24) NOT NULL,
	side VARCHAR(4) NOT NULL,
	order_type VARCHAR(8) NOT NULL,
	qty FLOAT NOT NULL,
	price FLOAT,
	stop_loss FLOAT,
	take_profit FLOAT,
	leverage INTEGER,
	status VARCHAR(16) NOT NULL DEFAULT 'pending',
	error TEXT,
	created_by VARCHAR(64) NOT NULL,
	created_at DATETIME NOT NULL,
	done_at DATETIME,
	PRIMARY KEY (id),
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_manual_orders_status ON manual_orders (status, id);
CREATE INDEX ix_manual_orders_user ON manual_orders (user_id, created_at);
