<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use Illuminate\Console\Command;
use Throwable;

class TestCoinbaseConnectivityCommand extends Command
{
    protected $signature = 'broker:test-coinbase {credentialId? : Optional broker credential ID}';

    protected $description = 'Run an authenticated Coinbase connectivity probe (accounts, products, quotes).';

    public function handle(
        CoinbaseClient $coinbaseClient,
        BrokerCredentialResolver $credentialResolver,
    ): int {
        $credential = $credentialResolver->resolve(
            BrokerType::COINBASE,
            $this->argument('credentialId') !== null ? (int) $this->argument('credentialId') : null,
        );

        if ($credential === null) {
            $this->error('No active Coinbase credentials found in DB or env config.');

            return self::FAILURE;
        }

        try {
            $accounts = $coinbaseClient->getAccounts($credential);
            $assets = $coinbaseClient->getAssets($credential);
            $symbols = collect($assets)
                ->pluck('base_currency_id')
                ->filter(fn (mixed $symbol): bool => is_string($symbol) && $symbol !== '')
                ->take(3)
                ->values()
                ->all();
            $quotes = $symbols === [] ? [] : $coinbaseClient->getBestBidAsk($credential, $symbols);

            $this->info('Coinbase connectivity check passed.');
            $this->line('Accounts: '.count($accounts));
            $this->line('Products: '.count($assets));
            $this->line('Quote samples: '.count($quotes));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Coinbase connectivity check failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
