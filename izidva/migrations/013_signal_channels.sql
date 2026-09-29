-- Управление парсингом сигналов: каналы-источники, админы (кто может пересылать боту), примеры постов.
-- source_key: id канала (-100…), 'manual' (личные пересылки админа без определяемого канала) или 'ext:<имя>' (внешний ридер).
CREATE TABLE IF NOT EXISTS signal_channels (
	id INTEGER NOT NULL AUTO_INCREMENT,
	name VARCHAR(120) NOT NULL,
	source_key VARCHAR(64) NOT NULL,
	enabled TINYINT(1) NOT NULL DEFAULT 0,
	mode VARCHAR(8) NOT NULL DEFAULT 'demo',
	risk_mult DOUBLE NOT NULL DEFAULT 1,
	max_chase_pct DOUBLE,
	parser_config JSON,
	notes TEXT,
	posts_seen INTEGER NOT NULL DEFAULT 0,
	last_post_at DATETIME,
	created_at DATETIME NOT NULL,
	created_by VARCHAR(32),
	PRIMARY KEY (id),
	UNIQUE KEY uq_signal_channel_key (source_key)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS signal_admins (
	id INTEGER NOT NULL AUTO_INCREMENT,
	tg_id BIGINT NOT NULL,
	name VARCHAR(120),
	enabled TINYINT(1) NOT NULL DEFAULT 1,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	UNIQUE KEY uq_signal_admin_tg (tg_id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Примеры постов канала: signal — должен разбираться как сигнал, update — как обновление, noise — не должен разбираться.
CREATE TABLE IF NOT EXISTS signal_examples (
	id INTEGER NOT NULL AUTO_INCREMENT,
	channel_id INTEGER NOT NULL,
	kind VARCHAR(8) NOT NULL DEFAULT 'signal',
	text TEXT NOT NULL,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	FOREIGN KEY(channel_id) REFERENCES signal_channels (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_signal_examples_channel ON signal_examples (channel_id);

ALTER TABLE signals ADD COLUMN channel_id INTEGER NULL;
ALTER TABLE signals ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'signal';
ALTER TABLE signals ADD COLUMN action VARCHAR(12) NULL;
