<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Models\Position;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\Broker\Robinhood\RobinhoodMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRobinhoodPositionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
        public readonly ?int $brokerAccountId = null,
    ) {
    }

    public function handle(
        RobinhoodClient $client,
        RobinhoodMapper $mapper,
        BrokerCredentialResolver $credentialResolver,
    ): void
    {
        $credential = $credentialResolver->resolve(BrokerType::ROBINHOOD, $this->credentialId);
        $account = $this->resolveAccount();
        if ($credential === null || $account === null) {
            return;
        }

        $assetLookup = $mapper->buildAssetLookup();
        $positions = $client->getPositions($credential);

        foreach ($positions as $rawPosition) {
            $mapped = $mapper->mapPosition($rawPosition, $assetLookup);
            if (($mapped['asset_id'] ?? null) === null) {
                continue;
            }

            Position::query()->updateOrCreate(
                [
                    'broker_account_id' => $account->id,
                    'asset_id' => $mapped['asset_id'],
                ],
                [
                    'quantity' => $mapped['quantity'],
                    'avg_cost' => $mapped['avg_cost'],
                    'market_value' => $mapped['market_value'],
                    'unrealized_pnl' => $mapped['unrealized_pnl'],
                    'snapshot_at' => $mapped['snapshot_at'],
                    'raw_json' => $mapped['raw_json'],
                ],
            );
        }
    }

    private function resolveAccount(): ?BrokerAccount
    {
        $query = BrokerAccount::query()
            ->where('broker', BrokerType::ROBINHOOD->value);

        if ($this->brokerAccountId !== null) {
            $query->whereKey($this->brokerAccountId);
        }

        return $query->first();
    }
}
