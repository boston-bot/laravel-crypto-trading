<?php

namespace App\Services\MarketData;

use App\Models\Asset;
use App\Models\MarketQuote;
use Carbon\CarbonImmutable;

class QuoteSnapshotService
{
    /**
     * @param  array<string, mixed>  $quote
     */
    public function store(Asset $asset, array $quote, string $source = 'coinbase'): MarketQuote
    {
        $bid = $this->toFloat($quote['bid_price'] ?? $quote['bid'] ?? null);
        $ask = $this->toFloat($quote['ask_price'] ?? $quote['ask'] ?? null);
        $last = $this->toFloat($quote['last_price'] ?? $quote['last_trade_price'] ?? null);

        $mid = null;
        if ($bid !== null && $ask !== null && $bid > 0 && $ask > 0) {
            $mid = ($bid + $ask) / 2;
        } elseif ($last !== null && $last > 0) {
            $mid = $last;
        }

        $spreadBps = null;
        if ($bid !== null && $ask !== null && $bid > 0 && $ask > 0 && $mid !== null && $mid > 0) {
            $spreadBps = (($ask - $bid) / $mid) * 10000;
        }

        $snapshotTime = $this->parseTime($quote['snapshot_time'] ?? $quote['as_of'] ?? null) ?? now();

        return MarketQuote::query()->updateOrCreate(
            [
                'asset_id' => $asset->id,
                'snapshot_time' => $snapshotTime,
                'source' => $source,
            ],
            [
                'bid_price' => $bid,
                'ask_price' => $ask,
                'mid_price' => $mid,
                'last_price' => $last,
                'spread_bps' => $spreadBps,
                'liquidity_score' => $this->resolveLiquidityScore($spreadBps),
                'slippage_bps_estimate' => $this->resolveSlippageEstimate($spreadBps),
                'raw_json' => $quote,
            ]
        );
    }

    private function resolveLiquidityScore(?float $spreadBps): ?float
    {
        if ($spreadBps === null) {
            return null;
        }

        if ($spreadBps <= 10) {
            return 1.0;
        }

        if ($spreadBps >= 150) {
            return 0.0;
        }

        return max(0.0, 1 - (($spreadBps - 10) / 140));
    }

    private function resolveSlippageEstimate(?float $spreadBps): ?float
    {
        if ($spreadBps === null) {
            return null;
        }

        return max(5.0, ($spreadBps / 2) + 4.0);
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function parseTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        return CarbonImmutable::parse((string) $value);
    }
}
