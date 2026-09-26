<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Оплата подписки криптовалютой через NOWPayments (https://nowpayments.io).
 * Инвойс создаётся мини-аппом (MiniApi::pay), подтверждение — вебхуком (public/nowpayments/webhook.php).
 */
final class NowPayments
{
    private const BASE = 'https://api.nowpayments.io/v1/';

    /** @return array{id:string,invoice_url:string} и другие поля ответа NOWPayments */
    public static function createInvoice(array $params): array
    {
        $key = (string)Settings::get('nowpayments_api_key');
        if ($key === '') {
            throw new RuntimeException('Ключ NOWPayments не задан');
        }
        $r = Http::json('POST', self::BASE . 'invoice', $params, ['x-api-key: ' . $key]);
        if (empty($r['invoice_url'])) {
            throw new RuntimeException($r['message'] ?? 'NOWPayments не вернул ссылку на оплату');
        }
        return $r;
    }

    /** Ключи объекта рекурсивно по алфавиту — так же, как NOWPayments считает подпись IPN. */
    private static function sortRecursive(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'sortRecursive'], $v);
        }
        ksort($v);
        return array_map([self::class, 'sortRecursive'], $v);
    }

    /** Подпись вебхука: HMAC-SHA512(секрет, JSON с рекурсивно отсортированными ключами) в заголовке x-nowpayments-sig. */
    public static function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string)Settings::get('nowpayments_ipn_secret');
        $signature = strtolower(trim((string)$signature));
        if ($secret === '' || $signature === '' || !preg_match('/^[a-f0-9]{128}$/', $signature)) {
            return false;
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return false;
        }
        $json = json_encode(self::sortRecursive($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $expected = hash_hmac('sha512', (string)$json, $secret);
        return hash_equals($expected, $signature);
    }
}
