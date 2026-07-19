<?php

namespace App\Services\Portfolio;

use App\Contracts\PortfolioContext;
use App\Models\BrokerAccount;
use InvalidArgumentException;

class PortfolioContextResolver
{
    public function __construct(
        private readonly PaperPortfolioContextFactory $paper,
        private readonly LivePortfolioContextFactory $live,
    ) {}

    public function resolve(
        string $mode,
        BrokerAccount $account,
        ?int $strategyVersionId = null,
        ?int $universeVersionId = null,
    ): PortfolioContext {
        return match ($mode) {
            'paper' => $this->paper->create($account, $strategyVersionId, $universeVersionId),
            'live' => $this->live->create($account, $strategyVersionId, $universeVersionId),
            default => throw new InvalidArgumentException('Unknown portfolio context mode: '.$mode),
        };
    }
}
