<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\AssetFeatureSnapshot;
use App\Models\ExecutionQualitySnapshot;
use App\Models\MarketCandle;
use App\Models\MarketQuote;
use Illuminate\Support\Collection;

class FeatureEngine
{
    public function latestSnapshot(Asset $asset, string $timeframe = '1d'): ?AssetFeatureSnapshot
    {
        return AssetFeatureSnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', $timeframe)
            ->latest('snapshot_time')
            ->first();
    }

    public function latestOrCompute(Asset $asset, string $timeframe = '1d'): ?AssetFeatureSnapshot
    {
        $latestCandle = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', $timeframe)
            ->latest('candle_open_time')
            ->first();

        if ($latestCandle === null) {
            return $this->latestSnapshot($asset, $timeframe);
        }

        $existing = AssetFeatureSnapshot::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', $timeframe)
            ->where('snapshot_time', $latestCandle->candle_open_time)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->computeAndPersist($asset, $timeframe, $latestCandle->candle_open_time->toDateTimeString());
    }

    public function computeAndPersist(Asset $asset, string $timeframe = '1d', ?string $asOf = null): ?AssetFeatureSnapshot
    {
        $candles = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->where('timeframe', $timeframe)
            ->when($asOf !== null, fn ($query) => $query->where('candle_open_time', '<=', $asOf))
            ->orderByDesc('candle_open_time')
            ->limit(240)
            ->get();

        if ($candles->count() < 30) {
            return $this->latestSnapshot($asset, $timeframe);
        }

        $orderedCandles = $candles->sortBy('candle_open_time')->values();
        $closes = $orderedCandles->pluck('close')->map(fn ($v) => (float) $v)->values();
        $highs = $orderedCandles->pluck('high')->map(fn ($v) => (float) $v)->values();
        $lows = $orderedCandles->pluck('low')->map(fn ($v) => (float) $v)->values();
        $volumes = $orderedCandles->pluck('volume')->map(fn ($v) => (float) ($v ?? 0))->values();

        $latestClose = (float) $closes->last();
        if ($latestClose <= 0) {
            return null;
        }

        $sma50 = $this->averageTail($closes, 50);
        $sma100 = $this->averageTail($closes, 100);
        $sma200 = $this->averageTail($closes, 200);
        $sma50Prev10 = $this->averageWindow($closes, 60, 50);

        $priceVs50 = $this->ratioDiff($latestClose, $sma50);
        $priceVs100 = $this->ratioDiff($latestClose, $sma100);
        $priceVs200 = $this->ratioDiff($latestClose, $sma200);
        $slope50 = $this->ratioDiff($sma50, $sma50Prev10);

        $trendScore = (
            $this->normalizeSigned($priceVs50, -0.08, 0.08) * 0.30
            + $this->normalizeSigned($priceVs100, -0.12, 0.12) * 0.25
            + $this->normalizeSigned($priceVs200, -0.18, 0.18) * 0.25
            + $this->normalizeSigned($slope50, -0.03, 0.03) * 0.20
        );

        $ret20 = $this->returnOverPeriod($closes, 20);
        $ret60 = $this->returnOverPeriod($closes, 60);
        $persistence = $this->positiveReturnRatio($closes, 10);
        $momentumScore = (
            $this->normalizeSigned($ret20, -0.20, 0.20) * 0.45
            + $this->normalizeSigned($ret60, -0.45, 0.45) * 0.40
            + $persistence * 0.15
        );

        $btcReturn20 = $this->btcReturnOverPeriod($timeframe, 20, $asOf);
        $relativeStrength = $ret20 - $btcReturn20;
        $relativeStrengthScore = $this->normalizeSigned($relativeStrength, -0.15, 0.15);

        $high20 = $this->maxTail($highs, 20);
        $distanceFromHigh20 = $high20 > 0 ? ($high20 - $latestClose) / $high20 : 0.0;
        $pullbackCenter = 0.05;
        $pullbackSpread = 0.08;
        $pullbackScore = 1.0 - min(1.0, abs($distanceFromHigh20 - $pullbackCenter) / $pullbackSpread);
        if ($priceVs50 < 0) {
            $pullbackScore *= 0.5;
        }

        $atr14 = $this->atr($orderedCandles, 14);
        $atrPct = $latestClose > 0 ? $atr14 / $latestClose : 0.0;
        $realizedVol20 = $this->realizedVolatility($closes, 20);

        $volatilityScore = (
            (1 - $this->normalizeUnsigned($atrPct, 0.01, 0.09)) * 0.55
            + (1 - $this->normalizeUnsigned($realizedVol20, 0.015, 0.11)) * 0.45
        );

        $volumeRatio = $this->ratio($volumes->last(), $this->averageTail($volumes, 20));
        $volumeCv20 = $this->coefficientOfVariation($volumes->slice(-20)->values());
        $participationScore = (
            $this->normalizeUnsigned($volumeRatio, 0.8, 2.0) * 0.65
            + (1 - $this->normalizeUnsigned($volumeCv20, 0.2, 1.3)) * 0.35
        );

        $latestQuote = MarketQuote::query()
            ->where('asset_id', $asset->id)
            ->when($asOf !== null, fn ($query) => $query->where('snapshot_time', '<=', $asOf))
            ->latest('snapshot_time')
            ->first();

        $spreadBps = (float) ($latestQuote?->spread_bps ?? 40.0);
        $slippageBpsEstimate = (float) ($latestQuote?->slippage_bps_estimate ?? max(10.0, ($spreadBps / 2) + 6));
        $liquidityScore = (float) ($latestQuote?->liquidity_score ?? 0.6);

        $executionPenalty = (
            $this->normalizeUnsigned($spreadBps, 12, 180) * 0.45
            + $this->normalizeUnsigned($slippageBpsEstimate, 10, 160) * 0.35
            + (1 - $this->clamp($liquidityScore)) * 0.20
        );

        $rsi14 = $this->rsi($closes, 14);

        $snapshotTime = $orderedCandles->last()->candle_open_time;
        $featurePayload = [
            'close' => $latestClose,
            'sma_50' => $sma50,
            'sma_100' => $sma100,
            'sma_200' => $sma200,
            'sma_50_slope' => $slope50,
            'return_20' => $ret20,
            'return_60' => $ret60,
            'btc_return_20' => $btcReturn20,
            'relative_strength_vs_btc' => $relativeStrength,
            'distance_from_high_20' => $distanceFromHigh20,
            'atr_14' => $atr14,
            'atr_pct' => $atrPct,
            'realized_volatility_20' => $realizedVol20,
            'volume_ratio_20' => $volumeRatio,
            'volume_cv_20' => $volumeCv20,
            'spread_bps' => $spreadBps,
            'slippage_bps_estimate' => $slippageBpsEstimate,
            'liquidity_score' => $liquidityScore,
            'rsi_14' => $rsi14,
        ];

        ExecutionQualitySnapshot::query()->updateOrCreate(
            [
                'asset_id' => $asset->id,
                'snapshot_time' => $snapshotTime,
            ],
            [
                'spread_bps' => $spreadBps,
                'slippage_bps_estimate' => $slippageBpsEstimate,
                'liquidity_score' => $liquidityScore,
                'execution_penalty_score' => $executionPenalty,
                'context_json' => [
                    'timeframe' => $timeframe,
                    'source' => 'feature-engine',
                ],
            ]
        );

        return AssetFeatureSnapshot::query()->updateOrCreate(
            [
                'asset_id' => $asset->id,
                'timeframe' => $timeframe,
                'snapshot_time' => $snapshotTime,
            ],
            [
                'trend_score' => $this->clamp($trendScore),
                'momentum_score' => $this->clamp($momentumScore),
                'relative_strength_score' => $this->clamp($relativeStrengthScore),
                'pullback_quality_score' => $this->clamp($pullbackScore),
                'volatility_quality_score' => $this->clamp($volatilityScore),
                'participation_score' => $this->clamp($participationScore),
                'execution_quality_penalty' => $this->clamp($executionPenalty),
                'atr_pct' => $atrPct,
                'realized_volatility_20d' => $realizedVol20,
                'features_json' => $featurePayload,
                'source' => 'strategy-v1',
            ]
        );
    }

