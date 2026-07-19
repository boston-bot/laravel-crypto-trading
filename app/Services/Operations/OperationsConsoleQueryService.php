<?php

namespace App\Services\Operations;

use App\Enums\BrokerType;
use App\Models\ActivityEvent;
use App\Models\Asset;
use App\Models\AssetEvaluation;
use App\Models\BacktestRun;
use App\Models\BrokerAccount;
use App\Models\EngineJob;
use App\Models\MarketCandle;
use App\Models\PaperLedgerEntry;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperPosition;
use App\Models\PaperSession;
use App\Models\PipelineCycle;
use App\Models\RuntimeProcess;
use App\Models\StrategyRun;
use App\Models\StrategyVersion;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;
use App\Services\Analytics\PerformanceProjectionService;
use App\Services\Research\ResearchStatusService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationsConsoleQueryService
{
    public function __construct(
        private readonly RuntimeControlService $runtime,
        private readonly ResearchStatusService $research,
        private readonly PerformanceProjectionService $performance,
    ) {}

    /** @return array<string, mixed> */
    public function overview(int $windowDays = 30): array
    {
        $account = $this->account();
        $session = $account ? $this->activeSession($account->id) : null;
        $cycle = $account ? PipelineCycle::query()->with(['steps', 'evaluations.asset'])->where('broker_account_id', $account->id)->latest('created_at')->first() : null;
        $processes = $this->processes();
        $snapshot = $session ? PaperPortfolioSnapshot::query()->where('paper_session_id', $session->id)->latest('snapshot_time')->first() : null;
        $projection = $this->performance->paper($session, now()->subDays($windowDays), now());

        return [
            'meta' => ['generated_at' => now()->toIso8601String(), 'window_days' => $windowDays, 'mode' => (string) config('broker.mode', 'paper'), 'local_only' => true],
            'account' => $account,
            'paper' => ['session' => $session, 'snapshot' => $snapshot, ...$projection],
            'latest_cycle' => $cycle,
            'latest_explanation' => $cycle?->summary_json['explanation'] ?? ($session ? 'No pipeline cycle has completed for this session yet.' : 'No paper action can occur until a virtual or mirrored paper session is started.'),
            'activity' => ActivityEvent::query()->with('asset')->latest('occurred_at')->limit(12)->get(),
            'assets' => $this->assetPerformance($windowDays, $session, $projection),
            'system' => ['healthy' => $processes->isNotEmpty() && $processes->every(fn (RuntimeProcess $process): bool => $this->processState($process) === 'running'), 'processes' => $processes, 'engine_pending' => EngineJob::query()->whereIn('status', ['pending', 'leased'])->count()],
        ];
    }

    /** @return array<string, mixed> */
    public function strategies(): array
    {
        $account = $this->account();
        $session = $account ? $this->activeSession($account->id) : null;

        return [
            'active_name' => (string) config('trading.strategy_name'),
            'versions' => StrategyVersion::query()->latest('created_at')->get(),
            'backtests' => BacktestRun::query()->with('metrics')->latest('run_started_at')->limit(20)->get(),
            'latest_runs' => StrategyRun::query()->latest('started_at')->limit(20)->get(),
            ...$this->performance->paper($session, now()->subDays(30), now()),
        ];
    }

    /** @return array<string, mixed> */
    public function assets(int $windowDays = 30): array
    {
        $account = $this->account();
        $session = $account ? $this->activeSession($account->id) : null;
        $projection = $this->performance->paper($session, now()->subDays($windowDays), now());

        return ['window_days' => $windowDays, ...$projection, 'assets' => $this->assetPerformance($windowDays, $session, $projection)];
    }

    /** @return array<string, mixed> */
    public function activity(?string $cycleId = null): array
    {
        $query = ActivityEvent::query()->with(['asset', 'cycle'])->latest('occurred_at');
        if ($cycleId !== null) {
            $query->where('pipeline_cycle_id', $cycleId);
        }

        return ['events' => $query->limit(200)->get(), 'cycle' => $cycleId ? PipelineCycle::query()->with(['steps', 'evaluations.asset'])->find($cycleId) : null];
    }

    /** @return array<string, mixed> */
    public function paper(): array
    {
        $account = $this->account();
        $session = $account ? $this->activeSession($account->id) : null;
        $cash = $session ? (float) PaperLedgerEntry::query()->where('paper_session_id', $session->id)->sum('cash_delta') : null;

        return [
            'account' => $account,
            'session' => $session,
            'available_cash' => $cash !== null ? round($cash - (float) $session->reserved_cash, 8) : null,
            'snapshot' => $session ? PaperPortfolioSnapshot::query()->where('paper_session_id', $session->id)->latest('snapshot_time')->first() : null,
            'positions' => $session ? PaperPosition::query()->with('asset')->where('paper_session_id', $session->id)->where('quantity', '>', 0)->get() : [],
            'ledger' => $session ? PaperLedgerEntry::query()->with('asset')->where('paper_session_id', $session->id)->latest('occurred_at')->limit(100)->get() : [],
            'pending_proposals' => TradeDecision::query()->with('asset')->where('broker_account_id', $account?->id)->where('status', 'awaiting_human_approval')->latest()->get(),
            'history' => $account ? PaperSession::query()->where('broker_account_id', $account->id)->latest('started_at')->limit(20)->get() : [],
        ];
    }

    /** @return array<string, mixed> */
    public function operations(): array
    {
        $this->runtime->ensureRegistry();
        $account = $this->account();

        return [
            'account' => $account,
            'paper_session' => $account ? $this->activeSession($account->id) : null,
            'processes' => $this->processes(),
            'cycles' => PipelineCycle::query()->with('steps')->latest('created_at')->limit(30)->get(),
            'engine_jobs' => EngineJob::query()->latest('created_at')->limit(30)->get(),
            'queue' => ['pending' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count()],
            'research' => $this->research->dataHealth(),
            'bootstrap_command' => 'php artisan trading:runtime',
        ];
    }

    /** @return array<string, mixed> */
    public function research(): array
    {
        return ['health' => $this->research->dataHealth(), 'backtests' => $this->research->backtests(), 'calibration' => $this->research->calibration(), 'spreads' => $this->research->spreads()];
    }

    private function account(): ?BrokerAccount
    {
        return BrokerAccount::query()->where('broker', BrokerType::default()->value)->latest('snapshot_at')->latest('id')->first();
    }

    private function activeSession(int $accountId): ?PaperSession
    {
        return PaperSession::query()->where('broker_account_id', $accountId)->where('status', 'active')->latest('id')->first();
    }

    /** @return Collection<int, RuntimeProcess> */
    private function processes(): Collection
    {
        $this->runtime->ensureRegistry();

        return RuntimeProcess::query()->orderBy('name')->get()->each(function (RuntimeProcess $process): void {
            $process->setAttribute('display_state', $this->processState($process));
        });
    }

    private function processState(RuntimeProcess $process): string
    {
        if ($process->heartbeat_at === null || $process->heartbeat_at->lt(now()->subSeconds((int) config('operations.heartbeat_stale_seconds', 30)))) {
            return 'offline';
        }

        return $process->observed_state;
    }

    /** @return array<int, array<string, mixed>> */
    private function assetPerformance(int $windowDays, ?PaperSession $session, array $projection): array
    {
        $start = now()->subDays(max(1, min(365, $windowDays)));
        $startEquity = data_get($projection, 'portfolio_summary.source_values.start_equity');
        $measured = data_get($projection, 'performance.measurement_state') === 'measured';

        return Asset::query()->where('broker', BrokerType::default()->value)->whereIn('symbol', (array) config('research.universe', []))->orderBy('symbol')->get()->map(function (Asset $asset) use ($start, $startEquity, $measured, $session): array {
            $position = $session ? PaperPosition::query()->where('paper_session_id', $session->id)->where('asset_id', $asset->id)->first() : null;
            $attributions = $session ? TradeAttribution::query()
                ->where('paper_session_id', $session->id)
                ->where('asset_id', $asset->id)
                ->whereBetween('attributed_at', [$start, now()])
                ->whereNotNull('realized_return_pct')
                ->get(['realized_pnl', 'realized_return_pct']) : collect();
            $netPnl = $attributions->isEmpty() ? null : (float) $attributions->sum('realized_pnl');
            $linkedReturn = $attributions->isEmpty() ? null : ($attributions->reduce(
                fn (float $linked, TradeAttribution $attribution): float => $linked * (1 + ((float) $attribution->realized_return_pct / 100)),
                1.0,
            ) - 1) * 100;
            $first = MarketCandle::query()->where('asset_id', $asset->id)->where('timeframe', '1h')->where('is_final', true)->where('candle_close_time', '>=', $start)->orderBy('candle_close_time')->first();
            $last = MarketCandle::query()->where('asset_id', $asset->id)->where('timeframe', '1h')->where('is_final', true)->where('candle_close_time', '<=', now())->where('available_at', '<=', now())->latest('candle_close_time')->first();
            $benchmark = $first && $last && (float) $first->close > 0 ? (((float) $last->close / (float) $first->close) - 1) * 100 : null;
            $latestEvaluation = AssetEvaluation::query()->where('asset_id', $asset->id)->latest('as_of')->first();

            return [
                'id' => $asset->id,
                'symbol' => $asset->symbol,
                'net_pnl' => $netPnl !== null ? round($netPnl, 8) : null,
                'pnl_contribution_pct' => $measured && $netPnl !== null && (float) $startEquity > 0 ? round(($netPnl / (float) $startEquity) * 100, 4) : null,
                'held_period_linked_return_pct' => $measured && $linkedReturn !== null ? round($linkedReturn, 4) : null,
                'buy_and_hold_price_return_pct' => $benchmark !== null ? round($benchmark, 4) : null,
                'costs_included' => false,
                'position' => $position,
                'latest_evaluation' => $latestEvaluation,
                'benchmark_available' => $benchmark !== null,
            ];
        })->all();
    }
}
