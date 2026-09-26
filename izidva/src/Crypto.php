<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/** Шифрование ключей, пароли админов, сессии, проверка подписи Telegram Mini App. */
final class Crypto
{
    private static function key(): string
    {
        $k = base64_decode(Env::get('APP_KEY'), true);
        if ($k === false || strlen($k) !== 32) {
            throw new RuntimeException('APP_KEY не задан — запустите migrate.php');
        }
        return $k;
    }

    public static function newKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** AES-256-GCM: результат = base64(iv | tag | шифр). */
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $enc);
    }

    public static function decrypt(string $token): string
    {
        $raw = base64_decode($token, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Повреждённые зашифрованные данные');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('Не удалось расшифровать — APP_KEY изменился?');
        }
        return $plain;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function checkPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function sessionToken(int $adminId, int $ttl = 43200): string
    {
        $body = $adminId . '.' . (time() + $ttl);
        return $body . '.' . hash_hmac('sha256', $body, Env::get('SECRET_KEY'));
    }

    public static function readSession(?string $token): ?int
    {
        if (!$token || substr_count($token, '.') !== 2 || Env::get('SECRET_KEY') === '') {
            return null;
        }
        [$id, $exp, $sig] = explode('.', $token);
        if (!hash_equals(hash_hmac('sha256', "$id.$exp", Env::get('SECRET_KEY')), $sig) || (int)$exp < time()) {
            return null;
        }
        return (int)$id;
    }

    /**
     * Проверка Telegram.WebApp.initData.
     * https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    public static function telegramUser(string $initData, string $botToken, int $maxAge = 86400): ?array
    {
        if ($initData === '' || $botToken === '') {
            return null;
        }
        parse_str($initData, $pairs);
        $hash = $pairs['hash'] ?? '';
        unset($pairs['hash']);
        if ($hash === '') {
            return null;
        }
        ksort($pairs);
        $check = implode("\n", array_map(fn($k, $v) => "$k=$v", array_keys($pairs), $pairs));
        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        if (!hash_equals(hash_hmac('sha256', $check, $secret), $hash)) {
            return null;
        }
        if ($maxAge && time() - (int)($pairs['auth_date'] ?? 0) > $maxAge) {
            return null;
        }
        $user = json_decode($pairs['user'] ?? '', true);
        return is_array($user) && isset($user['id']) ? $user : null;
    }
}
