<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoSpreadStudy;
use Illuminate\Console\Command;

class CryptoSpreadsCommand extends Command
{
    protected $signature = 'mcp:crypto-spreads {--once}';

    protected $description = 'Crypto venue cost study: one read-only sample of Robinhood Crypto vs Coinbase quote costs; no orders';

    public function handle(CryptoSpreadStudy $study): int
    {
        if (! $this->option('once')) {
            $this->error('Use --once; repeated sampling belongs to the scheduler.');

            return self::FAILURE;
        }
        $result = $study->sample();
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return in_array($result['status'], ['complete', 'disabled'], true) ? self::SUCCESS : self::FAILURE;
    }
}
