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
        'bot_username' => ['str', '', 'Юзернейм бота (без @)', 'Telegram', false, 'для реферальных ссылок, например izidva_bot'],
        'webapp_url' => ['str', '', 'Адрес мини-аппа (HTTPS)', 'Telegram', false, 'https://ваш-домен/app/'],
        'support_contact' => ['str', '', 'Контакт поддержки', 'Telegram', false, 'например @support'],
        'anthropic_api_key' => ['str', '', 'Ключ Anthropic (Claude)', 'ИИ', true, ''],
        'ai_model' => ['str', 'claude-opus-5', 'Модель ИИ', 'ИИ', false, 'claude-opus-5 или дешевле claude-sonnet-5'],
        'ai_interval_min' => ['int', 30, 'Интервал анализа монеты, мин', 'ИИ', false, ''],
        'vision_enabled' => ['bool', false, 'Vision-разбор графика (доп. расходы на ИИ)', 'ИИ', false,
            'раз в vision_interval_min ИИ смотрит на картинку графика монеты и пишет короткую заметку трейдеру — не влияет на сделки автоматически'],
        'vision_interval_min' => ['int', 60, 'Интервал vision-разбора монеты, мин', 'ИИ', false, ''],
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
        'kill_switch' => ['bool', false, 'АВАРИЙНАЯ ОСТАНОВКА: закрыть все позиции всех клиентов и не торговать', 'Торговля', false, ''],
        'signal_enabled' => ['bool', false, 'Сигналы из Telegram: принимать и исполнять', 'Сигналы', false, ''],
        'signal_allowed_ids' => ['list', [], 'Сигналы: разрешённые источники — Telegram ID (ваш, для пересылки боту) и/или ID канала (-100…)', 'Сигналы', false, ''],
        'signal_real_enabled' => ['bool', false, 'Сигналы: исполнять и на РЕАЛЬНЫХ счетах клиентов (по умолчанию только демо)', 'Сигналы', false, ''],
        'signal_max_chase_pct' => ['float', 0.3, 'Сигналы: допуск ухода цены от зоны входа, % (дальше — сигнал пропускается)', 'Сигналы', false, ''],
        'strategy_grid_enabled' => ['bool', true, 'Стратегия «сетка» разрешена на платформе (только в подтверждённом боковике)', 'Торговля', false, ''],
        'strategy_trend_enabled' => ['bool', true, 'Стратегия «тренд 1h» разрешена на платформе', 'Торговля', false, ''],
        'strategy_breakout_enabled' => ['bool', false, 'Стратегия «пробой канала 4h» (включать только после бэктеста: php bin/backtest.php МОНЕТА --strategies=breakout)', 'Торговля', false, ''],
        'strategy_liquidation_enabled' => ['bool', false, 'Стратегия «отскок после ликвидаций» (на истории не проверяется — по умолчанию выключена)', 'Торговля', false, ''],
        'price_month_usd' => ['float', 15.0, 'Аренда бота на 1 месяц, $', 'Тарифы', false, ''],
        'price_half_year_usd' => ['float', 50.0, 'Аренда бота на 6 месяцев, $', 'Тарифы', false, ''],
        'price_year_usd' => ['float', 90.0, 'Аренда бота на 1 год, $', 'Тарифы', false, ''],
        'stars_usd_rate' => ['float', 0.013, 'Курс 1 ⭐ в $ (для пересчёта цены в звёзды)', 'Тарифы', false, ''],
        'nowpayments_enabled' => ['bool', false, 'Оплата криптовалютой (NOWPayments) включена', 'NOWPayments (крипта)', false,
            'показывает клиенту вариант «Оплатить криптой» рядом со звёздами'],
        'nowpayments_api_key' => ['str', '', 'API-ключ NOWPayments', 'NOWPayments (крипта)', true, 'личный кабинет NOWPayments → API keys'],
        'nowpayments_ipn_secret' => ['str', '', 'IPN Secret Key NOWPayments', 'NOWPayments (крипта)', true,
            'личный кабинет → Settings → IPN — нужен для проверки подписи вебхука'],
        'referral_program_enabled' => ['bool', true, 'Партнёрская программа включена', 'Партнёрка', false,
            'начисление % рефереру при оплате подписки приглашённым — только с реальных оплат, демо-счёт бесплатный'],
        'referral_pct_l1' => ['float', 20.0, 'Уровень 1 (прямые приглашения), %', 'Партнёрка', false, ''],
        'referral_pct_l2' => ['float', 5.0, 'Уровень 2, %', 'Партнёрка', false, ''],
        'referral_pct_l3' => ['float', 3.0, 'Уровень 3, %', 'Партнёрка', false, ''],
        'referral_pct_l4' => ['float', 2.0, 'Уровень 4, %', 'Партнёрка', false, ''],
        'referral_pct_l5' => ['float', 1.0, 'Уровень 5, %', 'Партнёрка', false, ''],
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
                if ($row['value'] === '') {
                    $out[$k] = '';
                    continue;
                }
                // Битое значение (например, после смены APP_KEY) не должно ронять весь /overview или демон —
                // считаем поле «не задано» и один раз пишем в лог, вместо необработанного исключения на каждый запрос.
                try {
                    $out[$k] = Crypto::decrypt($row['value']);
                } catch (\Throwable $e) {
                    $out[$k] = '';
                    Log::warn("Settings: не удалось расшифровать «{$k}» — APP_KEY изменился? Поле сброшено, впишите заново. " . $e->getMessage());
                }
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

    /** Аренда бота: 1 месяц / 6 месяцев / 1 год. Цены в $ задаются в админке, звёзды считаются по курсу stars_usd_rate. */
    public static function plans(): array
    {
        $s = self::all();
        $rate = (float)$s['stars_usd_rate'] ?: 0.013;
        $mk = fn(string $code, string $title, int $days, float $usd) => ['code' => $code, 'title' => $title, 'days' => $days,
            'usd' => round($usd, 2), 'stars' => max(1, (int)ceil($usd / $rate))];
        return [
            $mk('month', '1 месяц', 30, (float)$s['price_month_usd']),
            $mk('half_year', '6 месяцев', 182, (float)$s['price_half_year_usd']),
            $mk('year', '1 год', 365, (float)$s['price_year_usd']),
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
