<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Models\BrokerAccount;
use App\Services\PaperTrading\PaperPortfolioValuationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SnapshotPaperPortfolioJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $broker = null,
        public readonly ?int $brokerAccountId = null,
    ) {}

    public function handle(PaperPortfolioValuationService $valuationService): void
    {
        if ((string) config('broker.mode', 'paper') !== 'paper') {
            return;
        }

        $query = BrokerAccount::query();

        if ($this->brokerAccountId !== null) {
            $query->whereKey($this->brokerAccountId);
        }

        $broker = BrokerType::tryFrom((string) $this->broker);
        if ($broker !== null) {
            $query->where('broker', $broker->value);
        }

        $query->orderBy('id')
            ->each(function (BrokerAccount $account) use ($valuationService): void {
                $valuationService->snapshot($account);
            });
    }
}
