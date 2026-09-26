<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/** Telegram Bot API. Бот работает через webhook (public/tg/webhook.php) — без постоянного процесса. */
final class Telegram
{
    public static function call(string $method, array $params = [], ?string $token = null): mixed
    {
        $token ??= (string)Settings::get('bot_token');
        if ($token === '') {
            throw new RuntimeException('Токен Telegram-бота не задан');
        }
        $r = Http::json('POST', "https://api.telegram.org/bot$token/$method", $params);
        if (empty($r['ok'])) {
            throw new RuntimeException('Telegram: ' . ($r['description'] ?? 'ошибка'));
        }
        return $r['result'];
    }

    /** Отправка без исключений — клиент мог заблокировать бота. */
    public static function send(int|string $chatId, string $text, ?array $markup = null): bool
    {
        try {
            $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
            if ($markup) {
                $p['reply_markup'] = $markup;
            }
            self::call('sendMessage', $p);
            return true;
        } catch (\Throwable $e) {
            Log::warn("telegram send $chatId: " . $e->getMessage());
            return false;
        }
    }

    public static function webhookSecret(): string
    {
        return substr(hash_hmac('sha256', 'telegram-webhook', Env::get('SECRET_KEY')), 0, 48);
    }

    public static function appButton(): array
    {
        return ['inline_keyboard' => [[['text' => '🚀 Открыть AI Trader', 'web_app' => ['url' => Settings::get('webapp_url')]]]]];
    }

    /** Подключает webhook и кнопку меню. Вызывается при установке и при смене токена/адреса. */
    public static function setup(string $baseUrl): void
    {
        self::call('setWebhook', [
            'url' => rtrim($baseUrl, '/') . '/tg/webhook.php',
            'secret_token' => self::webhookSecret(),
            'allowed_updates' => ['message', 'pre_checkout_query'],
            'drop_pending_updates' => true,
        ]);
        $url = (string)Settings::get('webapp_url');
        if ($url !== '') {
            self::call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'AI Trader', 'web_app' => ['url' => $url]]]);
        }
    }
}