    private function btcReturnOverPeriod(string $timeframe, int $periods, ?string $asOf = null): float
    {
        $btc = Asset::query()->where('symbol', 'BTC')->first();
        if ($btc === null) {
            return 0.0;
        }

        $closes = MarketCandle::query()
            ->where('asset_id', $btc->id)
            ->where('timeframe', $timeframe)
            ->when($asOf !== null, fn ($query) => $query->where('candle_open_time', '<=', $asOf))
            ->orderByDesc('candle_open_time')
            ->limit(max(30, $periods + 1))
            ->pluck('close')
            ->reverse()
            ->map(fn ($v) => (float) $v)
            ->values();

        return $this->returnOverPeriod($closes, $periods);
    }

    private function ratioDiff(?float $a, ?float $b): float
    {
        if ($a === null || $b === null || $b == 0.0) {
            return 0.0;
        }

        return ($a - $b) / $b;
    }

    private function ratio(?float $a, ?float $b): float
    {
        if ($a === null || $b === null || $b == 0.0) {
            return 0.0;
        }

        return $a / $b;
    }

    private function averageTail(Collection $series, int $period): float
    {
        $slice = $series->slice(-$period)->values();
        if ($slice->isEmpty()) {
            return 0.0;
        }

        return (float) ($slice->sum() / $slice->count());
    }

