<?php

namespace App\Services\Research;

use RuntimeException;

final class ResearchFailure extends RuntimeException
{
    public function __construct(public readonly ResearchError $reason, public readonly ?int $retryAfter = null)
    {
        parent::__construct($reason->value);
    }
}
