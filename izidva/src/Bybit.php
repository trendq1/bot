<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Bybit API v5 (REST). Подпись: HMAC_SHA256(secret, timestamp + apiKey + recvWindow + query|body).
 * https://bybit-exchange.github.io/docs/v5/guide
 */
final class Bybit
{
    private const RECV = '10000';

    public function __construct(
        private string $apiKey = '',
        private string $apiSecret = '',
        private string $mode = 'live',          // live | demo
    ) {}

    private function base(): string
    {
        return $this->mode === 'demo' ? 'https://api-demo.bybit.com' : 'https://api.bybit.com';
    }

    public function get(string $path, array $params = [], bool $signed = false): array
    {
        $query = http_build_query($params);
        $headers = $signed ? $this->sign($query) : [];
        $url = $this->base() . $path . ($query !== '' ? '?' . $query : '');
        return $this->unwrap(Http::json('GET', $url, null, $headers));
    }

    public function post(string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        [, $raw] = Http::request('POST', $this->base() . $path,
            array_merge(['Content-Type: application/json'], $this->sign($json)), $json);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Bybit: некорректный ответ');
        }
        return $this->unwrap($data);
    }

    private function sign(string $payload): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Bybit: ключ не задан');
        }
        $ts = (string)(int)(microtime(true) * 1000);
        $sig = hash_hmac('sha256', $ts . $this->apiKey . self::RECV . $payload, $this->apiSecret);
        return ["X-BAPI-API-KEY: {$this->apiKey}", "X-BAPI-TIMESTAMP: $ts", "X-BAPI-RECV-WINDOW: " . self::RECV,
            "X-BAPI-SIGN: $sig", 'X-BAPI-SIGN-TYPE: 2'];
    }

    private function unwrap(array $r): array
    {
        if ((int)($r['retCode'] ?? -1) !== 0) {
            throw new BybitError((int)($r['retCode'] ?? -1), 'Bybit: ' . ($r['retMsg'] ?? 'ошибка'));
        }
        return $r['result'] ?? [];
    }
}
