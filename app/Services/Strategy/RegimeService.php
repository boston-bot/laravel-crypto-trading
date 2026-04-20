<?php

namespace App\Services\Strategy;

use App\Models\Asset;
use App\Models\AssetFeatureSnapshot;
use App\Models\MarketRegimeSnapshot;

class RegimeService
{
    public function __construct(
        private readonly FeatureEngine $featureEngine,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function current(string $timeframe = '1d'): array
    {
        $latest = MarketRegimeSnapshot::query()
            ->latest('snapshot_time')
            ->first();

        if ($latest !== null && $latest->snapshot_time?->gte(now()->subHours(4))) {
            return [
                'state' => (string) $latest->regime,
                'confidence' => (float) $latest->confidence,
                'components' => (array) ($latest->components_json ?? []),
                'snapshot_time' => $latest->snapshot_time?->toIso8601String(),
            ];
        }

        return $this->classifyAndPersist($timeframe);
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyAndPersist(string $timeframe = '1d'): array
    {
        $btcAsset = Asset::query()->where('symbol', 'BTC')->first();
        $ethAsset = Asset::query()->where('symbol', 'ETH')->first();

        $btcFeatures = $btcAsset ? $this->featureEngine->latestOrCompute($btcAsset, $timeframe) : null;
        $ethFeatures = $ethAsset ? $this->featureEngine->latestOrCompute($ethAsset, $timeframe) : null;

        $latestUniverse = AssetFeatureSnapshot::query()
            ->where('timeframe', $timeframe)
            ->whereIn('asset_id', Asset::query()->where('is_enabled', true)->pluck('id'))
            ->where('snapshot_time', '=', function ($query): void {
                $query->selectRaw('MAX(snapshot_time)')
                    ->from('asset_feature_snapshots as afs2')
                    ->whereColumn('afs2.asset_id', 'asset_feature_snapshots.asset_id')
                    ->limit(1);
            })
            ->get();

        $breadthSupport = 0.0;
        if ($latestUniverse->isNotEmpty()) {
            $breadthSupport = $latestUniverse
                ->filter(fn (AssetFeatureSnapshot $snapshot): bool => (float) $snapshot->trend_score >= 0.55)
                ->count() / $latestUniverse->count();
        }

        $btcTrend = (float) ($btcFeatures?->trend_score ?? 0.5);
        $btcMomentum = (float) ($btcFeatures?->momentum_score ?? 0.5);
        $btcAtr = (float) ($btcFeatures?->atr_pct ?? 0.03);
        $btcVolQuality = (float) ($btcFeatures?->volatility_quality_score ?? 0.5);
        $ethTrend = (float) ($ethFeatures?->trend_score ?? 0.5);

        $volShock = $btcAtr >= (float) config('trading.regime.vol_shock_atr_pct', 0.08)
            || $btcVolQuality <= (float) config('trading.regime.vol_shock_quality_threshold', 0.35);

        $riskOnSignals = [
            $btcTrend >= (float) config('trading.regime.btc_trend_threshold', 0.58),
            $btcMomentum >= (float) config('trading.regime.btc_momentum_threshold', 0.55),
            $ethTrend >= (float) config('trading.regime.eth_confirmation_threshold', 0.52),
            $breadthSupport >= (float) config('trading.regime.breadth_threshold', 0.55),
            ! $volShock,
        ];

        $riskOnCount = count(array_filter($riskOnSignals));
        $riskOff = ($btcTrend <= 0.42 && $btcMomentum <= 0.45) || $volShock;

        $state = 'neutral';
        if ($riskOnCount >= 4 && ! $riskOff) {
            $state = 'risk_on';
        } elseif ($riskOff) {
            $state = 'risk_off';
        }

        $confidence = min(1.0, max(0.15, $riskOnCount / 5));
        if ($state === 'risk_off') {
            $confidence = max($confidence, 0.65);
        }

        $components = [
            'btc_trend_score' => $btcTrend,
            'btc_momentum_score' => $btcMomentum,
            'btc_atr_pct' => $btcAtr,
            'btc_volatility_quality_score' => $btcVolQuality,
            'eth_trend_score' => $ethTrend,
            'breadth_support_ratio' => $breadthSupport,
            'volatility_shock' => $volShock,
            'risk_on_signals' => $riskOnSignals,
        ];

        $snapshotTime = now()->startOfHour();
        $snapshot = MarketRegimeSnapshot::query()->updateOrCreate(
            [
                'snapshot_time' => $snapshotTime,
                'source' => 'strategy-v1',
            ],
            [
                'regime' => $state,
                'confidence' => $confidence,
                'components_json' => $components,
            ]
        );

        return [
            'state' => $snapshot->regime,
            'confidence' => (float) $snapshot->confidence,
            'components' => $components,
            'snapshot_time' => $snapshot->snapshot_time?->toIso8601String(),
        ];
    }
}
