<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoPolicies;
use Illuminate\Console\Command;
use Throwable;

class CryptoPolicyCommand extends Command
{
    protected $signature = 'mcp:crypto-policy {--actor= : Configured local operator ID} {--request= : Stable UUID for this policy request} {--base=0 : Expected current policy version} {--costs= : Local JSON array of verified product-specific fee observations}';

    protected $description = 'Explicitly create/version the separate $400 crypto virtual sleeve; no collection, order or flag change';

    public function handle(CryptoPolicies $policies): int
    {
        try {
            if (! ctype_digit((string) $this->option('actor')) || ! ctype_digit((string) $this->option('base'))) {
                throw new \InvalidArgumentException;
            }
            $path = $this->option('costs');
            if ($path !== null && (! is_file($path) || ! is_readable($path) || filesize($path) > 65536)) {
                throw new \InvalidArgumentException;
            }
            $costs = $path === null ? [] : json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($costs) || ! array_is_list($costs)) {
                throw new \InvalidArgumentException;
            }
            $policy = $policies->apply((int) $this->option('actor'), (string) $this->option('request'), (int) $this->option('base'), $costs);
            $this->line(json_encode(['portfolio_id' => $policy->paper_portfolio_id, 'policy_id' => $policy->id, 'version' => $policy->version,
                'mode' => 'paper', 'broker_executable' => false], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Crypto paper policy refused; check operator, request UUID, expected version and fee observation file.');

            return self::FAILURE;
        }
    }
}
