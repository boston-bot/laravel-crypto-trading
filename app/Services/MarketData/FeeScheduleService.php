<?php

namespace App\Services\MarketData;

use App\Models\BrokerCredential;
use App\Models\FeeScheduleSnapshot;
use App\Services\Broker\Coinbase\CoinbaseClient;

class FeeScheduleService
{
    public function __construct(private readonly CoinbaseClient $coinbase) {}

    /** @return array<int, FeeScheduleSnapshot> */
    public function refresh(BrokerCredential $credential): array
    {
        $response = $this->coinbase->getTransactionSummary($credential);
        $body = (array) ($response['body'] ?? []);
        $tier = (array) ($body['fee_tier'] ?? $body);
        $makerBps = $this->rateToBps($tier['maker_fee_rate'] ?? null);
        $takerBps = $this->rateToBps($tier['taker_fee_rate'] ?? null);
        if ($makerBps === null || $takerBps === null) {
            throw new \RuntimeException('Coinbase transaction summary did not include both maker and taker fee rates.');
        }
        $coinbase = $this->store(
            'coinbase', 'actual_account_tier',
            $makerBps,
            $takerBps,
            $body,
            'https://docs.cdp.coinbase.com/api-reference/advanced-trade-api/rest-api/fees/get-transaction-summary',
        );
        $krakenBps = (float) config('research.fees.kraken_retail_taker_bps', 40.0);
        $kraken = $this->store(
            'kraken', 'retail_conservative', $krakenBps, $krakenBps,
            ['scenario' => 'configured conservative retail taker'],
            'https://www.kraken.com/features/fee-schedule',
        );

        return [$coinbase, $kraken];
    }

    public function current(string $venue): ?FeeScheduleSnapshot
    {
        return FeeScheduleSnapshot::query()
            ->where('venue', $venue)
            ->where('effective_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('created_at', '>=', now()->subMinutes((int) config('research.fees.max_age_minutes', 60)))
            ->latest('effective_at')->first();
    }

    private function store(string $venue, string $scope, float $makerBps, float $takerBps, array $raw, string $url): FeeScheduleSnapshot
    {
        $effectiveAt = now()->startOfMinute();

        return FeeScheduleSnapshot::query()->updateOrCreate(
            ['venue' => $venue, 'account_scope' => $scope, 'effective_at' => $effectiveAt],
            [
                'maker_fee_bps' => $makerBps, 'taker_fee_bps' => $takerBps,
                'expires_at' => $effectiveAt->copy()->addMinutes((int) config('research.fees.max_age_minutes', 60)),
                'source_url' => $url,
                'content_hash' => hash('sha256', json_encode($raw, JSON_THROW_ON_ERROR)),
                'raw_json' => $raw,
            ],
        );
    }

    private function rateToBps(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }
        $rate = (float) $value;

        return $rate <= 1 ? $rate * 10000 : $rate;
    }
}
