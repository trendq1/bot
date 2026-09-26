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

    /** Кнопки нижнего меню бота (админ-панель → «Меню»): каждая своей строкой под сообщением. */
    public static function menuButtons(): array
    {
        $rows = [];
        foreach (\App\DB::all('SELECT title, url FROM bot_menu_buttons WHERE enabled = 1 ORDER BY sort_order, id') as $b) {
            $rows[] = [['text' => $b['title'], 'url' => $b['url']]];
        }
        return $rows;
    }

    /** /start и /menu: кнопка мини-аппа (если задан адрес) + кнопки, добавленные админом. */
    public static function homeKeyboard(): ?array
    {
        $rows = [];
        if (Settings::get('webapp_url') !== '') {
            $rows[] = [['text' => '🚀 Открыть AI Trader', 'web_app' => ['url' => Settings::get('webapp_url')]]];
        }
        $rows = array_merge($rows, self::menuButtons());
        return $rows ? ['inline_keyboard' => $rows] : null;
    }

    /** Отправка фото (для рассылки с картинкой) — caption поддерживает parse_mode HTML. */
    public static function sendPhoto(int|string $chatId, string $filePath, string $caption = '', ?array $markup = null): bool
    {
        try {
            $token = (string)Settings::get('bot_token');
            if ($token === '') {
                throw new RuntimeException('Токен Telegram-бота не задан');
            }
            $fields = ['chat_id' => (string)$chatId, 'caption' => $caption, 'parse_mode' => 'HTML',
                'photo' => new \CURLFile($filePath)];
            if ($markup) {
                $fields['reply_markup'] = json_encode($markup, JSON_UNESCAPED_UNICODE);
            }
            $r = Http::postMultipart("https://api.telegram.org/bot$token/sendPhoto", $fields);
            if (empty($r['ok'])) {
                throw new RuntimeException($r['description'] ?? 'ошибка');
            }
            return true;
        } catch (\Throwable $e) {
            Log::warn("telegram sendPhoto $chatId: " . $e->getMessage());
            return false;
        }
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
