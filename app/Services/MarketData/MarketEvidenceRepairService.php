<?php

namespace App\Services\MarketData;

use App\Models\Asset;
use App\Models\DataQualityIncident;
use App\Models\MarketCandle;
use App\Models\MarketCandleRevision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MarketEvidenceRepairService
{
    public function __construct(
        private readonly CoinbaseMarketDataClient $client,
        private readonly CandleIngestionService $ingestion,
        private readonly CanonicalMarketEvidenceService $evidence,
    ) {}

    /**
     * @param  array<int, string>  $symbols
     * @return array{generated_at: string, canonical_source: string, assets: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function plan(array $symbols = [], ?CarbonInterface $start = null, ?CarbonInterface $end = null): array
    {
        $query = Asset::query()->where('broker', 'coinbase')->where('is_enabled', true)->orderBy('symbol');
        if ($symbols !== []) {
            $query->whereIn('symbol', array_map('strtoupper', $symbols));
        } else {
            $query->whereIn('symbol', (array) config('research.universe', []));
        }

        $assets = [];
        $totals = ['affected_rows' => 0, 'missing_hours' => 0, 'batches' => 0];
        foreach ($query->get() as $asset) {
            $assetPlan = $this->planAsset($asset, $start, $end);
            if ($assetPlan === null) {
                continue;
            }
            $assets[] = $assetPlan;
            $totals['affected_rows'] += (int) $assetPlan['affected_rows'];
            $totals['missing_hours'] += (int) $assetPlan['missing_hours'];
            $totals['batches'] += count($assetPlan['batches']);
        }

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'canonical_source' => $this->evidence->canonicalSource(),
            'assets' => $assets,
            'totals' => $totals,
        ];
    }

    /**
     * @param  array{assets: array<int, array<string, mixed>>}  $plan
     * @return array{batches_completed: int, rows_invalidated: int, hourly_ingested: int, aggregates_rebuilt: int}
     */
    public function execute(array $plan): array
    {
        $result = ['batches_completed' => 0, 'rows_invalidated' => 0, 'hourly_ingested' => 0, 'aggregates_rebuilt' => 0];
        foreach ($plan['assets'] as $assetPlan) {
            $asset = Asset::query()->findOrFail((int) $assetPlan['asset_id']);
            foreach ((array) $assetPlan['batches'] as $batch) {
                $batchStart = CarbonImmutable::parse((string) $batch['start'], 'UTC')->utc();
                $batchEnd = CarbonImmutable::parse((string) $batch['end'], 'UTC')->utc();
                if (! $this->batchNeedsRepair($asset, $batchStart, $batchEnd)) {
                    continue;
                }

                try {
                    $hourly = $this->client->getHourlyCandlesRange($asset->symbol, $batchStart, $batchEnd);
                    $missingHours = $this->missingHourlyOpens($hourly, $batchStart, $batchEnd);
                    $expectedHours = (int) abs($batchStart->diffInHours($batchEnd));
                    if ($hourly === [] || count($missingHours) > max(24, (int) ceil($expectedHours * 0.05))) {
                        throw new RuntimeException('Coinbase repair response was too sparse to trust: '.count($missingHours)." of {$expectedHours} hours missing.");
                    }

                    $batchResult = DB::transaction(function () use ($asset, $batchStart, $batchEnd, $hourly, $missingHours): array {
                        $corrupt = MarketCandle::query()
                            ->where('asset_id', $asset->id)
                            ->whereIn('source', [$this->evidence->canonicalSource(), 'coinbase_public'])
                            ->where('candle_open_time', '>=', $batchStart)
                            ->where('candle_open_time', '<', $batchEnd)
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get()
                            ->filter(fn (MarketCandle $candle): bool => $this->reasons($candle) !== [])
                            ->values();
                        foreach ($corrupt as $candle) {
                            $this->archiveAndInvalidate($candle);
                        }

                        $hourlyIngested = $this->ingestion->ingest($asset, '1h', $hourly, $this->evidence->canonicalSource());
                        $derived = $this->evidence->rebuildDerivedForAsset($asset, $batchStart, $batchEnd);
                        $this->assertStoredHourlyRange($asset, $batchStart, $batchEnd, count($missingHours));

                        if ($missingHours !== []) {
                            DataQualityIncident::query()->create([
                                'source' => $this->evidence->canonicalSource(),
                                'stream' => 'candles:repair:'.$asset->symbol,
                                'severity' => 'warning',
                                'incident_type' => 'market_evidence_source_gap',
                                'started_at' => now()->utc(),
                                'message' => 'Coinbase omitted historical hours after narrow retries; no prices were synthesized.',
                                'context_json' => [
                                    'start' => $batchStart->toIso8601String(),
                                    'end' => $batchEnd->toIso8601String(),
                                    'missing_count' => count($missingHours),
                                    'missing_open_times' => array_slice($missingHours, 0, 100),
                                ],
                            ]);
                        }

                        $this->resolvePriorFailure($asset, $batchStart, $batchEnd);

                        DataQualityIncident::query()->create([
                            'source' => $this->evidence->canonicalSource(),
                            'stream' => 'candles:repair:'.$asset->symbol,
                            'severity' => 'info',
                            'incident_type' => 'market_evidence_repaired',
                            'started_at' => now()->utc(),
                            'resolved_at' => now()->utc(),
                            'message' => 'A bounded market-evidence repair batch completed and passed coverage validation.',
                            'context_json' => [
                                'start' => $batchStart->toIso8601String(),
                                'end' => $batchEnd->toIso8601String(),
                                'invalidated' => $corrupt->count(),
                                'hourly_ingested' => $hourlyIngested,
                                'derived' => $derived,
                                'missing_source_hours' => count($missingHours),
                            ],
                        ]);

                        return [
                            'invalidated' => $corrupt->count(),
                            'hourly_ingested' => $hourlyIngested,
                            'aggregates_rebuilt' => array_sum($derived),
                        ];
                    }, 3);

                    $result['batches_completed']++;
                    $result['rows_invalidated'] += $batchResult['invalidated'];
                    $result['hourly_ingested'] += $batchResult['hourly_ingested'];
                    $result['aggregates_rebuilt'] += $batchResult['aggregates_rebuilt'];
                } catch (Throwable $exception) {
                    DataQualityIncident::query()->create([
                        'source' => $this->evidence->canonicalSource(),
                        'stream' => 'candles:repair:'.$asset->symbol,
                        'severity' => 'error',
                        'incident_type' => 'market_evidence_repair_failed',
                        'started_at' => now()->utc(),
                        'message' => 'A bounded market-evidence repair batch rolled back: '.$exception->getMessage(),
                        'context_json' => ['start' => $batchStart->toIso8601String(), 'end' => $batchEnd->toIso8601String()],
                    ]);

                    throw $exception;
                }
            }
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function planAsset(Asset $asset, ?CarbonInterface $start, ?CarbonInterface $end): ?array
    {
        $baseQuery = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->whereIn('source', [$this->evidence->canonicalSource(), 'coinbase_public'])
            ->when($start !== null, fn ($query) => $query->where('candle_open_time', '>=', CarbonImmutable::instance($start)->utc()))
            ->when($end !== null, fn ($query) => $query->where('candle_open_time', '<', CarbonImmutable::instance($end)->utc()));

        $bounds = (clone $baseQuery)
            ->selectRaw('MIN(candle_open_time) AS first_open, MAX(candle_close_time) AS last_close')
            ->first();
        $rangeStartValue = $start ?? $bounds?->first_open;
        $rangeEndValue = $end ?? $bounds?->last_close;
        if ($rangeStartValue === null || $rangeEndValue === null) {
            return null;
        }
        $rangeStart = CarbonImmutable::parse((string) $rangeStartValue, 'UTC')->utc()->startOfHour();
        $rangeEnd = CarbonImmutable::parse((string) $rangeEndValue, 'UTC')->utc()->startOfHour();
        if (! $rangeStart->lessThan($rangeEnd)) {
            return null;
        }
        $rangeEnd = CarbonImmutable::instance($rangeEnd->min(now()->utc()->startOfHour()))->utc();
        if (! $rangeStart->lessThan($rangeEnd)) {
            return null;
        }

        $reasons = [];
        $affectedRows = 0;
        foreach ((clone $baseQuery)->orderBy('id')->cursor() as $row) {
            $rowReasons = $this->reasons($row);
            if ($rowReasons === []) {
                continue;
            }
            $affectedRows++;
            foreach ($rowReasons as $reason) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        $presentHours = 0;
        $hourlyQuery = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->where('source', $this->evidence->canonicalSource())
            ->where('timeframe', '1h')
            ->whereIn('quality_state', ['valid', 'verified'])
            ->where('is_final', true)
            ->where('candle_open_time', '>=', $rangeStart)
            ->where('candle_open_time', '<', $rangeEnd)
            ->orderBy('candle_open_time');
        foreach ($hourlyQuery->cursor() as $candle) {
            if ($candle->candle_open_time->utc()->getTimestamp() % 3600 === 0
                && $candle->candle_close_time !== null
                && $candle->candle_close_time->utc()->getTimestamp() - $candle->candle_open_time->utc()->getTimestamp() === 3600) {
                $presentHours++;
            }
        }
        $expectedHours = (int) abs($rangeStart->diffInHours($rangeEnd));
        $missingHours = max(0, $expectedHours - $presentHours);

        if ($affectedRows === 0 && $missingHours === 0) {
            return null;
        }

        return [
            'asset_id' => $asset->id,
            'symbol' => $asset->symbol,
            'start' => $rangeStart->toIso8601String(),
            'end' => $rangeEnd->toIso8601String(),
            'affected_rows' => $affectedRows,
            'reasons' => $reasons,
            'missing_hours' => $missingHours,
            'batches' => $this->batches($rangeStart, $rangeEnd),
        ];
    }

    /** @return array<int, string> */
    private function reasons(MarketCandle $candle): array
    {
        if ($candle->quality_state === 'invalid' && data_get($candle->metadata_json, 'repair.reason') === 'market_evidence_repair') {
            return [];
        }

        $interval = match ($candle->timeframe) {
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
            default => null,
        };
        if ($interval === null) {
            return ['unsupported_timeframe'];
        }

        $reasons = [];
        if ($candle->source !== $this->evidence->canonicalSource()) {
            $reasons[] = 'noncanonical_source';
        }
        if ($candle->candle_open_time->utc()->getTimestamp() % $interval !== 0) {
            $reasons[] = 'utc_bucket_misalignment';
        }
        if ($candle->candle_close_time === null || $candle->candle_close_time->utc()->getTimestamp() - $candle->candle_open_time->utc()->getTimestamp() !== $interval) {
            $reasons[] = 'interval_mismatch';
        }
        if ($candle->first_seen_at === null) {
            $reasons[] = 'missing_first_seen_at';
        }
        if (in_array($candle->timeframe, ['4h', '1d'], true) && data_get($candle->metadata_json, 'derived_from') !== '1h') {
            $reasons[] = 'nonderived_aggregate';
        }

        return $reasons;
    }

    /** @return array<int, array{start: string, end: string}> */
    private function batches(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $batches = [];
        for ($cursor = $start; $cursor->lessThan($end); $cursor = $batchEnd) {
            $batchEnd = $cursor->addDays(30);
            if ($batchEnd->greaterThan($end)) {
                $batchEnd = $end;
            }
            $batches[] = ['start' => $cursor->toIso8601String(), 'end' => $batchEnd->toIso8601String()];
        }

        return $batches;
    }

    private function archiveAndInvalidate(MarketCandle $candle): void
    {
        $firstSeen = $candle->first_seen_at ?? $candle->ingested_at ?? $candle->created_at;
        $available = $candle->available_at ?? $candle->candle_close_time ?? $candle->created_at;
        MarketCandleRevision::query()->firstOrCreate(
            ['market_candle_id' => $candle->id, 'revision' => (int) $candle->source_revision],
            [
                'content_hash' => (string) ($candle->content_hash ?: hash('sha256', json_encode($candle->only(['open', 'high', 'low', 'close', 'volume']), JSON_THROW_ON_ERROR))),
                'first_seen_at' => $firstSeen,
                'available_at' => $available,
                'values_json' => $candle->only(['open', 'high', 'low', 'close', 'volume', 'turnover_usd', 'candle_close_time', 'is_final', 'quality_state']),
                'change_reason' => 'market_evidence_repair',
            ],
        );

        $candle->update([
            'source_revision' => (int) $candle->source_revision + 1,
            'quality_state' => 'invalid',
            'is_final' => false,
            'available_at' => now()->utc(),
            'metadata_json' => array_merge((array) $candle->metadata_json, ['repair' => ['invalidated_at' => now()->utc()->toIso8601String(), 'reason' => 'market_evidence_repair']]),
        ]);
    }

    private function resolvePriorFailure(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): void
    {
        DataQualityIncident::query()
            ->where('stream', 'candles:repair:'.$asset->symbol)
            ->where('incident_type', 'market_evidence_repair_failed')
            ->whereNull('resolved_at')
            ->get()
            ->filter(fn (DataQualityIncident $incident): bool => data_get($incident->context_json, 'start') === $start->toIso8601String()
                && data_get($incident->context_json, 'end') === $end->toIso8601String())
            ->each->update([
                'resolved_at' => now()->utc(),
                'message' => 'The previously failed repair batch completed successfully on retry.',
            ]);
    }

    private function batchNeedsRepair(Asset $asset, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $expected = (int) abs($start->diffInHours($end));
        $stored = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->where('source', $this->evidence->canonicalSource())
            ->where('timeframe', '1h')
            ->where('is_final', true)
            ->whereIn('quality_state', ['valid', 'verified'])
            ->where('candle_open_time', '>=', $start)
            ->where('candle_open_time', '<', $end)
            ->count();
        if ($stored !== $expected) {
            return true;
        }

        foreach (MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->whereIn('source', [$this->evidence->canonicalSource(), 'coinbase_public'])
            ->where('candle_open_time', '>=', $start)
            ->where('candle_open_time', '<', $end)
            ->cursor() as $candle) {
            if ($this->reasons($candle) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hourly
     * @return array<int, string>
     */
    private function missingHourlyOpens(array $hourly, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $opens = collect($hourly)->mapWithKeys(fn (array $row): array => [CarbonImmutable::parse((string) $row['open_time'])->utc()->getTimestamp() => true]);
        $missing = [];
        for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addHour()) {
            if (! isset($opens[$cursor->getTimestamp()])) {
                $missing[] = $cursor->toIso8601String();
            }
        }

        return $missing;
    }

    private function assertStoredHourlyRange(Asset $asset, CarbonImmutable $start, CarbonImmutable $end, int $missingHours): void
    {
        $count = MarketCandle::query()
            ->where('asset_id', $asset->id)
            ->where('source', $this->evidence->canonicalSource())
            ->where('timeframe', '1h')
            ->where('is_final', true)
            ->whereIn('quality_state', ['valid', 'verified'])
            ->where('candle_open_time', '>=', $start)
            ->where('candle_open_time', '<', $end)
            ->count();
        $expected = (int) abs($start->diffInHours($end)) - $missingHours;
        if ($count !== $expected) {
            throw new RuntimeException("Stored hourly validation expected {$expected} rows and found {$count}.");
        }
    }
}
