<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Models\User;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\Crypto\CoinbaseFeeTier;
use App\Services\Crypto\CryptoFeeObservations;
use Illuminate\Console\Command;
use Throwable;

class CryptoFeeTierCommand extends Command
{
    protected $signature = 'mcp:crypto-fees {--actor= : Configured local operator ID} {--credential= : Optional active Coinbase credential ID} {--record : Store the result as an immutable fee observation} {--scheduled : Refresh stale observations for crypto sleeve owners}';

    protected $description = 'Read Coinbase spot fee-tier evidence once for product review; no policy, cash, flag or order change';

    public function handle(CoinbaseFeeTier $fees, BrokerCredentialResolver $credentials, CryptoFeeObservations $observations): int
    {
        if ($this->option('scheduled')) {
            $this->line(json_encode($observations->scheduled(), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        try {
            $actor = (string) $this->option('actor');
            $id = $this->option('credential');
            if (! ctype_digit($actor) || ($id !== null && ! ctype_digit((string) $id))) {
                throw new \InvalidArgumentException;
            }
            $user = User::findOrFail((int) $actor);
            abort_unless(in_array($user->email, config('mcp.operator_emails'), true), 403);
            if ($this->option('record')) {
                $observation = $observations->record((int) $actor, $id === null ? null : (int) $id);
                $this->line(json_encode($observation->only(['id', 'status', 'pricing_tier', 'maker_rate', 'taker_rate', 'reasons', 'response_sha256']), JSON_THROW_ON_ERROR));

                return $observation->status === 'complete' ? self::SUCCESS : self::FAILURE;
            }
            $credential = $credentials->resolve(BrokerType::COINBASE, $id === null ? null : (int) $id);
            if ($credential === null) {
                throw new \RuntimeException;
            }
            $this->line(json_encode($fees->read($credential), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Coinbase fee-tier read refused or unavailable; check operator and configured credentials. No policy was applied.');

            return self::FAILURE;
        }
    }
}
