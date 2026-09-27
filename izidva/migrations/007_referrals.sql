-- Партнёрская программа: 5-уровневая реферальная сеть.
-- Комиссия начисляется только с реальных платежей за подписку (таблица payments —
-- туда попадают только оплаты Stars/криптой за торговлю на бирже), демо-счёт бесплатный
-- и платежей не создаёт, поэтому начисления автоматически считаются только с реальных денег.

ALTER TABLE users ADD COLUMN referred_by BIGINT NULL AFTER id;
ALTER TABLE users ADD CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users (id) ON DELETE SET NULL;
CREATE INDEX ix_users_referred_by ON users (referred_by);

CREATE TABLE IF NOT EXISTS referral_earnings (
	id INTEGER NOT NULL AUTO_INCREMENT,
	beneficiary_id BIGINT NOT NULL,
	from_user_id BIGINT NOT NULL,
	level TINYINT NOT NULL,
	payment_id INTEGER NOT NULL,
	pct FLOAT NOT NULL,
	amount_usd FLOAT NOT NULL,
	paid BOOL NOT NULL DEFAULT 0,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id),
	FOREIGN KEY(beneficiary_id) REFERENCES users (id) ON DELETE CASCADE,
	FOREIGN KEY(from_user_id) REFERENCES users (id) ON DELETE CASCADE,
	FOREIGN KEY(payment_id) REFERENCES payments (id) ON DELETE CASCADE
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_referral_earnings_beneficiary ON referral_earnings (beneficiary_id, created_at);
CREATE INDEX ix_referral_earnings_payment ON referral_earnings (payment_id);
