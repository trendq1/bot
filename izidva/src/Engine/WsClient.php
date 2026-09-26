<?php
declare(strict_types=1);

namespace App\Engine;

use RuntimeException;

/**
 * Минимальный WebSocket-клиент (RFC 6455) без внешних библиотек.
 * Нужен только для потока ликвидаций Bybit (в REST его нет). Неблокирующее чтение — вызывать в цикле.
 */
final class WsClient
{
    /** @var resource|null */
    private $sock = null;
    private string $buf = '';
    private string $fragments = '';

    public function __construct(private string $url) {}

    public function connect(int $timeout = 10): void
    {
        $u = parse_url($this->url);
        $tls = ($u['scheme'] ?? 'wss') === 'wss';
        $host = $u['host'];
        $port = $u['port'] ?? ($tls ? 443 : 80);
        $path = ($u['path'] ?? '/') . (isset($u['query']) ? '?' . $u['query'] : '');
        $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $sock = @stream_socket_client(($tls ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $err, $timeout,
            STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            throw new RuntimeException("WebSocket $host: $err ($errno)");
        }
        stream_set_timeout($sock, $timeout);
        $key = base64_encode(random_bytes(16));
        fwrite($sock, "GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $head = '';
        while (!str_contains($head, "\r\n\r\n")) {
            $chunk = fread($sock, 1024);
            if ($chunk === '' || $chunk === false) {
                fclose($sock);
                throw new RuntimeException('WebSocket: сервер не ответил на рукопожатие');
            }
            $head .= $chunk;
        }
        [$headers, $rest] = explode("\r\n\r\n", $head, 2);
        if (!preg_match('#^HTTP/1\.1 101#', $headers)) {
            fclose($sock);
            throw new RuntimeException('WebSocket: ' . strtok($headers, "\r\n"));
        }
        $expected = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/Sec-WebSocket-Accept:\s*(\S+)/i', $headers, $m) || $m[1] !== $expected) {
            fclose($sock);
            throw new RuntimeException('WebSocket: неверный ответ рукопожатия');
        }
        stream_set_blocking($sock, false);
        $this->sock = $sock;
        $this->buf = $rest;
    }

    public function connected(): bool
    {
        return $this->sock !== null && !feof($this->sock);
    }

    public function close(): void
    {
        if ($this->sock) {
            @fwrite($this->sock, $this->frame('', 0x8));
            @fclose($this->sock);
        }
        $this->sock = null;
        $this->buf = '';
    }

    public function send(string $text): void
    {
        if (!$this->sock) {
            throw new RuntimeException('WebSocket не подключён');
        }
        $frame = $this->frame($text, 0x1);
        stream_set_blocking($this->sock, true);
        $ok = fwrite($this->sock, $frame);
        stream_set_blocking($this->sock, false);
        if ($ok === false) {
            $this->close();
            throw new RuntimeException('WebSocket: запись не удалась');
        }
    }

    /** Клиентские кадры обязаны быть замаскированы. */
    private function frame(string $payload, int $opcode): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len < 126) {
            $head .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $len);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $len);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        return $head . $mask . $masked;
    }

    /** Все целиком полученные текстовые сообщения (без ожидания). @return string[] */
    public function read(): array
    {
        if (!$this->sock) {
            return [];
        }
        while (($chunk = fread($this->sock, 65536)) !== false && $chunk !== '') {
            $this->buf .= $chunk;
        }
        if (feof($this->sock)) {
            $this->close();
        }
        $out = [];
        while (($msg = $this->parseFrame()) !== null) {
            [$fin, $op, $data] = $msg;
            if ($op === 0x9) {                               // ping -> pong
                @fwrite($this->sock, $this->frame($data, 0xA));
            } elseif ($op === 0x8) {
                $this->close();
                break;
            } elseif ($op === 0x1 || $op === 0x0) {
                $this->fragments .= $data;
                if ($fin) {
                    $out[] = $this->fragments;
                    $this->fragments = '';
                }
            }
        }
        return $out;
    }

    private function parseFrame(): ?array
    {
        $b = $this->buf;
        if (strlen($b) < 2) {
            return null;
        }
        $fin = (ord($b[0]) & 0x80) !== 0;
        $op = ord($b[0]) & 0x0F;
        $masked = (ord($b[1]) & 0x80) !== 0;
        $len = ord($b[1]) & 0x7F;
        $pos = 2;
        if ($len === 126) {
            if (strlen($b) < 4) {
                return null;
            }
            $len = unpack('n', substr($b, 2, 2))[1];
            $pos = 4;
        } elseif ($len === 127) {
            if (strlen($b) < 10) {
                return null;
            }
            $len = unpack('J', substr($b, 2, 8))[1];
            $pos = 10;
        }
        $mask = '';
        if ($masked) {
            $mask = substr($b, $pos, 4);
            $pos += 4;
        }
        if (strlen($b) < $pos + $len) {
            return null;
        }
        $data = substr($b, $pos, $len);
        if ($masked) {
            for ($i = 0; $i < $len; $i++) {
                $data[$i] = $data[$i] ^ $mask[$i % 4];
            }
        }
        $this->buf = substr($b, $pos + $len);
        return [$fin, $op, $data];
    }
}
