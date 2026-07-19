<?php

namespace App\Services\Research;

use App\Models\Asset;
use App\Models\AssetEvaluation;
use App\Models\BacktestRun;
use App\Models\DataQualityIncident;
use App\Models\EngineJob;
use App\Models\IngestionCheckpoint;
use App\Models\MarketCandle;
use App\Models\OrderBookSummary;
use App\Models\PipelineCycle;
use App\Models\ResearchManifest;
use App\Models\SpreadObservation;
use App\Services\MarketData\CanonicalMarketEvidenceService;
use Illuminate\Support\Facades\DB;

class ResearchStatusService
{
    public function __construct(private readonly CanonicalMarketEvidenceService $marketEvidence) {}

    public function dataHealth(): array
    {
        $assets = Asset::query()->where('broker', 'coinbase')->whereIn('symbol', config('research.universe'))->get();
        $candleHealth = $assets->map(function (Asset $asset): array {
            $latest = MarketCandle::query()
                ->where('asset_id', $asset->id)
                ->where('source', $this->marketEvidence->canonicalSource())
                ->where('timeframe', '1h')
                ->where('is_final', true)
                ->whereIn('quality_state', ['valid', 'verified'])
                ->latest('candle_open_time')
                ->first();
            $expectedSince = now()->subDays(7)->startOfHour();
            $actual = MarketCandle::query()
                ->where('asset_id', $asset->id)
                ->where('source', $this->marketEvidence->canonicalSource())
                ->where('timeframe', '1h')
                ->where('is_final', true)
                ->whereIn('quality_state', ['valid', 'verified'])
                ->where('candle_open_time', '>=', $expectedSince)
                ->distinct()
                ->count('candle_open_time');

            return [
                'asset' => $asset->symbol,
                'latest_final_bar' => $latest?->candle_close_time?->toIso8601String(),
                'lag_minutes' => $latest?->candle_close_time ? round($latest->candle_close_time->diffInMinutes(now()), 2) : null,
                'missing_bars_7d' => max(0, 168 - $actual),
                'quality_state' => $latest?->quality_state,
            ];
        })->values();
        $commonBar = $this->marketEvidence->latestCommonEligibleBar($assets, now()->utc(), false);
        $expectedEvaluations = $assets->count() * 6;
        $actualEvaluations = AssetEvaluation::query()
            ->where('evaluation_kind', 'trading')
            ->where('logical_bar_close', '>=', now()->subDay())
            ->count();
        $books = OrderBookSummary::query()->select('venue', 'product_id', DB::raw('MAX(bucket_time) AS latest_bucket'), DB::raw('MIN(book_age_ms) AS best_book_age_ms'))
            ->groupBy('venue', 'product_id')->orderBy('venue')->orderBy('product_id')->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'candles' => $candleHealth,
            'market_evidence' => [
                'canonical_source' => $this->marketEvidence->canonicalSource(),
                'latest_common_bar' => $commonBar?->logicalBarClose->toIso8601String(),
                'invalid_rows' => MarketCandle::query()->where('quality_state', 'invalid')->count(),
                'noncanonical_rows' => MarketCandle::query()->where('source', '!=', $this->marketEvidence->canonicalSource())->count(),
            ],
            'evaluation_coverage_24h' => [
                'expected' => $expectedEvaluations,
                'actual' => $actualEvaluations,
                'coverage_pct' => $expectedEvaluations > 0 ? round(($actualEvaluations / $expectedEvaluations) * 100, 2) : 0.0,
            ],
            'cycle_integrity' => [
                'completed_with_nonterminal_steps' => PipelineCycle::query()
                    ->where('status', 'completed')
                    ->whereHas('steps', fn ($query) => $query->whereIn('status', ['queued', 'running', 'waiting_engine']))
                    ->count(),
                'duplicate_strategy_runs' => DB::table('strategy_runs')->where('status', 'duplicate_bar')->count(),
            ],
            'action_suppression_24h' => AssetEvaluation::query()
                ->where('created_at', '>=', now()->subDay())
                ->whereNotNull('action_suppression_reason')
                ->select('action_suppression_reason', DB::raw('COUNT(*) AS total'))
                ->groupBy('action_suppression_reason')
                ->pluck('total', 'action_suppression_reason'),
            'checkpoints' => IngestionCheckpoint::query()->orderBy('source')->orderBy('stream')->get(),
            'open_incidents' => DataQualityIncident::query()->whereNull('resolved_at')->latest('started_at')->get(),
            'books' => $books,
            'engine_queue' => EngineJob::query()->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status'),
            'latest_manifest' => ResearchManifest::query()->latest('frozen_at')->first(),
            'spread_shadow' => [
                'execution_enabled' => false,
                'observations_24h' => SpreadObservation::query()->where('observed_at', '>=', now()->subDay())->count(),
                'executable_24h' => SpreadObservation::query()->where('observed_at', '>=', now()->subDay())->where('classification', 'executable')->count(),
                'max_net_edge_bps_24h' => SpreadObservation::query()->where('observed_at', '>=', now()->subDay())->max('net_edge_bps'),
            ],
        ];
    }

    public function backtests(int $limit = 25): array
    {
        return BacktestRun::query()->with('metrics')->latest('run_started_at')->limit(max(1, min(100, $limit)))->get()->all();
    }

    public function calibration(int $limit = 10): array
    {
        return BacktestRun::query()->whereNotNull('result_json')->latest('run_started_at')->limit(max(1, min(50, $limit)))->get()
            ->map(fn (BacktestRun $run): array => [
                'backtest_run_id' => $run->id,
                'strategy_name' => $run->strategy_name,
                'completed_at' => $run->run_completed_at?->toIso8601String(),
                'calibration' => data_get($run->result_json, 'calibration'),
                'folds' => data_get($run->result_json, 'folds'),
                'sentiment_ablation' => data_get($run->result_json, 'sentiment_ablation'),
            ])->all();
    }

    public function spreads(int $hours = 24): array
    {
        $since = now()->subHours(max(1, min(24 * 30, $hours)));

        return [
            'shadow_only' => true,
            'execution_enabled' => false,
            'window_start' => $since->toIso8601String(),
            'classifications' => SpreadObservation::query()->where('observed_at', '>=', $since)
                ->select('classification', DB::raw('COUNT(*) AS total'), DB::raw('MAX(net_edge_bps) AS max_net_edge_bps'), DB::raw('AVG(net_edge_bps) AS avg_net_edge_bps'))
                ->groupBy('classification')->get(),
            'by_product' => SpreadObservation::query()->where('observed_at', '>=', $since)
                ->select('product_id', DB::raw('COUNT(*) AS observations'), DB::raw('SUM(CASE WHEN classification = \'executable\' THEN 1 ELSE 0 END) AS executable_count'), DB::raw('MAX(net_edge_bps) AS max_net_edge_bps'))
                ->groupBy('product_id')->get(),
            'recent' => SpreadObservation::query()->latest('observed_at')->limit(100)->get(),
        ];
    }
}
