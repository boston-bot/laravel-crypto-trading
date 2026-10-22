<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Broker\Coinbase\CoinbaseClient;
use Illuminate\Console\Command;
use Throwable;

class BrokerKeyPermissionsCommand extends Command
{
    protected $signature = 'broker:key-permissions {--broker=coinbase : coinbase or robinhood} {credentialId? : Optional broker credential ID}';

    protected $description = 'Read-only report of a legacy crypto API key\'s own permissions; no order, account or key change.';

    public function handle(CoinbaseClient $coinbaseClient, BrokerCredentialResolver $credentialResolver): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker'));
        if ($broker === null) {
            $this->error('Unknown broker. Use coinbase or robinhood.');

            return self::FAILURE;
        }

        if ($broker === BrokerType::ROBINHOOD) {
            // No documented permission read exists for this API; do not infer read-only from a successful GET.
            $this->warn('Robinhood Crypto exposes no documented key-permission read. Permissions are unverified; check the key in Robinhood\'s API credentials settings.');

            return self::SUCCESS;
        }

        $credential = $credentialResolver->resolve(
            $broker,
            $this->argument('credentialId') !== null ? (int) $this->argument('credentialId') : null,
        );
        if ($credential === null) {
            $this->error('No active Coinbase credentials found in DB or env config.');

            return self::FAILURE;
        }

        try {
            $permissions = $coinbaseClient->getKeyPermissions($credential);
        } catch (Throwable $exception) {
            $this->error('Coinbase key-permission read failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $flags = [];
        foreach (['can_view', 'can_trade', 'can_transfer'] as $field) {
            $value = $permissions[$field] ?? null;
            $flags[$field] = is_bool($value) ? $value : null;
            $this->line($field.': '.match ($flags[$field]) {
                true => 'yes',
                false => 'no',
                null => 'unknown',
            });
        }
        $this->line('portfolio_type: '.(is_string($permissions['portfolio_type'] ?? null) ? $permissions['portfolio_type'] : 'unknown'));

        if (in_array(null, $flags, true)) {
            $this->error('Response did not state every permission; treat the key as trade-capable.');

            return self::FAILURE;
        }
        if ($flags['can_trade'] || $flags['can_transfer']) {
            $this->warn('This key can trade or transfer. A view-only key is recommended until an authorized crypto pilot.');
        } else {
            $this->info('This key is view-only.');
        }

        return self::SUCCESS;
    }
}
