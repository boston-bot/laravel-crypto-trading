<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Robinhood\RobinhoodClient;
use App\Services\Broker\Robinhood\RobinhoodMapper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncRobinhoodAccountJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?int $credentialId = null,
    ) {
    }

    public function handle(
        RobinhoodClient $client,
        RobinhoodMapper $mapper,
        BrokerCredentialResolver $credentialResolver,
    ): void
    {
        $credential = $credentialResolver->resolve(BrokerType::ROBINHOOD, $this->credentialId);
        if ($credential === null) {
            return;
        }

        $accounts = $client->getAccounts($credential);

        foreach ($accounts as $rawAccount) {
            $mapped = $mapper->mapAccount($rawAccount);

            BrokerAccount::query()->updateOrCreate(
                [
                    'broker' => BrokerType::ROBINHOOD->value,
                    'external_account_id' => $mapped['external_account_id'],
                ],
                [
                    'broker_credential_id' => $credential->id,
                    'account_type' => $mapped['account_type'],
                    'currency' => $mapped['currency'],
                    'buying_power' => $mapped['buying_power'],
                    'cash_balance' => $mapped['cash_balance'],
                    'equity' => $mapped['equity'],
                    'status' => $mapped['status'],
                    'snapshot_at' => $mapped['snapshot_at'],
                    'raw_json' => $mapped['raw_json'],
                ],
            );
        }

        if ($credential->exists) {
            $credential->forceFill([
                'last_verified_at' => now(),
            ])->save();
        }
    }
}
