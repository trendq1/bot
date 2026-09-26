<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

final class Http
{
    /** @return array{0:int,1:string} [HTTP-код, тело] */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'izidva-trader/1.0',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            throw new RuntimeException("HTTP $method $url: $err");
        }
        return [$code, (string)$resp];
    }

    public static function json(string $method, string $url, ?array $payload = null, array $headers = [], int $timeout = 15): array
    {
        $headers[] = 'Content-Type: application/json';
        [$code, $body] = self::request($method, $url, $headers,
            $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE), $timeout);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException("HTTP $code: некорректный ответ " . mb_substr($body, 0, 200));
        }
        return $data;
    }
}
