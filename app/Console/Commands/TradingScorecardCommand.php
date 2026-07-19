<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Models\BacktestRun;
use App\Models\BrokerAccount;
use App\Models\PaperOrderEvent;
use App\Models\PaperPortfolioSnapshot;
use App\Models\RiskEvent;
use App\Models\TradeAttribution;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TradingScorecardCommand extends Command
{
    protected $signature = 'trading:scorecard
        {--broker= : Broker (coinbase|robinhood)}
        {--account= : Broker account ID override}
        {--days=30 : Paper/ops lookback window in days}
        {--json : Output machine-readable JSON}';

    protected $description = 'Evaluate strategy readiness from backtest, paper, and operations evidence.';

    public function handle(): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker')) ?? BrokerType::default();
        $account = $this->resolveAccount($broker);
        if ($account === null) {
            $this->error('No broker account found for '.$broker->value.'.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $windowStart = now()->subDays($days);

        $sections = [
            'backtest' => $this->evaluateBacktestSection(),
            'paper' => $this->evaluatePaperSection($account, $windowStart),
            'operations' => $this->evaluateOperationsSection($account, $windowStart),
        ];

        $minimumScore = (float) config('scorecard.minimum_weighted_score_pct', 75.0);
        $weightedScorePct = $this->weightedScorePct($sections);
        $hardGateFailures = $this->hardGateFailures($sections);
        $verdict = $weightedScorePct >= $minimumScore && $hardGateFailures === [] ? 'PASS' : 'FAIL';

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'strategy_name' => (string) config('trading.strategy_name', 'strategy-v1'),
            'broker' => $broker->value,
            'broker_account_id' => $account->id,
            'window_days' => $days,
            'verdict' => $verdict,
            'minimum_weighted_score_pct' => round($minimumScore, 4),
            'weighted_score_pct' => round($weightedScorePct, 4),
            'sections' => $sections,
            'hard_gate_failures' => $hardGateFailures,
        ];

        if ((bool) $this->option('json')) {
            foreach (explode(PHP_EOL, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }
        } else {
            $this->renderConsoleSummary($payload);
        }

        return $verdict === 'PASS' ? self::SUCCESS : self::FAILURE;
    }

    private function resolveAccount(BrokerType $broker): ?BrokerAccount
    {
        $accountId = $this->option('account');
        $query = BrokerAccount::query()
            ->where('broker', $broker->value);

        if ($accountId !== null) {
            return $query->whereKey((int) $accountId)->first();
        }

        return $query
            ->orderByDesc('snapshot_at')
            ->orderByDesc('id')
            ->first();
    }

    private function evaluateBacktestSection(): array
    {
        $config = (array) config('scorecard.backtest', []);
        $lookbackDays = max(1, (int) ($config['lookback_days'] ?? 180));
        $windowStart = now()->subDays($lookbackDays);

        $query = BacktestRun::query()
            ->where('status', 'completed')
            ->where('run_completed_at', '>=', $windowStart);

        $runCount = (clone $query)->count();
        $latestRun = (clone $query)
            ->with('metrics')
            ->latest('run_completed_at')
            ->first();

        $metricAliases = (array) ($config['metric_aliases'] ?? []);
        $metricMap = $latestRun === null
            ? []
            : $this->metricMap($latestRun->metrics);

        $sharpe = $this->metricValueByAlias($metricMap, (array) ($metricAliases['sharpe'] ?? []));
        $drawdown = $this->metricValueByAlias($metricMap, (array) ($metricAliases['max_drawdown_pct'] ?? []));
        $drawdown = $drawdown !== null ? abs($this->normalizePercent($drawdown)) : null;
        $winRate = $this->metricValueByAlias($metricMap, (array) ($metricAliases['win_rate_pct'] ?? []));
        $winRate = $winRate !== null ? $this->normalizePercent($winRate) : null;
        $profitFactor = $this->metricValueByAlias($metricMap, (array) ($metricAliases['profit_factor'] ?? []));

        $checks = [
            $this->check('completed_runs', (float) $runCount, '>=', (float) ($config['min_runs'] ?? 1), true),
            $this->check('sharpe', $sharpe, '>=', (float) ($config['min_sharpe'] ?? 1.0), true),
            $this->check('max_drawdown_pct', $drawdown, '<=', (float) ($config['max_drawdown_pct'] ?? 15.0), true),
            $this->check('win_rate_pct', $winRate, '>=', (float) ($config['min_win_rate_pct'] ?? 45.0)),
            $this->check('profit_factor', $profitFactor, '>=', (float) ($config['min_profit_factor'] ?? 1.15)),
        ];

        return $this->buildSection(
            'backtest',
            (float) config('scorecard.weights.backtest', 0.40),
            $checks,
            [
                'lookback_days' => $lookbackDays,
                'run_count' => $runCount,
                'latest_run_id' => $latestRun?->id,
                'latest_completed_at' => $latestRun?->run_completed_at?->toIso8601String(),
                'metrics' => array_filter([
                    'sharpe' => $sharpe,
                    'max_drawdown_pct' => $drawdown,
                    'win_rate_pct' => $winRate,
                    'profit_factor' => $profitFactor,
                ], fn (mixed $value): bool => $value !== null),
            ]
        );
    }

    private function evaluatePaperSection(BrokerAccount $account, \DateTimeInterface $windowStart): array
    {
        $config = (array) config('scorecard.paper', []);

        $snapshots = PaperPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->where('snapshot_time', '>=', $windowStart)
            ->orderBy('snapshot_time')
            ->get();

        $dailySnapshots = $snapshots
            ->groupBy(fn (PaperPortfolioSnapshot $snapshot): string => $snapshot->snapshot_time->toDateString())
            ->map(fn (Collection $group): ?PaperPortfolioSnapshot => $group->sortBy('snapshot_time')->last())
            ->filter()
            ->values();

        $equitySeries = $dailySnapshots
            ->map(fn (PaperPortfolioSnapshot $snapshot): float => (float) $snapshot->equity)
            ->values();

        $returns = $this->returnsFromSeries($equitySeries);
        $paperSharpe = $this->annualizedSharpe($returns, 365.0);
        $paperDrawdownPct = $this->maxDrawdownPct($equitySeries);
        $paperTotalReturnPct = $this->totalReturnPct($equitySeries);

        $attributions = TradeAttribution::query()
            ->whereHas('tradeDecision', function (Builder $query) use ($account): void {
                $query->where('broker_account_id', $account->id);
            })
            ->where('attributed_at', '>=', $windowStart)
            ->whereNotNull('realized_pnl')
            ->get();

        $tradeCount = $attributions->count();
        $grossProfit = $attributions->sum(fn (TradeAttribution $row): float => max(0.0, (float) $row->realized_pnl));
        $grossLossAbs = $attributions->sum(fn (TradeAttribution $row): float => abs(min(0.0, (float) $row->realized_pnl)));
        $wins = $attributions->filter(fn (TradeAttribution $row): bool => (float) $row->realized_pnl > 0)->count();
        $hitRatePct = $tradeCount > 0 ? ($wins / $tradeCount) * 100 : null;
        $expectancy = $tradeCount > 0
            ? (float) ($attributions->sum(fn (TradeAttribution $row): float => (float) $row->realized_pnl) / $tradeCount)
            : null;
        $profitFactor = $this->profitFactor($grossProfit, $grossLossAbs);

        $slippageRows = PaperOrderEvent::query()
            ->where('broker_account_id', $account->id)
            ->where('event_time', '>=', $windowStart)
            ->whereNotNull('slippage_bps')
            ->get();
        $avgSlippageBps = $slippageRows->count() > 0
            ? (float) $slippageRows->avg(fn (PaperOrderEvent $event): float => abs((float) $event->slippage_bps))
            : null;

        $checks = [
            $this->check('paper_days', (float) $dailySnapshots->count(), '>=', (float) ($config['min_days'] ?? 21), true),
            $this->check('paper_snapshots', (float) $snapshots->count(), '>=', (float) ($config['min_snapshots'] ?? 30), true),
            $this->check('realized_trade_count', (float) $tradeCount, '>=', (float) ($config['min_trades'] ?? 15), true),
            $this->check('paper_sharpe', $paperSharpe, '>=', (float) ($config['min_sharpe'] ?? 0.75)),
            $this->check('paper_max_drawdown_pct', $paperDrawdownPct, '<=', (float) ($config['max_drawdown_pct'] ?? 10.0), true),
            $this->check('paper_hit_rate_pct', $hitRatePct, '>=', (float) ($config['min_hit_rate_pct'] ?? 45.0)),
            $this->check('paper_profit_factor', $profitFactor, '>=', (float) ($config['min_profit_factor'] ?? 1.05)),
            $this->check('avg_slippage_bps', $avgSlippageBps, '<=', (float) ($config['max_avg_slippage_bps'] ?? 75.0)),
        ];

        return $this->buildSection(
            'paper',
            (float) config('scorecard.weights.paper', 0.45),
            $checks,
            [
                'window_start' => $windowStart->format(\DateTimeInterface::ATOM),
                'window_end' => now()->toIso8601String(),
                'snapshot_count' => $snapshots->count(),
                'paper_days' => $dailySnapshots->count(),
                'trade_count' => $tradeCount,
                'wins' => $wins,
                'total_return_pct' => $paperTotalReturnPct,
                'expectancy' => $expectancy,
                'paper_sharpe' => $paperSharpe,
                'paper_max_drawdown_pct' => $paperDrawdownPct,
                'paper_hit_rate_pct' => $hitRatePct,
                'paper_profit_factor' => $profitFactor,
                'avg_slippage_bps' => $avgSlippageBps,
            ]
        );
    }

    private function evaluateOperationsSection(BrokerAccount $account, \DateTimeInterface $windowStart): array
    {
        $config = (array) config('scorecard.operations', []);

        $criticalRiskEvents = RiskEvent::query()
            ->where('triggered_at', '>=', $windowStart)
            ->whereIn('severity', ['critical', 'high'])
            ->where(function (Builder $query) use ($account): void {
                $query->whereHas('tradeDecision', function (Builder $tradeDecisionQuery) use ($account): void {
                    $tradeDecisionQuery->where('broker_account_id', $account->id);
                })->orWhereHas('brokerOrder', function (Builder $brokerOrderQuery) use ($account): void {
                    $brokerOrderQuery->where('broker_account_id', $account->id);
                });
            })
            ->count();

        $terminalStatuses = ['filled', 'cancelled', 'rejected'];
        $terminalEventCount = PaperOrderEvent::query()
            ->where('broker_account_id', $account->id)
            ->where('event_time', '>=', $windowStart)
            ->whereIn('status', $terminalStatuses)
            ->count();
        $rejectedEventCount = PaperOrderEvent::query()
            ->where('broker_account_id', $account->id)
            ->where('event_time', '>=', $windowStart)
            ->where('status', 'rejected')
            ->count();
        $rejectedRatePct = $terminalEventCount > 0
            ? ($rejectedEventCount / $terminalEventCount) * 100
            : 0.0;

        $latestPaperSnapshot = PaperPortfolioSnapshot::query()
            ->where('broker_account_id', $account->id)
            ->latest('snapshot_time')
            ->first();
        $paperSnapshotStalenessMinutes = $latestPaperSnapshot?->snapshot_time !== null
            ? (float) $latestPaperSnapshot->snapshot_time->diffInMinutes(now())
            : null;
        $accountStalenessMinutes = $account->snapshot_at !== null
            ? (float) $account->snapshot_at->diffInMinutes(now())
            : null;

        $checks = [
            $this->check('critical_risk_events', (float) $criticalRiskEvents, '<=', (float) ($config['max_critical_risk_events'] ?? 0), true),
            $this->check('rejected_order_rate_pct', $rejectedRatePct, '<=', (float) ($config['max_rejected_rate_pct'] ?? 10.0)),
            $this->check('paper_snapshot_staleness_minutes', $paperSnapshotStalenessMinutes, '<=', (float) ($config['max_data_staleness_minutes'] ?? 60), true),
            $this->check('account_snapshot_staleness_minutes', $accountStalenessMinutes, '<=', (float) config('risk.stale_account_minutes', 30)),
        ];

        return $this->buildSection(
            'operations',
            (float) config('scorecard.weights.operations', 0.15),
            $checks,
            [
                'window_start' => $windowStart->format(\DateTimeInterface::ATOM),
                'window_end' => now()->toIso8601String(),
                'critical_risk_events' => $criticalRiskEvents,
                'terminal_event_count' => $terminalEventCount,
                'rejected_event_count' => $rejectedEventCount,
                'rejected_order_rate_pct' => $rejectedRatePct,
                'paper_snapshot_staleness_minutes' => $paperSnapshotStalenessMinutes,
                'account_snapshot_staleness_minutes' => $accountStalenessMinutes,
            ]
        );
    }

    /**
     * @param  array<string, float>  $metricMap
     * @param  array<int, string>  $aliases
     */
    private function metricValueByAlias(array $metricMap, array $aliases): ?float
    {
        foreach ($aliases as $alias) {
            $key = $this->normalizeMetricName((string) $alias);
            if (array_key_exists($key, $metricMap)) {
                return $metricMap[$key];
            }
        }

        return null;
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

            $map[$this->normalizeMetricName((string) $metric->metric_name)] = (float) $metric->metric_value;
        }

        return $map;
    }

    private function normalizeMetricName(string $name): string
    {
        return Str::of($name)
            ->lower()
            ->replace([' ', '-', '.', '/'], '_')
            ->value();
    }

    private function normalizePercent(float $value): float
    {
        return abs($value) <= 1.0 ? $value * 100 : $value;
    }

    /**
     * @param  Collection<int, float>  $series
     * @return array<int, float>
     */
    private function returnsFromSeries(Collection $series): array
    {
        $returns = [];
        $values = $series->values();
        for ($index = 1; $index < $values->count(); $index++) {
            $previous = (float) $values[$index - 1];
            $current = (float) $values[$index];

            if ($previous <= 0) {
                continue;
            }

            $returns[] = ($current - $previous) / $previous;
        }

        return $returns;
    }

    /**
     * @param  array<int, float>  $returns
     */
    private function annualizedSharpe(array $returns, float $periodsPerYear): ?float
    {
        $count = count($returns);
        if ($count < 2) {
            return null;
        }

        $mean = array_sum($returns) / $count;
        $sumOfSquares = 0.0;
        foreach ($returns as $value) {
            $sumOfSquares += ($value - $mean) ** 2;
        }

        $stdDev = sqrt($sumOfSquares / ($count - 1));
        if ($stdDev <= 0) {
            return null;
        }

        return ($mean / $stdDev) * sqrt($periodsPerYear);
    }

    /**
     * @param  Collection<int, float>  $series
     */
    private function maxDrawdownPct(Collection $series): ?float
    {
        if ($series->count() < 2) {
            return null;
        }

        $peak = (float) $series->first();
        $maxDrawdownPct = 0.0;
        foreach ($series as $equity) {
            $equity = (float) $equity;
            if ($equity > $peak) {
                $peak = $equity;
            }

            if ($peak <= 0) {
                continue;
            }

            $drawdownPct = (($peak - $equity) / $peak) * 100;
            if ($drawdownPct > $maxDrawdownPct) {
                $maxDrawdownPct = $drawdownPct;
            }
        }

        return $maxDrawdownPct;
    }

    /**
     * @param  Collection<int, float>  $series
     */
    private function totalReturnPct(Collection $series): ?float
    {
        if ($series->count() < 2) {
            return null;
        }

        $start = (float) $series->first();
        $end = (float) $series->last();
        if ($start <= 0) {
            return null;
        }

        return (($end - $start) / $start) * 100;
    }

    private function profitFactor(float $grossProfit, float $grossLossAbs): ?float
    {
        if ($grossProfit <= 0 && $grossLossAbs <= 0) {
            return null;
        }

        if ($grossLossAbs <= 0) {
            return 99.0;
        }

        return $grossProfit / $grossLossAbs;
    }

    private function check(string $name, ?float $value, string $operator, float $threshold, bool $hardGate = false): array
    {
        $passed = $value !== null && $this->compare($value, $operator, $threshold);

        return [
            'name' => $name,
            'value' => $value !== null ? round($value, 8) : null,
            'operator' => $operator,
            'threshold' => round($threshold, 8),
            'rule' => $operator.' '.$this->formatValue($threshold),
            'hard_gate' => $hardGate,
            'passed' => $passed,
        ];
    }

    private function compare(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '==' => $value === $threshold,
            default => false,
        };
    }

    /**
     * @param  array<int, array{name: string, value: float|null, operator: string, threshold: float, rule: string, hard_gate: bool, passed: bool}>  $checks
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function buildSection(string $name, float $weight, array $checks, array $stats = []): array
    {
        $totalChecks = count($checks);
        $passedChecks = collect($checks)->where('passed', true)->count();
        $scorePct = $totalChecks > 0
            ? ($passedChecks / $totalChecks) * 100
            : 0.0;
        $hardGatePassed = ! collect($checks)->contains(
            fn (array $check): bool => $check['hard_gate'] === true && $check['passed'] === false
        );

        return [
            'name' => $name,
            'weight' => round($weight, 6),
            'score_pct' => round($scorePct, 4),
            'checks' => $checks,
            'hard_gate_passed' => $hardGatePassed,
            'stats' => $this->normalizeStats($stats),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     */
    private function weightedScorePct(array $sections): float
    {
        $weightedScore = 0.0;
        $weightTotal = 0.0;

        foreach ($sections as $section) {
            $weight = (float) ($section['weight'] ?? 0.0);
            $sectionScore = ((float) ($section['score_pct'] ?? 0.0)) / 100.0;
            $weightedScore += $sectionScore * $weight;
            $weightTotal += $weight;
        }

        if ($weightTotal <= 0) {
            return 0.0;
        }

        return ($weightedScore / $weightTotal) * 100.0;
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<int, string>
     */
    private function hardGateFailures(array $sections): array
    {
        $failures = [];

        foreach ($sections as $sectionName => $section) {
            foreach ((array) ($section['checks'] ?? []) as $check) {
                if (($check['hard_gate'] ?? false) !== true) {
                    continue;
                }

                if (($check['passed'] ?? false) === true) {
                    continue;
                }

                $failures[] = $sectionName.'.'.$check['name'];
            }
        }

        return $failures;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function normalizeStats(array $stats): array
    {
        foreach ($stats as $key => $value) {
            if (is_float($value)) {
                $stats[$key] = round($value, 8);
            }
        }

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderConsoleSummary(array $payload): void
    {
        $summary = sprintf(
            'Scorecard verdict: %s (weighted %.2f%%, minimum %.2f%%)',
            $payload['verdict'],
            (float) $payload['weighted_score_pct'],
            (float) $payload['minimum_weighted_score_pct'],
        );

        if ($payload['verdict'] === 'PASS') {
            $this->info($summary);
        } else {
            $this->error($summary);
        }

        $this->line(sprintf(
            'Broker: %s | Account: %d | Window: %d days | Strategy: %s',
            $payload['broker'],
            $payload['broker_account_id'],
            $payload['window_days'],
            $payload['strategy_name'],
        ));

        $sectionRows = [];
        foreach ((array) $payload['sections'] as $sectionName => $section) {
            $sectionRows[] = [
                strtoupper((string) $sectionName),
                $this->formatValue((float) ($section['score_pct'] ?? 0.0)),
                $this->formatValue(((float) ($section['weight'] ?? 0.0)) * 100).'%',
                ($section['hard_gate_passed'] ?? false) ? 'PASS' : 'FAIL',
            ];
        }
        $this->table(['Section', 'Score %', 'Weight', 'Hard Gates'], $sectionRows);

        foreach ((array) $payload['sections'] as $sectionName => $section) {
            $this->line(strtoupper((string) $sectionName).' checks:');
            $checkRows = [];
            foreach ((array) ($section['checks'] ?? []) as $check) {
                $checkRows[] = [
                    $check['name'],
                    $check['value'] === null ? 'n/a' : $this->formatValue((float) $check['value']),
                    $check['rule'],
                    $check['hard_gate'] ? 'yes' : 'no',
                    $check['passed'] ? 'PASS' : 'FAIL',
                ];
            }
            $this->table(['Check', 'Value', 'Rule', 'Hard Gate', 'Status'], $checkRows);
        }

        if ((array) $payload['hard_gate_failures'] !== []) {
            $this->warn('Failed hard gates: '.implode(', ', (array) $payload['hard_gate_failures']));
        }
    }

    private function formatValue(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
