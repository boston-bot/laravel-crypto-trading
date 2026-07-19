<?php

namespace App\Services\MarketData;

use App\Models\FeeScheduleSnapshot;
use App\Models\OrderBookSummary;
use App\Models\SpreadObservation;

class SpreadAnalysisService
{
    public function __construct(private readonly FeeScheduleService $feeSchedules) {}

    public function analyze(string $productId, float $notional, string $buyVenue, string $sellVenue): SpreadObservation
    {
        $buy = $this->latest($productId, $buyVenue);
        $sell = $this->latest($productId, $sellVenue);
        $buyFee = $this->fees($buyVenue)?->taker_fee_bps;
        $sellFee = $this->fees($sellVenue)?->taker_fee_bps;
        $reasons = [];
        $classification = 'executable';
        $delta = ($buy && $sell) ? abs($buy->received_at->diffInMilliseconds($sell->received_at)) : PHP_INT_MAX;
        $bucket = (string) (int) $notional;
        $buyVwap = $buy ? data_get($buy->depth_json, $bucket.'.buy_vwap') : null;
        $sellVwap = $sell ? data_get($sell->depth_json, $bucket.'.sell_vwap') : null;

        if (! $buy?->is_valid || ! $sell?->is_valid) {
            $classification = 'invalid-book';
            $reasons[] = 'invalid or missing book';
        } elseif ($buy->book_age_ms >= 1000 || $sell->book_age_ms >= 1000 || $delta > 500) {
            $classification = 'stale';
            $reasons[] = 'books are not time-comparable';
        } elseif ($buyFee === null || $sellFee === null) {
            $classification = 'fee-uncertain';
            $reasons[] = 'fee snapshot missing or stale';
        } elseif ($buyVwap === null || $sellVwap === null) {
            $classification = 'insufficient-depth';
            $reasons[] = 'notional cannot be filled on both books';
        }

        $grossBps = $buyVwap && $sellVwap ? (((float) $sellVwap - (float) $buyVwap) / (float) $buyVwap) * 10000 : null;
        $impact = 0.0;
        $rebalance = (float) config('research.spread.rebalance_reserve_bps', 5);
        $buffer = (float) config('research.spread.safety_buffer_bps', 10);
        $netBps = $grossBps !== null && $buyFee !== null && $sellFee !== null
            ? $grossBps - (float) $buyFee - (float) $sellFee - $impact - $rebalance - $buffer
            : null;
        if ($classification === 'executable' && ($netBps === null || $netBps <= 0)) {
            $classification = 'negative-after-costs';
            $reasons[] = 'costs exceed gross edge';
        }

        return SpreadObservation::query()->create([
            'product_id' => $productId, 'buy_venue' => $buyVenue, 'sell_venue' => $sellVenue,
            'notional_usd' => $notional, 'observed_at' => now(),
            'buy_book_age_ms' => $buy?->book_age_ms ?? 4294967295,
            'sell_book_age_ms' => $sell?->book_age_ms ?? 4294967295,
            'receive_delta_ms' => min($delta, 4294967295),
            'buy_vwap' => $buyVwap, 'sell_vwap' => $sellVwap,
            'gross_edge_bps' => $grossBps, 'net_edge_bps' => $netBps,
            'buy_fee_bps' => $buyFee, 'sell_fee_bps' => $sellFee, 'impact_bps' => $impact,
            'rebalance_reserve_bps' => $rebalance, 'safety_buffer_bps' => $buffer,
            'classification' => $classification,
            'delay_outcomes_json' => collect(config('research.spread.delay_scenarios_ms'))
                ->mapWithKeys(fn ($ms) => [(string) $ms => ['status' => 'pending_prospective_measurement']])->all(),
            'rejection_reasons_json' => $reasons,
        ]);
    }

    private function latest(string $productId, string $venue): ?OrderBookSummary
    {
        return OrderBookSummary::query()->where('product_id', $productId)->where('venue', $venue)->latest('bucket_time')->first();
    }

    private function fees(string $venue): ?FeeScheduleSnapshot
    {
        return $this->feeSchedules->current($venue);
    }
}
