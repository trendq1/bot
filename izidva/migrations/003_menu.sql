-- Кнопки в нижнем меню бота (админ-панель → «Меню»): название + ссылка, показываются под /start и /menu.
CREATE TABLE IF NOT EXISTS bot_menu_buttons (
	id INTEGER NOT NULL AUTO_INCREMENT,
	title VARCHAR(64) NOT NULL,
	url VARCHAR(512) NOT NULL,
	sort_order INTEGER NOT NULL DEFAULT 0,
	enabled TINYINT(1) NOT NULL DEFAULT 1,
	created_at DATETIME NOT NULL,
	PRIMARY KEY (id)
)ENGINE=InnoDB CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE INDEX ix_bot_menu_buttons_sort ON bot_menu_buttons (sort_order);
