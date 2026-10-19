<?php

declare(strict_types=1);

namespace App\Services\MarketData\Sec;

/** Safe, persistable failure code. Never carries a provider body, URL query or request header. */
final class EdgarReadFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
