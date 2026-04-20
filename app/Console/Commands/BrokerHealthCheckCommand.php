<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Robinhood\RobinhoodClient;
use Illuminate\Console\Command;
use Throwable;

class BrokerHealthCheckCommand extends Command
{
    protected $signature = 'broker:health-check {credentialId? : Optional broker credential ID} {--broker= : Broker (coinbase|robinhood)}';

    protected $description = 'Validate broker authentication and connectivity.';

    public function handle(
        RobinhoodClient $robinhoodClient,
        CoinbaseClient $coinbaseClient,
        BrokerCredentialResolver $credentialResolver,
    ): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker')) ?? BrokerType::default();
        $credential = $credentialResolver->resolve(
            $broker,
            $this->argument('credentialId') !== null ? (int) $this->argument('credentialId') : null,
        );

        if ($credential === null) {
            $this->warn('No active '.strtoupper($broker->value).' credentials found.');

            return self::FAILURE;
        }

        try {
            match ($broker) {
                BrokerType::COINBASE => $coinbaseClient->ping($credential),
                BrokerType::ROBINHOOD => $robinhoodClient->ping($credential),
            };
            $label = $credential->id !== null ? "Credential {$credential->id}" : 'Environment credential';
            $this->info($label.' is healthy for '.$broker->value.'.');
        } catch (Throwable $exception) {
            $label = $credential->id !== null ? "Credential {$credential->id}" : 'Environment credential';
            $this->error($label.' failed for '.$broker->value.': '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
