<?php

declare(strict_types=1);

namespace App\Services\Crypto;

/** Carries only a short redacted reason code; never provider bodies or request details. */
final class CryptoMarketDataException extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
