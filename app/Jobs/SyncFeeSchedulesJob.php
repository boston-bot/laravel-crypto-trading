<?php

namespace App\Jobs;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerCredentialResolver;
use App\Services\MarketData\FeeScheduleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncFeeSchedulesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ?int $credentialId = null) {}

    public function handle(FeeScheduleService $fees, BrokerCredentialResolver $credentials): void
    {
        $credential = $credentials->resolve(BrokerType::COINBASE, $this->credentialId);
        if ($credential !== null) {
            $fees->refresh($credential);
        }
    }
}