    private function averageWindow(Collection $series, int $offsetFromEnd, int $window): float
    {
        $count = $series->count();
        if ($count <= $offsetFromEnd) {
            return $this->averageTail($series, $window);
        }

        $start = max(0, $count - $offsetFromEnd);
        $slice = $series->slice($start, $window)->values();
        if ($slice->isEmpty()) {
            return $this->averageTail($series, $window);
        }

        return (float) ($slice->sum() / $slice->count());
    }

    private function maxTail(Collection $series, int $period): float
    {
        $slice = $series->slice(-$period)->values();

        return (float) ($slice->max() ?? 0.0);
    }

    private function returnOverPeriod(Collection $closes, int $period): float
    {
        $count = $closes->count();
        if ($count <= $period) {
            return 0.0;
        }

        $end = (float) $closes->last();
        $start = (float) $closes->get($count - 1 - $period, 0.0);
        if ($start <= 0) {
            return 0.0;
        }

        return ($end - $start) / $start;
    }

    private function positiveReturnRatio(Collection $closes, int $period): float
    {
        $returns = $this->returns($closes)->slice(-$period)->values();
        if ($returns->isEmpty()) {
            return 0.5;
        }

        $positive = $returns->filter(fn (float $ret) => $ret > 0)->count();

        return $positive / $returns->count();
    }

    private function returns(Collection $closes): Collection
    {
        $values = $closes
            ->values()
            ->map(fn ($close): float => (float) $close)
            ->values();

        $returns = [];
        for ($i = 1; $i < $values->count(); $i++) {
            $start = (float) $values->get($i - 1, 0.0);
            $end = (float) $values->get($i, 0.0);

            if ($start <= 0) {
                $returns[] = 0.0;
                continue;
            }

            $returns[] = ($end - $start) / $start;
        }

        return collect($returns);
    }

    private function atr(Collection $candles, int $period): float
    {
        $ranges = [];
        $count = $candles->count();
        for ($i = max(1, $count - $period); $i < $count; $i++) {
            $current = $candles->get($i);
            $prev = $candles->get($i - 1);
            if ($current === null || $prev === null) {
                continue;
            }

            $high = (float) $current->high;
            $low = (float) $current->low;
            $prevClose = (float) $prev->close;

            $ranges[] = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
        }

        if ($ranges === []) {
            return 0.0;
        }

        return array_sum($ranges) / count($ranges);
    }

    private function realizedVolatility(Collection $closes, int $period): float
    {
        $returns = $this->returns($closes)->slice(-$period)->values();
        if ($returns->count() < 2) {
            return 0.0;
        }

        return $this->standardDeviation($returns);
    }

    private function rsi(Collection $closes, int $period): float
    {
        $returns = $this->returns($closes)->slice(-$period)->values();
        if ($returns->isEmpty()) {
            return 50.0;
        }

        $gains = $returns->filter(fn (float $ret) => $ret > 0)->sum();
        $losses = abs((float) $returns->filter(fn (float $ret) => $ret < 0)->sum());

        if ($losses <= 0.0) {
            return 100.0;
        }

        $rs = $gains / $losses;

        return 100 - (100 / (1 + $rs));
    }

    private function coefficientOfVariation(Collection $series): float
    {
        if ($series->isEmpty()) {
            return 0.0;
        }

        $mean = (float) ($series->sum() / $series->count());
        if ($mean == 0.0) {
            return 0.0;
        }

        return $this->standardDeviation($series) / $mean;
    }

    private function standardDeviation(Collection $series): float
    {
        if ($series->count() < 2) {
            return 0.0;
        }

        $mean = (float) ($series->sum() / $series->count());
        $variance = $series->reduce(
            fn (float $carry, float $item): float => $carry + (($item - $mean) ** 2),
            0.0
        ) / ($series->count() - 1);

        return sqrt(max(0.0, $variance));
    }

    private function normalizeSigned(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return 0.5;
        }

        return $this->clamp(($value - $min) / ($max - $min));
    }

    private function normalizeUnsigned(float $value, float $low, float $high): float
    {
        if ($high <= $low) {
            return 0.0;
        }

        return $this->clamp(($value - $low) / ($high - $low));
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return min($max, max($min, $value));
    }
}
