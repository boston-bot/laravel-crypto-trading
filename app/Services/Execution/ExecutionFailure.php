<?php

namespace App\Services\Execution;

use RuntimeException;

/** $maybeSent is true once a place request may have reached the broker: the outcome is then unknown, never retried. */
final class ExecutionFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $maybeSent)
    {
        parent::__construct($reason);
    }
}
