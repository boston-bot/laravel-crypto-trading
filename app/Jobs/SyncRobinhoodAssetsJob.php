<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\Asset;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\Broker\Robinhood\RobinhoodMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRobinhoodAssetsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
    ) {}

    public function handle(
        RobinhoodClient $client,
        RobinhoodMapper $mapper,
        BrokerCredentialResolver $credentialResolver,
    ): void {
        $credential = $credentialResolver->resolve(BrokerType::ROBINHOOD, $this->credentialId);
        if ($credential === null) {
            return;
        }

        $assets = $client->getAssets($credential);
        foreach ($assets as $rawAsset) {
            $mapped = $mapper->mapAsset($rawAsset);
            if (($mapped['symbol'] ?? '') === '') {
                continue;
            }

            Asset::query()->updateOrCreate(
                [
                    'broker' => BrokerType::ROBINHOOD->value,
                    'symbol' => $mapped['symbol'],
                ],
                [
                    'asset_type' => $mapped['asset_type'],
                    'is_tradable' => $mapped['is_tradable'],
                    'min_order_notional' => $mapped['min_order_notional'],
                    'price_precision' => $mapped['price_precision'],
                    'quantity_precision' => $mapped['quantity_precision'],
                    'metadata_json' => $mapped['metadata_json'],
                ],
            );
        }
    }
}
