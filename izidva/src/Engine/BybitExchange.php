<?php
declare(strict_types=1);

namespace App\Engine;

use App\Bybit;
use App\BybitError;
use App\Settings;
use RuntimeException;

/** Реальные ордера на Bybit (USDT Perpetual, one-way режим): demo — демо-счёт, live — реальный. */
final class BybitExchange implements ExchangeInterface
{
    private const LEVERAGE_NOT_MODIFIED = 110043;

    private Bybit $api;

    public function __construct(string $key, string $secret, private string $mode)
    {
        $this->api = new Bybit($key, $secret, $mode);
    }

    public function mode(): string { return $this->mode; }

    public function prepare(): void
    {
        try {
            $this->api->post('/v5/position/switch-mode', ['category' => 'linear', 'coin' => 'USDT', 'mode' => 0]);
        } catch (BybitError) {
            // уже one-way или есть открытые позиции
        }
    }

    public function equity(): float
    {
        $r = $this->api->get('/v5/account/wallet-balance', ['accountType' => 'UNIFIED'], true);
        return (float)($r['list'][0]['totalEquity'] ?? 0);
    }

    public function positions(): array
    {
        $r = $this->api->get('/v5/position/list', ['category' => 'linear', 'settleCoin' => 'USDT'], true);
        $out = [];
        foreach ($r['list'] ?? [] as $p) {
            if ((float)$p['size'] > 0) {
                $out[$p['symbol']] = ['side' => $p['side'], 'qty' => (float)$p['size'], 'entry' => (float)$p['avgPrice']];
            }
        }
        return $out;
    }

    public function setLeverage(string $symbol, int $leverage): void
    {
        try {
            $this->api->post('/v5/position/set-leverage', ['category' => 'linear', 'symbol' => $symbol,
                'buyLeverage' => (string)$leverage, 'sellLeverage' => (string)$leverage]);
        } catch (BybitError $e) {
            if ($e->retCode !== self::LEVERAGE_NOT_MODIFIED) {
                throw $e;
            }
        }
    }

    public function placeLimit(string $symbol, string $side, string $qty, string $price, string $linkId, bool $reduceOnly = false): void
    {
        $this->api->post('/v5/order/create', ['category' => 'linear', 'symbol' => $symbol, 'side' => $side,
            'orderType' => 'Limit', 'qty' => $qty, 'price' => $price, 'timeInForce' => 'GTC', 'orderLinkId' => $linkId,
            'reduceOnly' => $reduceOnly, 'positionIdx' => 0]);
    }

    public function placeMarket(string $symbol, string $side, string $qty, ?string $stop = null, ?string $take = null, bool $reduceOnly = false): void
    {
        $body = ['category' => 'linear', 'symbol' => $symbol, 'side' => $side, 'orderType' => 'Market', 'qty' => $qty,
            'reduceOnly' => $reduceOnly, 'positionIdx' => 0];
        if ($stop !== null || $take !== null) {
            $body['tpslMode'] = 'Full';
        }
        if ($stop !== null) {
            $body['stopLoss'] = $stop;
        }
        if ($take !== null) {
            $body['takeProfit'] = $take;
        }
        $this->api->post('/v5/order/create', $body);
    }

    public function cancel(string $symbol, string $linkId): void
    {
        try {
            $this->api->post('/v5/order/cancel', ['category' => 'linear', 'symbol' => $symbol, 'orderLinkId' => $linkId]);
        } catch (BybitError) {
            // уже исполнен или отменён
        }
    }

    public function cancelAll(string $symbol): void
    {
        $this->api->post('/v5/order/cancel-all', ['category' => 'linear', 'symbol' => $symbol]);
    }

    public function openOrderIds(string $symbol): array
    {
        $r = $this->api->get('/v5/order/realtime', ['category' => 'linear', 'symbol' => $symbol, 'limit' => 50], true);
        return array_values(array_filter(array_map(fn($o) => (string)($o['orderLinkId'] ?? ''), $r['list'] ?? [])));
    }

    public function orderResult(string $symbol, string $linkId): array
    {
        $r = $this->api->get('/v5/order/history', ['category' => 'linear', 'symbol' => $symbol, 'orderLinkId' => $linkId], true);
        $o = $r['list'][0] ?? null;
        if (!$o) {
            return ['status' => 'Unknown', 'avg_price' => 0.0, 'filled_qty' => 0.0];
        }
        return ['status' => $o['orderStatus'], 'avg_price' => (float)($o['avgPrice'] ?: 0), 'filled_qty' => (float)($o['cumExecQty'] ?: 0)];
    }

    public function closedPnl(string $symbol, int $sinceMs): array
    {
        $r = $this->api->get('/v5/position/closed-pnl', ['category' => 'linear', 'symbol' => $symbol, 'startTime' => $sinceMs, 'limit' => 50], true);
        return array_map(fn($x) => ['pnl' => (float)$x['closedPnl'], 'exit' => (float)$x['avgExitPrice'], 'ts' => (int)$x['updatedTime']],
            $r['list'] ?? []);
    }

    public function closePosition(string $symbol): ?array
    {
        $p = $this->positions()[$symbol] ?? null;
        if (!$p) {
            return null;
        }
        $this->placeMarket($symbol, $p['side'] === 'Buy' ? 'Sell' : 'Buy', rtrim(rtrim(sprintf('%.8F', $p['qty']), '0'), '.'), null, null, true);
        return [$p['entry'], $p['qty']];
    }

    /**
     * Проверка ключа клиента при подключении: права на торговлю, нет права вывода, реферал.
     * @return array{uid:string,referral_ok:bool}
     */
    public static function verifyKeys(string $key, string $secret, string $mode): array
    {
        try {
            $info = (new Bybit($key, $secret, $mode))->get('/v5/user/query-api', [], true);
        } catch (\Throwable $e) {
            throw new RuntimeException('Bybit отклонил ключ: ' . $e->getMessage());
        }
        if ((int)($info['readOnly'] ?? 1) === 1) {
            throw new RuntimeException('Ключ только для чтения. Включите права Contract → Orders и Positions.');
        }
        if (in_array('Withdraw', $info['permissions']['Wallet'] ?? [], true)) {
            throw new RuntimeException('У ключа есть право вывода средств. Создайте ключ БЕЗ права Withdraw.');
        }
        $uid = (string)($info['userID'] ?? '');
        $referral = $mode === 'live' ? self::isReferral($uid) : true;
        if ($mode === 'live' && Settings::get('require_referral') && !$referral) {
            throw new RuntimeException('Аккаунт Bybit зарегистрирован не по нашей реферальной ссылке. Зарегистрируйтесь: '
                . Settings::get('referral_link'));
        }
        return ['uid' => $uid, 'referral_ok' => $referral];
    }

    public static function isReferral(string $uid): bool
    {
        $key = (string)Settings::get('affiliate_api_key');
        if ($key === '' || $uid === '') {
            return !Settings::get('require_referral');
        }
        try {
            $r = (new Bybit($key, (string)Settings::get('affiliate_api_secret')))->get('/v5/user/aff-customer-info', ['uid' => $uid], true);
            return !empty($r['uid']);
        } catch (\Throwable) {
            return false;
        }
    }
}
