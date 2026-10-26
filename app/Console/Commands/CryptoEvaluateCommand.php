<?php

namespace App\Console\Commands;

use App\Services\Crypto\CryptoStrategyEvaluation;
use Illuminate\Console\Command;
use Throwable;

class CryptoEvaluateCommand extends Command
{
    protected $signature = 'mcp:crypto-evaluate {--actor= : Configured local operator ID} {--portfolio= : Crypto virtual portfolio ID}';

    protected $description = 'One crypto cycle from stored data; simulated paper orders only while CRYPTO_PAPER_ENABLED is on; no network or venue order';

    public function handle(CryptoStrategyEvaluation $evaluation): int
    {
        try {
            if (! ctype_digit((string) $this->option('actor')) || ! ctype_digit((string) $this->option('portfolio'))) {
                throw new \InvalidArgumentException;
            }
            $this->line(json_encode($evaluation->evaluate((int) $this->option('actor'), (int) $this->option('portfolio')), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Crypto evaluation refused; check operator, crypto portfolio, policy version and collection list.');

            return self::FAILURE;
        }
    }
}
