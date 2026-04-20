<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\MarketQuote;

class TaSignalService
{
    public function __construct(
        private readonly FeatureEngine $featureEngine,
        private readonly RegimeService $regimeService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $symbol): array
    {
        $asset = Asset::query()
            ->where('symbol', strtoupper($symbol))
            ->where('is_enabled', true)
            ->where('is_tradable', true)
            ->first();

        if ($asset === null) {
            return [
                'skill' => 'strategy-v1',
                'symbol' => strtoupper($symbol),
                'trend' => 'unknown',
                'momentum' => 0.0,
                'rsi' => 50.0,
                'volatility' => 1.0,
                'atr_pct' => 0.0,
                'regime_state' => 'neutral',
                'confidence' => 0.0,
                'feature_ready' => false,
                'features' => [],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $features = $this->featureEngine->latestOrCompute($asset);
        $regime = $this->regimeService->current();

        if ($features === null) {
            return [
                'skill' => 'strategy-v1',
                'symbol' => strtoupper($symbol),
                'trend' => 'unknown',
                'momentum' => 0.0,
                'rsi' => 50.0,
                'volatility' => 1.0,
                'atr_pct' => 0.0,
                'regime_state' => (string) ($regime['state'] ?? 'neutral'),
                'confidence' => 0.0,
                'feature_ready' => false,
                'features' => [],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $trendScore = (float) $features->trend_score;
        $momentumScore = (float) $features->momentum_score;
        $volatilityScore = (float) $features->volatility_quality_score;
        $atrPct = (float) ($features->atr_pct ?? 0.0);
        $rsi = (float) ($features->features_json['rsi_14'] ?? 50.0);

        $trend = 'sideways';
        if ($trendScore >= 0.58) {
            $trend = 'uptrend';
        } elseif ($trendScore <= 0.42) {
            $trend = 'downtrend';
        }

        $quote = MarketQuote::query()
            ->where('asset_id', $asset->id)
            ->latest('snapshot_time')
            ->first();

        $lastPrice = (float) ($quote?->mid_price ?? $quote?->last_price ?? $features->features_json['close'] ?? 0.0);
        $distanceFromHigh20 = (float) ($features->features_json['distance_from_high_20'] ?? 0.0);
        $extensionPct = max(0.0, -1 * $distanceFromHigh20);

        $confidence = max(0.1, min(0.95, 0.35 + ($trendScore * 0.35) + ($momentumScore * 0.3)));

        return [
            'skill' => 'strategy-v1',
            'symbol' => strtoupper($symbol),
            'trend' => $trend,
            'momentum' => round(($momentumScore - 0.5) * 2, 4),
            'rsi' => round($rsi, 2),
            'volatility' => round(max(0.0, 1 - $volatilityScore), 4),
            'atr_pct' => round($atrPct, 6),
            'extension_pct' => round($extensionPct, 6),
            'last_price' => round($lastPrice, 8),
            'feature_ready' => true,
            'regime_state' => (string) ($regime['state'] ?? 'neutral'),
            'confidence' => round($confidence, 4),
            'features' => [
                'trend_score' => round($trendScore, 4),
                'momentum_score' => round($momentumScore, 4),
                'relative_strength_score' => round((float) $features->relative_strength_score, 4),
                'pullback_quality_score' => round((float) $features->pullback_quality_score, 4),
                'volatility_quality_score' => round($volatilityScore, 4),
                'participation_score' => round((float) $features->participation_score, 4),
                'execution_quality_penalty' => round((float) $features->execution_quality_penalty, 4),
            ],
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}
