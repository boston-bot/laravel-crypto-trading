<?php

namespace App\Services\Broker\Agentic;

use RuntimeException;

final class ReadFailure extends RuntimeException
{
    public function __construct(public readonly ReadError $reason, public readonly ?int $retryAfter = null)
    {
        parent::__construct($reason->value);
    }
}
