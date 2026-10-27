<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoMarketCollector;
use Illuminate\Console\Command;

class CryptoHistoryCommand extends Command
{
    protected $signature = 'mcp:crypto-history {--days=1095 : Calendar days of settled daily history to hold for replay (1–2000)}';

    protected $description = 'Crypto C3: one public read of older settled Coinbase daily candles for replay; no credentials, signals or orders';

    public function handle(CryptoMarketCollector $collector): int
    {
        if (! ctype_digit((string) $this->option('days'))) {
            $this->error('Use a whole number of days.');

            return self::FAILURE;
        }
        $result = $collector->backfill((int) $this->option('days'));
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return $result['status'] === 'complete' ? self::SUCCESS : self::FAILURE;
    }
}
