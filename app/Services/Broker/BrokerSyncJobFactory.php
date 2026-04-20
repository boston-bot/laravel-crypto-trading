<?php

namespace App\Services\Broker;

use App\Enums\BrokerType;
use App\Jobs\SyncCoinbaseAccountJob;
use App\Jobs\SyncCoinbaseAssetsJob;
use App\Jobs\SyncCoinbaseMarketDataJob;
use App\Jobs\SyncCoinbaseOrdersJob;
use App\Jobs\SyncCoinbasePositionsJob;
use App\Jobs\SyncRobinhoodAccountJob;
use App\Jobs\SyncRobinhoodAssetsJob;
use App\Jobs\SyncRobinhoodMarketDataJob;
use App\Jobs\SyncRobinhoodOrdersJob;
use App\Jobs\SyncRobinhoodPositionsJob;

class BrokerSyncJobFactory
{
    /**
     * @return array<int, object>
     */
    public function make(BrokerType $broker, ?int $credentialId = null, string $timeframe = '1d'): array
    {
        return match ($broker) {
            BrokerType::COINBASE => [
                new SyncCoinbaseAccountJob($credentialId),
                new SyncCoinbaseAssetsJob($credentialId),
                new SyncCoinbaseMarketDataJob($credentialId, $timeframe),
                new SyncCoinbasePositionsJob($credentialId),
                new SyncCoinbaseOrdersJob($credentialId),
            ],
            BrokerType::ROBINHOOD => [
                new SyncRobinhoodAccountJob($credentialId),
                new SyncRobinhoodAssetsJob($credentialId),
                new SyncRobinhoodMarketDataJob($credentialId, $timeframe),
                new SyncRobinhoodPositionsJob($credentialId),
                new SyncRobinhoodOrdersJob($credentialId),
            ],
        };
    }

    public function marketDataJob(BrokerType $broker, ?int $credentialId = null, string $timeframe = '1d'): object
    {
        return match ($broker) {
            BrokerType::COINBASE => new SyncCoinbaseMarketDataJob($credentialId, $timeframe),
            BrokerType::ROBINHOOD => new SyncRobinhoodMarketDataJob($credentialId, $timeframe),
        };
    }
}
