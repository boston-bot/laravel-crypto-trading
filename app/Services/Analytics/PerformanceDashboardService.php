<?php

namespace App\Services\Analytics;

use App\Enums\BrokerType;
use App\Models\BacktestRun;
use App\Models\BrokerAccount;
use App\Models\PaperOrderEvent;
use App\Models\PaperPortfolioSnapshot;
use App\Models\RiskEvent;
use App\Models\TradeAttribution;
use App\Services\Research\ResearchStatusService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PerformanceDashboardService
{
    public function build(
        string $broker,
        ?int $accountId,
        int $windowDays = 30,
        int $limitTrades = 100,
        int $limitRiskEvents = 50,
    ): ?array {
        $brokerValue = BrokerType::tryFrom($broker)?->value ?? BrokerType::default()->value;
        $account = $this->resolveAccount($brokerValue, $accountId);
        if ($account === null) {
            return null;
        }

        $windowDays = $this->normalizeWindowDays($windowDays);
        $windowStart = now()->subDays($windowDays);
        $limitTrades = max(1, min(300, $limitTrades));
        $limitRiskEvents = max(1, min(300, $limitRiskEvents));

        $snapshots = $account->paperPortfolioSnapshots()
            ->where('snapshot_time', '>=', $windowStart)
            ->orderBy('snapshot_time')
            ->get([
                'snapshot_time',
                'equity',
                'cash',
                'invested_value',
                'realized_pnl',
                'unrealized_pnl',
                'gross_exposure_pct',
                'heat_score',
                'drawdown_pct',
            ]);

        $equitySeries = $this->downsampleSeries($snapshots->map(
            fn (object $row): array => [
                't' => $row->snapshot_time->toIso8601String(),
                'v' => (float) $row->equity,
            ]
        )->all());

        $drawdownSeries = $this->downsampleSeries($snapshots->map(
            fn (object $row): array => [
                't' => $row->snapshot_time->toIso8601String(),
                'v' => (float) $row->drawdown_pct,
            ]
        )->all());

        $exposureSeries = $this->downsampleSeries($snapshots->map(
            fn (object $row): array => [
                't' => $row->snapshot_time->toIso8601String(),
                'v' => (float) $row->gross_exposure_pct,
            ]
        )->all());

        $heatSeries = $this->downsampleSeries($snapshots->map(
            fn (object $row): array => [
                't' => $row->snapshot_time->toIso8601String(),
                'v' => (float) $row->heat_score,
            ]
        )->all());

        $latestSnapshot = $snapshots->last();
        $tradeStats = $this->tradeStats($account->id, $windowStart);
        $riskStats = $this->riskStats($account->id, $windowStart);
        $operations = $this->operationsStats($account->id, $windowStart, $account, $latestSnapshot, $riskStats);
        $kpis = $this->paperKpis($equitySeries, $drawdownSeries, $tradeStats, $latestSnapshot, $operations);
        $backtest = $this->latestBacktestMetrics();

        return [
            'meta' => [
                'broker' => $brokerValue,
                'account_id' => $account->id,
                'window_days' => $windowDays,
                'window_start' => $windowStart->format(\DateTimeInterface::ATOM),
                'window_end' => now()->toIso8601String(),
                'generated_at' => now()->toIso8601String(),
            ],
            'account' => [
                'id' => $account->id,
                'external_account_id' => $account->external_account_id,
                'status' => $account->status,
                'currency' => $account->currency,
                'snapshot_at' => $account->snapshot_at?->toIso8601String(),
                'equity' => $this->roundOrNull($this->nullableFloat($account->equity), 8),
                'cash_balance' => $this->roundOrNull($this->nullableFloat($account->cash_balance), 8),
                'buying_power' => $this->roundOrNull($this->nullableFloat($account->buying_power), 8),
            ],
            'window_summary' => [
                'snapshot_count' => $snapshots->count(),
                'trade_count' => $tradeStats['trade_count'],
                'risk_event_count' => $riskStats['risk_event_count'],
                'first_snapshot_at' => $snapshots->first()?->snapshot_time?->toIso8601String(),
                'last_snapshot_at' => $latestSnapshot?->snapshot_time?->toIso8601String(),
                'last_trade_at' => $tradeStats['last_trade_at'],
                'last_risk_event_at' => $riskStats['last_risk_event_at'],
                'paper_snapshot_staleness_minutes' => $operations['paper_snapshot_staleness_minutes'],
                'account_snapshot_staleness_minutes' => $operations['account_snapshot_staleness_minutes'],
            ],
            'kpis' => $kpis,
            'paper_series' => [
                'equity' => $equitySeries,
                'drawdown_pct' => $drawdownSeries,
                'gross_exposure_pct' => $exposureSeries,
                'heat_score' => $heatSeries,
            ],
            'backtest_latest' => $backtest,
            'comparison' => [
                'paper' => [
                    'hit_rate_pct' => $kpis['paper_hit_rate_pct'],
                    'max_drawdown_pct' => $kpis['paper_max_drawdown_pct'],
                    'profit_factor' => $kpis['paper_profit_factor'],
                ],
                'backtest' => [
                    'win_rate_pct' => data_get($backtest, 'metrics.win_rate_pct'),
                    'max_drawdown_pct' => data_get($backtest, 'metrics.max_drawdown_pct'),
                    'profit_factor' => data_get($backtest, 'metrics.profit_factor'),
                ],
            ],
            'operations' => $operations,
            'recent_trades' => $this->recentTrades($account->id, $windowStart, $limitTrades),
            'recent_risk_events' => $this->recentRiskEvents($account->id, $windowStart, $limitRiskEvents),
            'research' => app(ResearchStatusService::class)->dataHealth(),
        ];
    }

    private function resolveAccount(string $broker, ?int $accountId): ?BrokerAccount
    {
        $query = BrokerAccount::query()
            ->where('broker', $broker);

        if ($accountId !== null) {
            return $query->whereKey($accountId)->first();
        }

        return $query
            ->latest('snapshot_at')
            ->latest('id')
            ->first();
    }

    private function normalizeWindowDays(int $windowDays): int
    {
        $allowed = [7, 30, 90];

        return in_array($windowDays, $allowed, true) ? $windowDays : 30;
    }

    /**
     * @param  array<int, array{t: string, v: float}>  $series
     * @return array<int, array{t: string, v: float}>
     */
    private function downsampleSeries(array $series, int $maxPoints = 2000): array
    {
        $count = count($series);
        if ($count <= $maxPoints || $maxPoints < 2) {
            return $series;
        }

        $sampled = [];
        $step = ($count - 1) / ($maxPoints - 1);

        for ($i = 0; $i < $maxPoints; $i++) {
            $index = (int) round($i * $step);
            $sampled[] = $series[$index];
        }

        return $sampled;
    }

    /**
     * @param  array<int, array{t: string, v: float}>  $equitySeries
     * @param  array<int, array{t: string, v: float}>  $drawdownSeries
     * @return array<string, float|int|null>
     */
    private function paperKpis(
        array $equitySeries,
        array $drawdownSeries,
        array $tradeStats,
        ?PaperPortfolioSnapshot $latestSnapshot,
        array $operations,
    ): array {
        $snapshotRealizedPnl = $this->nullableFloat($latestSnapshot?->realized_pnl ?? null);

        $paperEquity = $equitySeries !== []
            ? (float) ($equitySeries[count($equitySeries) - 1]['v'] ?? 0.0)
            : null;

        $paperReturnPct = null;
        if (count($equitySeries) >= 2) {
            $startEquity = (float) ($equitySeries[0]['v'] ?? 0.0);
            $endEquity = (float) ($equitySeries[count($equitySeries) - 1]['v'] ?? 0.0);
            if ($startEquity > 0) {
                $paperReturnPct = (($endEquity - $startEquity) / $startEquity) * 100;
            }
        }

        $maxDrawdownPct = null;
        if ($drawdownSeries !== []) {
            $maxDrawdownPct = (float) collect($drawdownSeries)->max('v');
        }

        return [
            'paper_equity' => $this->roundOrNull($paperEquity, 8),
            'paper_return_pct' => $this->roundOrNull($paperReturnPct, 4),
            'paper_max_drawdown_pct' => $this->roundOrNull($maxDrawdownPct, 4),
            'paper_current_drawdown_pct' => $this->roundOrNull($drawdownSeries !== [] ? (float) $drawdownSeries[count($drawdownSeries) - 1]['v'] : null, 4),
            'paper_hit_rate_pct' => $this->roundOrNull($tradeStats['hit_rate_pct'], 4),
            'paper_expectancy' => $this->roundOrNull($tradeStats['expectancy'], 8),
            'paper_trade_count' => $tradeStats['trade_count'],
            'paper_wins' => $tradeStats['wins'],
            'paper_losses' => $tradeStats['losses'],
            'paper_profit_factor' => $this->roundOrNull($tradeStats['profit_factor'], 6),
            'paper_total_realized_pnl' => $this->roundOrNull($tradeStats['total_realized_pnl'], 8),
            'paper_avg_hold_hours' => $this->roundOrNull($tradeStats['avg_hold_hours'], 4),
            'paper_avg_win_pnl' => $this->roundOrNull($tradeStats['avg_win_pnl'], 8),
            'paper_avg_loss_pnl' => $this->roundOrNull($tradeStats['avg_loss_pnl'], 8),
            'paper_avg_mae_pct' => $this->roundOrNull($tradeStats['avg_mae_pct'], 6),
            'paper_avg_mfe_pct' => $this->roundOrNull($tradeStats['avg_mfe_pct'], 6),
            'paper_cash' => $this->roundOrNull($this->nullableFloat($latestSnapshot?->cash ?? null), 8),
            'paper_invested_value' => $this->roundOrNull($this->nullableFloat($latestSnapshot?->invested_value ?? null), 8),
            'paper_realized_pnl' => $this->roundOrNull($snapshotRealizedPnl ?? $tradeStats['total_realized_pnl'], 8),
            'paper_unrealized_pnl' => $this->roundOrNull($this->nullableFloat($latestSnapshot?->unrealized_pnl ?? null), 8),
            'paper_gross_exposure_pct' => $this->roundOrNull($this->nullableFloat($latestSnapshot?->gross_exposure_pct ?? null), 4),
            'paper_heat_score' => $this->roundOrNull($this->nullableFloat($latestSnapshot?->heat_score ?? null), 4),
            'operations_avg_slippage_bps' => $this->roundOrNull($operations['avg_slippage_bps'], 4),
            'operations_rejected_order_rate_pct' => $this->roundOrNull($operations['rejected_order_rate_pct'], 4),
            'operations_critical_risk_events' => $operations['critical_risk_events'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestBacktestMetrics(): array
    {
        $strategyName = (string) config('trading.strategy_name', '');

        $query = BacktestRun::query()
            ->where('status', 'completed');

        if ($strategyName !== '') {
            $query->where('strategy_name', $strategyName);
        }

        $run = (clone $query)
            ->with('metrics')
            ->latest('run_completed_at')
            ->first();

        if ($run === null && $strategyName !== '') {
            $run = BacktestRun::query()
                ->where('status', 'completed')
                ->with('metrics')
                ->latest('run_completed_at')
                ->first();
        }

        if ($run === null) {
            return [
                'run_id' => null,
                'completed_at' => null,
                'metrics' => [
                    'sharpe' => null,
                    'max_drawdown_pct' => null,
                    'win_rate_pct' => null,
                    'profit_factor' => null,
                ],
            ];
        }

        $metricAliases = (array) config('scorecard.backtest.metric_aliases', []);
        $metricMap = $this->metricMap($run->metrics);

        $sharpe = $this->metricByAlias($metricMap, (array) ($metricAliases['sharpe'] ?? []));
        $drawdown = $this->metricByAlias($metricMap, (array) ($metricAliases['max_drawdown_pct'] ?? []));
        $winRate = $this->metricByAlias($metricMap, (array) ($metricAliases['win_rate_pct'] ?? []));
        $profitFactor = $this->metricByAlias($metricMap, (array) ($metricAliases['profit_factor'] ?? []));

        return [
            'run_id' => $run->id,
            'completed_at' => $run->run_completed_at?->toIso8601String(),
            'metrics' => [
                'sharpe' => $this->roundOrNull($sharpe, 6),
                'max_drawdown_pct' => $this->roundOrNull($drawdown !== null ? abs($this->normalizePercent($drawdown)) : null, 6),
                'win_rate_pct' => $this->roundOrNull($winRate !== null ? $this->normalizePercent($winRate) : null, 6),
                'profit_factor' => $this->roundOrNull($profitFactor, 6),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentTrades(int $accountId, \DateTimeInterface $windowStart, int $limit): array
    {
        return $this->baseTradeQuery($accountId, $windowStart)
            ->with(['asset:id,symbol'])
            ->latest('attributed_at')
            ->limit($limit)
            ->get()
            ->map(fn (TradeAttribution $trade): array => [
                'trade_decision_id' => $trade->trade_decision_id,
                'asset_symbol' => $trade->asset?->symbol,
                'attributed_at' => $trade->attributed_at?->toIso8601String(),
                'expected_probability' => $this->roundOrNull($this->nullableFloat($trade->expected_probability), 6),
                'expected_expectancy' => $this->roundOrNull($this->nullableFloat($trade->expected_expectancy), 8),
                'realized_return_pct' => $this->roundOrNull($this->nullableFloat($trade->realized_return_pct), 8),
                'realized_pnl' => $this->roundOrNull($this->nullableFloat($trade->realized_pnl), 8),
                'hold_hours' => $trade->hold_hours,
                'mae_pct' => $this->roundOrNull($this->nullableFloat($trade->mae_pct), 8),
                'mfe_pct' => $this->roundOrNull($this->nullableFloat($trade->mfe_pct), 8),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentRiskEvents(int $accountId, \DateTimeInterface $windowStart, int $limit): array
    {
        return $this->baseRiskEventQuery($accountId, $windowStart)
            ->latest('triggered_at')
            ->limit($limit)
            ->get()
            ->map(fn (RiskEvent $event): array => [
                'id' => $event->id,
                'severity' => $event->severity,
                'event_type' => $event->event_type,
                'message' => $event->message,
                'triggered_at' => $event->triggered_at?->toIso8601String(),
                'asset_id' => $event->asset_id,
                'trade_decision_id' => $event->trade_decision_id,
                'broker_order_id' => $event->broker_order_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, float|int|null|string>
     */
    private function tradeStats(int $accountId, \DateTimeInterface $windowStart): array
    {
        $trades = $this->baseTradeQuery($accountId, $windowStart)->get([
            'realized_pnl',
            'hold_hours',
            'mae_pct',
            'mfe_pct',
            'attributed_at',
        ]);

        $tradeCount = $trades->count();
        $wins = $trades->filter(fn (TradeAttribution $row): bool => (float) $row->realized_pnl > 0)->count();
        $losses = $trades->filter(fn (TradeAttribution $row): bool => (float) $row->realized_pnl < 0)->count();
        $grossProfit = (float) $trades->sum(fn (TradeAttribution $row): float => max(0.0, (float) $row->realized_pnl));
        $grossLossAbs = (float) $trades->sum(fn (TradeAttribution $row): float => abs(min(0.0, (float) $row->realized_pnl)));
        $totalRealizedPnl = $tradeCount > 0
            ? (float) $trades->sum(fn (TradeAttribution $row): float => (float) $row->realized_pnl)
            : null;
        $hitRatePct = $tradeCount > 0
            ? ($wins / $tradeCount) * 100
            : null;
        $expectancy = $tradeCount > 0
            ? (float) ($totalRealizedPnl / $tradeCount)
            : null;
        $profitFactor = $grossLossAbs > 0
            ? $grossProfit / $grossLossAbs
            : null;

        $winningTrades = $trades->filter(fn (TradeAttribution $row): bool => (float) $row->realized_pnl > 0);
        $losingTrades = $trades->filter(fn (TradeAttribution $row): bool => (float) $row->realized_pnl < 0);
        $withHoldHours = $trades->filter(fn (TradeAttribution $row): bool => $row->hold_hours !== null);
        $withMae = $trades->filter(fn (TradeAttribution $row): bool => $row->mae_pct !== null);
        $withMfe = $trades->filter(fn (TradeAttribution $row): bool => $row->mfe_pct !== null);
        $lastTradeAt = $trades->max(fn (TradeAttribution $row): ?string => $row->attributed_at?->toIso8601String());

        return [
            'trade_count' => $tradeCount,
            'wins' => $wins,
            'losses' => $losses,
            'total_realized_pnl' => $totalRealizedPnl,
            'hit_rate_pct' => $hitRatePct,
            'expectancy' => $expectancy,
            'profit_factor' => $profitFactor,
            'avg_hold_hours' => $withHoldHours->count() > 0
                ? (float) $withHoldHours->avg(fn (TradeAttribution $row): float => (float) $row->hold_hours)
                : null,
            'avg_win_pnl' => $winningTrades->count() > 0
                ? (float) $winningTrades->avg(fn (TradeAttribution $row): float => (float) $row->realized_pnl)
                : null,
            'avg_loss_pnl' => $losingTrades->count() > 0
                ? (float) $losingTrades->avg(fn (TradeAttribution $row): float => (float) $row->realized_pnl)
                : null,
            'avg_mae_pct' => $withMae->count() > 0
                ? (float) $withMae->avg(fn (TradeAttribution $row): float => abs((float) $row->mae_pct))
                : null,
            'avg_mfe_pct' => $withMfe->count() > 0
                ? (float) $withMfe->avg(fn (TradeAttribution $row): float => (float) $row->mfe_pct)
                : null,
            'last_trade_at' => $lastTradeAt,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function riskStats(int $accountId, \DateTimeInterface $windowStart): array
    {
        $baseQuery = $this->baseRiskEventQuery($accountId, $windowStart);

        $rawCounts = (clone $baseQuery)
            ->selectRaw('LOWER(severity) as severity_key, COUNT(*) as aggregate')
            ->groupByRaw('LOWER(severity)')
            ->pluck('aggregate', 'severity_key');
        $riskEventCount = (int) $rawCounts->sum();
        $lastRiskEventAt = (clone $baseQuery)
            ->latest('triggered_at')
            ->first()
            ?->triggered_at
            ?->toIso8601String();

        return [
            'risk_event_count' => $riskEventCount,
            'critical_risk_events' => (int) ($rawCounts['critical'] ?? 0) + (int) ($rawCounts['high'] ?? 0),
            'warning_risk_events' => (int) ($rawCounts['warning'] ?? 0),
            'info_risk_events' => (int) ($rawCounts['info'] ?? 0),
            'last_risk_event_at' => $lastRiskEventAt,
        ];
    }

    /**
     * @param  array<string, int|string|null>  $riskStats
     * @return array<string, float|int|null>
     */
    private function operationsStats(
        int $accountId,
        \DateTimeInterface $windowStart,
        BrokerAccount $account,
        ?PaperPortfolioSnapshot $latestSnapshot,
        array $riskStats,
    ): array {
        $orderQuery = PaperOrderEvent::query()
            ->where('broker_account_id', $accountId)
            ->where('event_time', '>=', $windowStart);

        $terminalStatuses = ['filled', 'cancelled', 'rejected'];
        $terminalEvents = (int) (clone $orderQuery)
            ->whereIn('status', $terminalStatuses)
            ->count();
        $rejectedEvents = (int) (clone $orderQuery)
            ->where('status', 'rejected')
            ->count();
        $avgSlippageBps = (clone $orderQuery)
            ->whereNotNull('slippage_bps')
            ->selectRaw('AVG(ABS(slippage_bps)) as average')
            ->value('average');

        return [
            'avg_slippage_bps' => $this->nullableFloat($avgSlippageBps),
            'terminal_order_events' => $terminalEvents,
            'rejected_order_events' => $rejectedEvents,
            'rejected_order_rate_pct' => $terminalEvents > 0
                ? ($rejectedEvents / $terminalEvents) * 100
                : null,
            'risk_events_total' => (int) $riskStats['risk_event_count'],
            'critical_risk_events' => (int) $riskStats['critical_risk_events'],
            'warning_risk_events' => (int) $riskStats['warning_risk_events'],
            'info_risk_events' => (int) $riskStats['info_risk_events'],
            'paper_snapshot_staleness_minutes' => $latestSnapshot?->snapshot_time !== null
                ? (float) $latestSnapshot->snapshot_time->diffInMinutes(now())
                : null,
            'account_snapshot_staleness_minutes' => $account->snapshot_at !== null
                ? (float) $account->snapshot_at->diffInMinutes(now())
                : null,
        ];
    }

    private function baseTradeQuery(int $accountId, \DateTimeInterface $windowStart): Builder
    {
        return TradeAttribution::query()
            ->where('attributed_at', '>=', $windowStart)
            ->whereNotNull('realized_pnl')
            ->whereHas('tradeDecision', function (Builder $query) use ($accountId): void {
                $query->where('broker_account_id', $accountId);
            });
    }

    private function baseRiskEventQuery(int $accountId, \DateTimeInterface $windowStart): Builder
    {
        return RiskEvent::query()
            ->where('triggered_at', '>=', $windowStart)
            ->where(function (Builder $query) use ($accountId): void {
                $query->whereHas('tradeDecision', function (Builder $decisionQuery) use ($accountId): void {
                    $decisionQuery->where('broker_account_id', $accountId);
                })->orWhereHas('brokerOrder', function (Builder $orderQuery) use ($accountId): void {
                    $orderQuery->where('broker_account_id', $accountId);
                });
            });
    }

    /**
     * @param  Collection<int, mixed>  $metrics
     * @return array<string, float>
     */
    private function metricMap(Collection $metrics): array
    {
        $map = [];
        foreach ($metrics as $metric) {
            if (! isset($metric->metric_name)) {
                continue;
            }

            $key = Str::of((string) $metric->metric_name)
                ->lower()
                ->replace([' ', '-', '.', '/'], '_')
                ->value();

            $map[$key] = (float) $metric->metric_value;
        }

        return $map;
    }

    /**
     * @param  array<string, float>  $metricMap
     * @param  array<int, string>  $aliases
     */
    private function metricByAlias(array $metricMap, array $aliases): ?float
    {
        foreach ($aliases as $alias) {
            $key = Str::of((string) $alias)
                ->lower()
                ->replace([' ', '-', '.', '/'], '_')
                ->value();

            if (array_key_exists($key, $metricMap)) {
                return $metricMap[$key];
            }
        }

        return null;
    }

    private function normalizePercent(float $value): float
    {
        return abs($value) <= 1.0 ? $value * 100 : $value;
    }

    private function roundOrNull(?float $value, int $precision = 6): ?float
    {
        return $value === null ? null : round($value, $precision);
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
