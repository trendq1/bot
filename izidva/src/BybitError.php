<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/** Ошибка, которую вернул Bybit (retCode != 0). */
final class BybitError extends RuntimeException
{
    public function __construct(public readonly int $retCode, string $message)
    {
        parent::__construct($message, $retCode);
    }
}
