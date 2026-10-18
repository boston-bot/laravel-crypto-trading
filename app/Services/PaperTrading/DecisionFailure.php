<?php

namespace App\Services\PaperTrading;

use RuntimeException;

final class DecisionFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $outcome = 'rejected')
    {
        parent::__construct($reason);
    }
}
