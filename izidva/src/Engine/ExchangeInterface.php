<?php
declare(strict_types=1);

namespace App\Engine;

/** Общий интерфейс: движок одинаково работает с Bybit и с бумажным счётом. */
interface ExchangeInterface
{
    public const MAKER_FEE = 0.0002;
    public const TAKER_FEE = 0.00055;

    public function mode(): string;                         // paper | demo | live
    public function equity(): float;
    /** @return array<string,array{side:string,qty:float,entry:float}> */
    public function positions(): array;
    public function setLeverage(string $symbol, int $leverage): void;
    public function placeLimit(string $symbol, string $side, string $qty, string $price, string $linkId, bool $reduceOnly = false): void;
    public function placeMarket(string $symbol, string $side, string $qty, ?string $stop = null, ?string $take = null, bool $reduceOnly = false): void;
    public function cancel(string $symbol, string $linkId): void;
    public function cancelAll(string $symbol): void;
    /** @return string[] */
    public function openOrderIds(string $symbol): array;
    /** @return array{status:string,avg_price:float,filled_qty:float} */
    public function orderResult(string $symbol, string $linkId): array;
    /** @return list<array{pnl:float,exit:float,ts:int}> */
    public function closedPnl(string $symbol, int $sinceMs): array;
    /** Закрыть позицию по рынку. @return array{0:float,1:float}|null [цена, объём] */
    public function closePosition(string $symbol): ?array;
}
