<?php
declare(strict_types=1);

namespace App;

/**
 * Рабочие настройки: значение по умолчанию -> таблица app_settings (секреты — зашифрованы).
 * Редактируются в админ-панели.
 */
final class Settings
{
    /** key => [type, default, label, group, secret, help] */
    public const FIELDS = [
        'bot_token' => ['str', '', 'Токен Telegram-бота', 'Telegram', true, 'от @BotFather'],
        'webapp_url' => ['str', '', 'Адрес мини-аппа (HTTPS)', 'Telegram', false, 'https://ваш-домен/app/'],
        'support_contact' => ['str', '', 'Контакт поддержки', 'Telegram', false, 'например @support'],
        'anthropic_api_key' => ['str', '', 'Ключ Anthropic (Claude)', 'ИИ', true, ''],
        'ai_model' => ['str', 'claude-opus-5', 'Модель ИИ', 'ИИ', false, 'claude-opus-5 или дешевле claude-sonnet-5'],
        'ai_interval_min' => ['int', 30, 'Интервал анализа монеты, мин', 'ИИ', false, ''],
        'referral_link' => ['str', 'https://www.bybit.com/invite?ref=YOURCODE', 'Реферальная ссылка Bybit', 'Bybit', false, ''],
        'require_referral' => ['bool', true, 'Реальный счёт только для рефералов', 'Bybit', false, ''],
        'affiliate_api_key' => ['str', '', 'Партнёрский API-ключ Bybit', 'Bybit', true, 'read-only, для проверки рефералов'],
        'affiliate_api_secret' => ['str', '', 'Партнёрский API-секрет Bybit', 'Bybit', true, ''],
        'symbols' => ['list', ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'XRPUSDT', 'DOGEUSDT', 'SUIUSDT', 'AVAXUSDT', 'LINKUSDT', 'ADAUSDT', 'LTCUSDT'],
            'Доступные монеты', 'Торговля', false, 'через запятую'],
        'default_symbols' => ['list', ['BTCUSDT', 'ETHUSDT', 'SOLUSDT'], 'Монеты новых клиентов', 'Торговля', false, ''],
        'paper_start_balance' => ['float', 1000.0, 'Стартовый демо-баланс, $', 'Торговля', false, ''],
        'engine_enabled' => ['bool', true, 'Торговый движок включён', 'Торговля', false, ''],
        'maintenance' => ['bool', false, 'Техобслуживание (запрет запуска ботов)', 'Торговля', false, ''],
        'price_week_stars' => ['int', 250, 'Цена 7 дней, ⭐', 'Тарифы', false, ''],
        'price_month_stars' => ['int', 750, 'Цена 30 дней, ⭐', 'Тарифы', false, ''],
        'price_quarter_stars' => ['int', 1900, 'Цена 90 дней, ⭐', 'Тарифы', false, ''],
        'stars_usd_rate' => ['float', 0.013, 'Курс 1 ⭐ в $ (для учёта доходов)', 'Тарифы', false, ''],
    ];

    private static ?array $cache = null;

    public static function coerce(string $key, mixed $v): mixed
    {
        [$type, $default] = self::FIELDS[$key];
        if ($v === null) {
            return $default;
        }
        return match ($type) {
            'bool' => is_bool($v) ? $v : in_array(strtolower(trim((string)$v)), ['1', 'true', 'yes', 'on', 'да'], true),
            'int' => (int)$v,
            'float' => (float)$v,
            'list' => array_values(array_filter(array_map(fn($x) => strtoupper(trim((string)$x)),
                is_array($v) ? $v : explode(',', (string)$v)))),
            default => trim((string)$v),
        };
    }

    public static function all(bool $fresh = false): array
    {
        if (self::$cache !== null && !$fresh) {
            return self::$cache;
        }
        $out = [];
        foreach (self::FIELDS as $k => $f) {
            $out[$k] = $f[1];
        }
        foreach (DB::all('SELECT `key`, value FROM app_settings') as $row) {
            $k = $row['key'];
            if (!isset(self::FIELDS[$k])) {
                continue;
            }
            if (self::FIELDS[$k][4]) {
                $out[$k] = $row['value'] === '' ? '' : Crypto::decrypt($row['value']);
            } else {
                $out[$k] = self::coerce($k, json_decode($row['value'], true));
            }
        }
        return self::$cache = $out;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    public static function save(array $values): void
    {
        foreach ($values as $k => $v) {
            if (!isset(self::FIELDS[$k])) {
                continue;
            }
            $v = self::coerce($k, $v);
            $stored = self::FIELDS[$k][4] ? ($v === '' ? '' : Crypto::encrypt((string)$v)) : json_encode($v, JSON_UNESCAPED_UNICODE);
            DB::q('INSERT INTO app_settings (`key`, value, updated_at) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)', [$k, $stored, DB::now()]);
        }
        self::$cache = null;
    }

    public static function plans(): array
    {
        $s = self::all();
        return [
            ['code' => 'week', 'title' => '7 дней', 'days' => 7, 'stars' => $s['price_week_stars']],
            ['code' => 'month', 'title' => '30 дней', 'days' => 30, 'stars' => $s['price_month_stars']],
            ['code' => 'quarter', 'title' => '90 дней', 'days' => 90, 'stars' => $s['price_quarter_stars']],
        ];
    }

    public static function plan(string $code): ?array
    {
        foreach (self::plans() as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }
        return null;
    }
}
