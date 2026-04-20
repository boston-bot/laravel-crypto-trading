<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\AssetFeatureSnapshot;

class CompositeScoringService
{
    /**
     * @param  array<string, mixed>  $regime
     * @return array<string, mixed>
     */
    public function score(AssetFeatureSnapshot $features, array $regime): array
    {
        $weights = (array) config('trading.scoring.weights', []);

        $trend = (float) $features->trend_score;
        $relativeStrength = (float) $features->relative_strength_score;
        $momentum = (float) $features->momentum_score;
        $volatility = (float) $features->volatility_quality_score;
        $pullback = (float) $features->pullback_quality_score;
        $participation = (float) $features->participation_score;
        $executionPenalty = (float) $features->execution_quality_penalty;

        $rawComposite = (
            $trend * (float) ($weights['trend'] ?? 0.30)
            + $relativeStrength * (float) ($weights['relative_strength'] ?? 0.25)
            + $momentum * (float) ($weights['momentum'] ?? 0.20)
            + $volatility * (float) ($weights['volatility_quality'] ?? 0.15)
            + $pullback * (float) ($weights['pullback_quality'] ?? 0.10)
            + $participation * (float) ($weights['participation'] ?? 0.0)
        );

        $compositeScore = max(0.0, min(1.0, $rawComposite - ($executionPenalty * (float) ($weights['execution_penalty'] ?? 0.18))));

        $probability = $this->probabilityFromScore($compositeScore);
        $scoreTier = $this->scoreTier($compositeScore);
        $probabilityTier = $this->probabilityTier($probability);

        $regimeState = (string) ($regime['state'] ?? 'neutral');
        $entryEligible = $regimeState !== 'risk_off'
            && $trend >= 0.52
            && $momentum >= 0.5
            && $relativeStrength >= 0.5
            && $volatility >= 0.35
            && $executionPenalty <= 0.6;

        $exitDeterioration = $regimeState === 'risk_off'
            || $trend <= 0.42
            || $momentum <= 0.45
            || $relativeStrength <= 0.42;

        return [
            'composite_score' => round($compositeScore, 4),
            'score_tier' => $scoreTier,
            'probability' => round($probability, 4),
            'probability_tier' => $probabilityTier,
            'entry_eligible' => $entryEligible,
            'exit_deterioration' => $exitDeterioration,
            'factor_breakdown' => [
                'trend' => round($trend, 4),
                'relative_strength' => round($relativeStrength, 4),
                'momentum' => round($momentum, 4),
                'volatility_quality' => round($volatility, 4),
                'pullback_quality' => round($pullback, 4),
                'participation' => round($participation, 4),
                'execution_quality_penalty' => round($executionPenalty, 4),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $regime
     * @return array<string, mixed>
     */
    public function rankAsset(Asset $asset, AssetFeatureSnapshot $features, array $regime, string $timeframe = '1d'): array
    {
        $latestSnapshots = AssetFeatureSnapshot::query()
            ->where('timeframe', $timeframe)
            ->whereIn('asset_id', Asset::query()->where('is_enabled', true)->where('is_tradable', true)->pluck('id'))
            ->where('snapshot_time', '=', function ($query): void {
                $query->selectRaw('MAX(snapshot_time)')
                    ->from('asset_feature_snapshots as afs2')
                    ->whereColumn('afs2.asset_id', 'asset_feature_snapshots.asset_id')
                    ->limit(1);
            })
            ->get();

        if ($latestSnapshots->isEmpty()) {
            $scored = $this->score($features, $regime);

            return [
                ...$scored,
                'universe_rank' => 1,
                'universe_size' => 1,
                'universe_percentile' => 1.0,
            ];
        }

        $rows = $latestSnapshots
            ->map(function (AssetFeatureSnapshot $snapshot) use ($regime): array {
                $scored = $this->score($snapshot, $regime);

                return [
                    'asset_id' => $snapshot->asset_id,
                    'score' => (float) $scored['composite_score'],
                    'scored' => $scored,
                ];
            })
            ->sortByDesc('score')
            ->values();

        $targetIdx = $rows->search(fn (array $row): bool => (int) $row['asset_id'] === $asset->id);
        if ($targetIdx === false) {
            $targetIdx = 0;
        }

        $rank = $targetIdx + 1;
        $size = max(1, $rows->count());
        $percentile = round(1 - (($rank - 1) / $size), 4);

        $target = $rows->get($targetIdx);
        $scored = is_array($target['scored'] ?? null)
            ? (array) $target['scored']
            : $this->score($features, $regime);

        return [
            ...$scored,
            'universe_rank' => $rank,
            'universe_size' => $size,
            'universe_percentile' => $percentile,
        ];
    }

    private function scoreTier(float $score): string
    {
        return match (true) {
            $score >= 0.80 => 'A',
            $score >= 0.70 => 'B',
            $score >= 0.58 => 'C',
            $score >= 0.45 => 'D',
            default => 'E',
        };
    }

    private function probabilityFromScore(float $score): float
    {
        return max(0.35, min(0.80, 0.35 + ($score * 0.45)));
    }

    private function probabilityTier(float $probability): string
    {
        return match (true) {
            $probability >= 0.72 => 'high',
            $probability >= 0.62 => 'medium',
            $probability >= 0.52 => 'low',
            default => 'very_low',
        };
    }
}
