<?php

namespace App\Services\MarketData;

use App\Data\MarketData\CommonMarketBar;
use App\Models\Asset;
use App\Models\DataQualityIncident;
use App\Models\MarketCandle;
use App\Models\MarketCandleRevision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class CanonicalMarketEvidenceService
{
    public function __construct(private readonly CandleIngestionService $ingestion) {}

    /**
     * @return array{4h: int, 1d: int}
     */
    public function rebuildDerivedForAsset(Asset $asset, CarbonInterface $from, CarbonInterface $to): array
    {
        $source = $this->canonicalSource();
        $hourly = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->where('source', $source)
            ->where('timeframe', '1h')
            ->whereIn('quality_state', ['valid', 'verified'])
            ->where('is_final', true)
            ->whereBetween('candle_open_time', [
                CarbonImmutable::instance($from)->utc()->startOfDay()->subDay(),
                CarbonImmutable::instance($to)->utc()->endOfHour(),
            ])
            ->orderBy('candle_open_time')
            ->get();

        return [
            '4h' => $this->storeBuckets($asset, $hourly, '4h', 4),
            '1d' => $this->storeBuckets($asset, $hourly, '1d', 24),
        ];
    }

    /**
     * @param  Collection<int, Asset>  $assets
     */
    public function latestCommonEligibleBar(Collection $assets, CarbonInterface $evidenceCutoff, bool $recordGap = true): ?CommonMarketBar
    {
        $assetIds = $assets->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        if ($assetIds === []) {
            return null;
        }

        $cutoff = CarbonImmutable::instance($evidenceCutoff)->utc();
        $logicalBarClose = MarketCandle::query()
            ->whereIn('asset_id', $assetIds)
            ->where('source', $this->canonicalSource())
            ->where('timeframe', '4h')
            ->where('metadata_json->derived_from', '1h')
            ->where('is_final', true)
            ->whereIn('quality_state', ['valid', 'verified'])
            ->whereNotNull('first_seen_at')
            ->whereNotNull('available_at')
            ->where('available_at', '<=', $cutoff)
            ->where('first_seen_at', '<=', $cutoff)
            ->where('candle_close_time', '<=', $cutoff)
            ->select('candle_close_time')
            ->groupBy('candle_close_time')
            ->havingRaw('COUNT(DISTINCT asset_id) = ?', [count($assetIds)])
            ->orderByDesc('candle_close_time')
            ->value('candle_close_time');

        if ($logicalBarClose === null) {
            if ($recordGap) {
                $this->recordCoverageGap($assets, $cutoff);
            }

            return null;
        }

        $close = CarbonImmutable::parse((string) $logicalBarClose, 'UTC')->utc();
        $candles = MarketCandle::query()
            ->whereIn('asset_id', $assetIds)
            ->where('source', $this->canonicalSource())
            ->where('timeframe', '4h')
            ->where('metadata_json->derived_from', '1h')
            ->where('candle_close_time', $close)
            ->where('is_final', true)
            ->whereIn('quality_state', ['valid', 'verified'])
            ->get()
            ->keyBy('asset_id');

        if ($candles->count() !== count($assetIds)) {
            if ($recordGap) {
                $this->recordCoverageGap($assets, $cutoff);
            }

            return null;
        }

        return new CommonMarketBar(
            logicalBarClose: $close,
            evidenceCutoff: $cutoff,
            assetIds: $assetIds,
            candleIdsByAsset: $candles->mapWithKeys(
                fn (MarketCandle $candle): array => [(int) $candle->asset_id => (int) $candle->id],
            )->all(),
        );
    }

    public function canonicalSource(): string
    {
        return (string) config('research.candles.canonical_source', 'coinbase');
    }

    /** @return array<string, mixed>|null */
    public function pointInTimeVersion(MarketCandle $candle, CarbonInterface $cutoff): ?array
    {
        $at = CarbonImmutable::instance($cutoff)->utc();
        $versions = collect();
        if ($candle->available_at !== null && $candle->first_seen_at !== null && $candle->available_at->lte($at) && $candle->first_seen_at->lte($at)) {
            $versions->push(['available_at' => $candle->available_at, 'first_seen_at' => $candle->first_seen_at, 'open' => $candle->open, 'high' => $candle->high, 'low' => $candle->low, 'close' => $candle->close, 'volume' => $candle->volume, 'is_final' => $candle->is_final, 'quality_state' => $candle->quality_state, 'observation_id' => 'candle:'.$candle->id.':'.$candle->source_revision]);
        }
        foreach (MarketCandleRevision::query()->where('market_candle_id', $candle->id)->where('available_at', '<=', $at)->where('first_seen_at', '<=', $at)->get() as $revision) {
            $versions->push(array_merge((array) $revision->values_json, ['available_at' => $revision->available_at, 'first_seen_at' => $revision->first_seen_at, 'observation_id' => 'revision:'.$revision->id]));
        }
        return $versions->filter(fn (array $row): bool => $this->validNumericVersion($row))->sortByDesc(fn (array $row): int => $row['available_at']->getTimestamp())->first();
    }

    private function validNumericVersion(array $row): bool
    {
        foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
            if (! isset($row[$field]) || ! is_numeric($row[$field]) || ! is_finite((float) $row[$field])) { return false; }
        }
        return (bool) ($row['is_final'] ?? true) && in_array((string) ($row['quality_state'] ?? 'valid'), ['valid', 'verified'], true)
            && (float) $row['high'] >= max((float) $row['open'], (float) $row['close'], (float) $row['low'])
            && (float) $row['low'] <= min((float) $row['open'], (float) $row['close'], (float) $row['high'])
            && (float) $row['volume'] >= 0;
    }

    /**
     * @param  Collection<int, MarketCandle>  $hourly
     */
    private function storeBuckets(Asset $asset, Collection $hourly, string $timeframe, int $expectedHours): int
    {
        $bucketSeconds = $expectedHours * 3600;
        $buckets = $hourly->groupBy(function (MarketCandle $candle) use ($bucketSeconds): int {
            $timestamp = $candle->candle_open_time->utc()->getTimestamp();

            return intdiv($timestamp, $bucketSeconds) * $bucketSeconds;
        });
        $rows = [];

        foreach ($buckets as $bucketTimestamp => $children) {
            $ordered = $children->sortBy(fn (MarketCandle $candle): int => $candle->candle_open_time->getTimestamp())->values();
            if (! $this->isCompleteBucket($ordered, (int) $bucketTimestamp, $expectedHours)) {
                continue;
            }

            /** @var MarketCandle $first */
            $first = $ordered->first();
            /** @var MarketCandle $last */
            $last = $ordered->last();
            $openTime = CarbonImmutable::createFromTimestampUTC((int) $bucketTimestamp);
            $closeTime = $openTime->addHours($expectedHours);
            $availableAt = $ordered->max(fn (MarketCandle $candle): int => $candle->available_at->utc()->getTimestamp());
            $firstSeenAt = $ordered->max(fn (MarketCandle $candle): int => $candle->first_seen_at->utc()->getTimestamp());
            $childHashes = $ordered->map(fn (MarketCandle $candle): string => (string) $candle->content_hash)->all();

            $rows[] = [
                'open_time' => $openTime->toIso8601String(),
                'close_time' => $closeTime->toIso8601String(),
                'open' => (float) $first->open,
                'high' => $ordered->max(fn (MarketCandle $candle): float => (float) $candle->high),
                'low' => $ordered->min(fn (MarketCandle $candle): float => (float) $candle->low),
                'close' => (float) $last->close,
                'volume' => $ordered->sum(fn (MarketCandle $candle): float => (float) $candle->volume),
                'turnover_usd' => $ordered->sum(fn (MarketCandle $candle): float => (float) $candle->turnover_usd),
                'available_at' => CarbonImmutable::createFromTimestampUTC((int) $availableAt)->toIso8601String(),
                'first_seen_at' => CarbonImmutable::createFromTimestampUTC((int) $firstSeenAt)->toIso8601String(),
                'is_final' => true,
                'quality_state' => 'valid',
                'metadata' => [
                    'provider' => $this->canonicalSource(),
                    'derived_from' => '1h',
                    'expected_children' => $expectedHours,
                    'child_open_start' => $first->candle_open_time->utc()->toIso8601String(),
                    'child_open_end' => $last->candle_open_time->utc()->toIso8601String(),
                    'child_content_hash' => hash('sha256', implode('|', $childHashes)),
                ],
            ];
        }

        return $this->ingestion->ingest($asset, $timeframe, $rows, $this->canonicalSource());
    }

    /**
     * @param  Collection<int, MarketCandle>  $children
     */
    private function isCompleteBucket(Collection $children, int $bucketTimestamp, int $expectedHours): bool
    {
        if ($children->count() !== $expectedHours) {
            return false;
        }

        foreach ($children->values() as $offset => $candle) {
            $expectedOpen = $bucketTimestamp + ($offset * 3600);
            if ($candle->candle_open_time->utc()->getTimestamp() !== $expectedOpen
                || $candle->candle_close_time?->utc()->getTimestamp() !== $expectedOpen + 3600
                || $candle->available_at === null
                || $candle->first_seen_at === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, Asset>  $assets
     */
    private function recordCoverageGap(Collection $assets, CarbonImmutable $cutoff): void
    {
        $latestByAsset = $assets->mapWithKeys(function (Asset $asset): array {
            $latest = MarketCandle::query()
                ->where('asset_id', $asset->id)
                ->where('source', $this->canonicalSource())
                ->where('timeframe', '4h')
                ->where('is_final', true)
                ->whereIn('quality_state', ['valid', 'verified'])
                ->max('candle_close_time');

            return [$asset->symbol => $latest];
        })->all();

        $incident = DataQualityIncident::query()->firstOrCreate(
            [
                'source' => $this->canonicalSource(),
                'stream' => 'candles:4h:common-universe',
                'incident_type' => 'missing_common_bar',
                'resolved_at' => null,
            ],
            [
                'severity' => 'warning',
                'started_at' => now()->utc(),
                'message' => 'No common final four-hour candle was available for the configured universe.',
            ],
        );
        $incident->update([
            'message' => 'No common final four-hour candle was available for the configured universe.',
            'context_json' => ['evidence_cutoff' => $cutoff->toIso8601String(), 'latest_by_asset' => $latestByAsset],
        ]);
    }
}
