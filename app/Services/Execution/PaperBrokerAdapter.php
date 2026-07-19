<?php

namespace App\Services\Execution;

use App\Models\Asset;
use App\Models\BrokerAccount;
use App\Models\BrokerOrder;
use App\Models\TradeDecision;
use App\Services\PaperTrading\PaperExecutionEngine;

class PaperBrokerAdapter
{
    public function __construct(
        private readonly PaperExecutionEngine $paperExecutionEngine,
    ) {}

    public function submit(BrokerAccount $account, Asset $asset, TradeDecision $decision): BrokerOrder
    {
        return $this->paperExecutionEngine->submit($account, $asset, $decision);
    }
}
