<?php
declare(strict_types=1);

namespace App\Web;

/** Маршрутизация и JSON-ответы. Работает без mod_rewrite: /api/index.php/путь. */
final class Api
{
    public static function path(): string
    {
        $p = $_SERVER['PATH_INFO'] ?? '';
        if ($p === '' && isset($_GET['r'])) {
            $p = (string)$_GET['r'];
        }
        return '/' . trim($p, '/');
    }

    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        $d = json_decode($raw ?: '[]', true);
        return is_array($d) ? $d : [];
    }

    public static function fail(int $code, string $message): never
    {
        throw new ApiError($message, $code);
    }

    public static function send(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** Выполнить обработчик и отдать JSON, превращая ошибки в понятные ответы. */
    public static function run(callable $handler): void
    {
        try {
            self::send($handler());
        } catch (ApiError $e) {
            self::send(['detail' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            \App\Log::error('API ' . self::path() . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::send(['detail' => 'Внутренняя ошибка сервера'], 500);
        }
    }

    public static function str(array $b, string $k, int $min = 0, int $max = 10000): string
    {
        $v = trim((string)($b[$k] ?? ''));
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
            self::fail(400, "Поле $k: от $min до $max символов");
        }
        return $v;
    }

    public static function iso(?string $dt): ?string
    {
        return $dt ? str_replace(' ', 'T', $dt) : null;
    }
}
