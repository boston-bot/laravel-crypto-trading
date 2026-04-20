<?php

namespace App\Services\Strategy;

use App\Models\Asset;

class MarketRankService
{
    public function __construct(
        private readonly FeatureEngine $featureEngine,
        private readonly RegimeService $regimeService,
        private readonly CompositeScoringService $compositeScoringService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function rank(string $symbol): array
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
                'rank_score' => 0.0,
                'composite_score' => 0.0,
                'universe_rank' => null,
                'universe_size' => 0,
                'universe_percentile' => 0.0,
                'factor_breakdown' => [],
                'entry_eligible' => false,
                'exit_deterioration' => false,
                'regime' => ['state' => 'neutral', 'confidence' => 0.0, 'components' => []],
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $features = $this->featureEngine->latestOrCompute($asset);
        $regime = $this->regimeService->current();

        if ($features === null) {
            return [
                'skill' => 'strategy-v1',
                'symbol' => strtoupper($symbol),
                'rank_score' => 0.0,
                'composite_score' => 0.0,
                'universe_rank' => null,
                'universe_size' => 0,
                'universe_percentile' => 0.0,
                'factor_breakdown' => [],
                'entry_eligible' => false,
                'exit_deterioration' => false,
                'regime' => $regime,
                'evaluated_at' => now()->toIso8601String(),
            ];
        }

        $score = $this->compositeScoringService->rankAsset($asset, $features, $regime);
        $probabilityTier = $score['probability_tier'] ?? 'very_low';
        if (is_string($probabilityTier)) {
            $probabilityTier = [
                'tier' => $probabilityTier,
                'probability' => (float) ($score['probability'] ?? 0.0),
            ];
        }

        return [
            'skill' => 'strategy-v1',
            'symbol' => strtoupper($symbol),
            'rank_score' => (float) ($score['composite_score'] ?? 0.0),
            'composite_score' => (float) ($score['composite_score'] ?? 0.0),
            'score_tier' => (string) ($score['score_tier'] ?? 'E'),
            'probability_tier' => $probabilityTier,
            'entry_eligible' => (bool) ($score['entry_eligible'] ?? false),
            'exit_deterioration' => (bool) ($score['exit_deterioration'] ?? false),
            'universe_rank' => $score['universe_rank'] ?? null,
            'universe_size' => (int) ($score['universe_size'] ?? 0),
            'universe_percentile' => (float) ($score['universe_percentile'] ?? 0.0),
            'feature_snapshot_id' => $features->id,
            'factor_breakdown' => (array) ($score['factor_breakdown'] ?? []),
            'regime' => $regime,
            'evaluated_at' => now()->toIso8601String(),
        ];
    }
}
