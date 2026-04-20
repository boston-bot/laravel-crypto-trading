<?php

namespace App\Services\Broker;

use App\Enums\BrokerType;
use App\Models\BrokerCredential;

class BrokerCredentialResolver
{
    public function resolve(BrokerType $broker, ?int $credentialId = null): ?BrokerCredential
    {
        $query = BrokerCredential::query()
            ->where('broker', $broker->value)
            ->where('status', 'active');

        if ($credentialId !== null) {
            $query->whereKey($credentialId);
        }

        $credential = $query->first();
        if ($credential !== null) {
            return $credential;
        }

        if ($credentialId !== null) {
            return null;
        }

        return $this->buildConfigBackedCredential($broker);
    }

    private function buildConfigBackedCredential(BrokerType $broker): ?BrokerCredential
    {
        if ($broker === BrokerType::COINBASE) {
            $apiKey = (string) config('broker.coinbase.api_key', '');
            $privateKey = (string) config('broker.coinbase.api_private_key', '');

            if ($apiKey === '' || $privateKey === '') {
                return null;
            }

            return new BrokerCredential([
                'broker' => $broker->value,
                'label' => 'coinbase-env',
                'api_key_ref' => $privateKey,
                'secret_ref' => $apiKey,
                'status' => 'active',
                'metadata' => [
                    'source' => 'config',
                    'ephemeral' => true,
                ],
            ]);
        }

        $xApiKey = (string) config('broker.robinhood.x_api_key', '');
        $privateKey = (string) config('broker.robinhood.private_api_key', '');

        if ($xApiKey === '' || $privateKey === '') {
            return null;
        }

        return new BrokerCredential([
            'broker' => $broker->value,
            'label' => 'robinhood-env',
            'api_key_ref' => $privateKey,
            'secret_ref' => $xApiKey,
            'status' => 'active',
            'metadata' => [
                'source' => 'config',
                'ephemeral' => true,
            ],
        ]);
    }
}
