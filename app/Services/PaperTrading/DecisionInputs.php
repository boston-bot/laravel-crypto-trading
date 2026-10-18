<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use Carbon\CarbonImmutable;

interface DecisionInputs
{
    /** Normalized local evidence available by the cutoff; no policy, brokerage writes or strategy engine. */
    public function load(McpConnection $connection, CarbonImmutable $cutoff): array;
}
