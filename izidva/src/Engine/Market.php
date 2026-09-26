<?php
declare(strict_types=1);

namespace App\Engine;

use App\Bybit;
use App\Log;

/**
 * Общие рыночные данные для всех клиентов: цены (REST раз в несколько секунд), свечи 5м (раз в минуту),
 * ликвидации (WebSocket allLiquidation). Одна подписка на монету обслуживает всех клиентов.
 */
final class Market
{
    public const WS_URL = 'wss://stream.bybit.com/v5/public/linear';

    /** @var array<string,SymbolFeed> */
    public array $feeds = [];
    /** @var array<string,Instrument> */
    public array $instruments = [];
    private Bybit $api;
    private ?WsClient $ws = null;
    private float $wsCheck = 0.0;
    private float $wsPing = 0.0;
    private float $tickersTs = 0.0;
    private float $instrumentsTs = 0.0;

    public function __construct(public array $symbols, private string $wsUrl = self::WS_URL)
    {
        foreach ($symbols as $s) {
            $this->feeds[$s] = new SymbolFeed($s);
        }
        $this->api = new Bybit();
    }

    public function loadInstruments(): void
    {
        foreach ($this->symbols as $s) {
            if (isset($this->instruments[$s])) {
                continue;
            }
            try {
                $r = $this->api->get('/v5/market/instruments-info', ['category' => 'linear', 'symbol' => $s]);
                if (!empty($r['list'][0])) {
                    $this->instruments[$s] = Instrument::fromBybit($r['list'][0]);
                } else {
                    Log::warn("$s: инструмент не найден на Bybit");
                }
            } catch (\Throwable $e) {
                Log::warn("$s: инструмент не загрузился: " . $e->getMessage());
            }
        }
    }

    public function pollTickers(): void
    {
        try {
            $r = $this->api->get('/v5/market/tickers', ['category' => 'linear']);
        } catch (\Throwable $e) {
            Log::warn('tickers: ' . $e->getMessage());
            return;
        }
        $now = microtime(true);
        foreach ($r['list'] ?? [] as $t) {
            $feed = $this->feeds[$t['symbol']] ?? null;
            if ($feed && !empty($t['lastPrice'])) {
                $feed->addPrice((float)$t['lastPrice'], $now);
                if (($t['fundingRate'] ?? '') !== '') {
                    $feed->funding = (float)$t['fundingRate'];
                }
            }
        }
        $this->tickersTs = $now;
    }

    public function refreshKlines(string $symbol): void
    {
        $r = $this->api->get('/v5/market/kline', ['category' => 'linear', 'symbol' => $symbol, 'interval' => '5', 'limit' => 300]);
        $feed = $this->feeds[$symbol];
        $feed->klines = array_reverse($r['list'] ?? []);
        $feed->klinesTs = microtime(true);
    }

    /** Обработка ликвидаций: S=Buy — ликвидирован лонг. */
    public function onWsMessage(string $raw): void
    {
        $m = json_decode($raw, true);
        if (!is_array($m) || !str_starts_with((string)($m['topic'] ?? ''), 'allLiquidation.')) {
            return;
        }
        $now = microtime(true);
        foreach ($m['data'] ?? [] as $d) {
            $feed = $this->feeds[$d['s'] ?? ''] ?? null;
            if ($feed) {
                $feed->addLiq($d['S'] === 'Buy' ? 'long' : 'short', (float)$d['v'] * (float)$d['p'], $now);
            }
        }
    }

    private function ensureWs(): void
    {
        if ($this->ws && $this->ws->connected()) {
            if (microtime(true) - $this->wsPing > 20) {
                try {
                    $this->ws->send('{"op":"ping"}');
                } catch (\Throwable) {
                }
                $this->wsPing = microtime(true);
            }
            return;
        }
        if (microtime(true) - $this->wsCheck < 60) {
            return;
        }
        $this->wsCheck = microtime(true);
        try {
            $this->ws = new WsClient($this->wsUrl);
            $this->ws->connect();
            foreach (array_chunk($this->symbols, 10) as $chunk) {
                $this->ws->send(json_encode(['op' => 'subscribe', 'args' => array_map(fn($s) => "allLiquidation.$s", $chunk)]));
            }
            $this->wsPing = microtime(true);
            Log::info('WebSocket ликвидаций Bybit подключён');
        } catch (\Throwable $e) {
            $this->ws = null;
            Log::warn('WebSocket ликвидаций недоступен (' . $e->getMessage() . ') — повтор через минуту');
        }
    }

    /** Один такт: вызывается из цикла демона каждые ~3 секунды. */
    public function tick(): void
    {
        if (count($this->instruments) < count($this->symbols) && microtime(true) - $this->instrumentsTs > 60) {
            $this->instrumentsTs = microtime(true);          // при сбое повторяем не чаще раза в минуту
            $this->loadInstruments();
        }
        $this->ensureWs();
        if ($this->ws) {
            foreach ($this->ws->read() as $msg) {
                $this->onWsMessage($msg);
            }
        }
        if (microtime(true) - $this->tickersTs >= 3) {
            $this->pollTickers();
        }
        $budget = 3;                                         // не больше 3 запросов свечей за такт
        foreach ($this->symbols as $s) {
            if ($budget > 0 && microtime(true) - $this->feeds[$s]->klinesTs > 60) {
                $budget--;
                try {
                    $this->refreshKlines($s);
                } catch (\Throwable $e) {
                    $this->feeds[$s]->klinesTs = microtime(true) - 30;     // не долбим API при сбое
                    Log::warn("$s: свечи не загрузились: " . $e->getMessage());
                }
            }
        }
    }

    public function prices(): array
    {
        $out = [];
        foreach ($this->feeds as $s => $f) {
            if ($f->price) {
                $out[$s] = $f->price;
            }
        }
        return $out;
    }

    public function wsConnected(): bool
    {
        return $this->ws !== null && $this->ws->connected();
    }
}
