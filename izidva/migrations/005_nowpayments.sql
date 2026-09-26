-- Оплата подписки криптовалютой через NOWPayments (в дополнение к Telegram Stars).

ALTER TABLE payments ADD COLUMN method VARCHAR(16) NOT NULL DEFAULT 'stars' AFTER stars;

-- Инвойсы NOWPayments: создаются при нажатии «Оплатить криптой», обновляются вебхуком (IPN)
-- по мере смены статуса оплаты; при status='finished' начисляется подписка (см. public/nowpayments/webhook.php).
CREATE TABLE IF NOT EXISTS crypto_invoices (
	id INTEGER NOT NULL AUTO_INCREMENT,
	user_id BIGINT NOT NULL,
	plan VARCHAR(16) NOT NULL,
	order_id VARCHAR(64) NOT NULL,
	invoice_id VARCHAR(32),
	price_amount FLOAT NOT NULL,
	price_currency VARCHAR(8) NOT NULL,
	pay_currency VARCHAR(16),
	actually_paid FLOAT,
	status VARCHAR(24) NOT NULL DEFAULT 'waiting',
	invoice_url VARCHAR(512),
	created_at DATETIME NOT NULL,
	updated_at DATETIME,
	PRIMARY KEY (id),
	UNIQUE (order_id),
	FOREIGN KEY(user_id) REFERENCES users (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_crypto_invoices_user ON crypto_invoices (user_id, created_at);
