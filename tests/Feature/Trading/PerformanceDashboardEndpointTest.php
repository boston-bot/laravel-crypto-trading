<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\BacktestRun;
use App\Models\BacktestRunMetric;
use App\Models\BrokerAccount;
use App\Models\PaperPortfolioSnapshot;
use App\Models\PaperSession;
use App\Models\RiskEvent;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformanceDashboardEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_endpoint_returns_not_found_without_broker_account(): void
    {
        $this->getJson('/api/broker/performance-dashboard?broker=coinbase')
            ->assertStatus(404)
            ->assertJsonPath('message', 'No broker account snapshots found. Run broker sync first.');
    }

    public function test_endpoint_returns_dashboard_payload_with_paper_and_backtest_metrics(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 21, 14, 0, 0, 'UTC'));

        $account = BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'cb-main',
            'currency' => 'USD',
            'buying_power' => 15000,
            'cash_balance' => 15000,
            'equity' => 15000,
            'status' => 'active',
            'snapshot_at' => now()->subMinutes(2),
        ]);

        $asset = Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $session = PaperSession::query()->create([
            'broker_account_id' => $account->id,
            'funding_mode' => 'virtual',
            'status' => 'active',
            'currency' => 'USD',
            'opening_cash' => 10_000,
            'reserved_cash' => 0,
            'fee_scenario' => 'coinbase-taker-current',
            'slippage_scenario' => 'observed-spread-volatility-v1',
            'valuation_at' => now()->subDays(30),
            'started_at' => now()->subDays(30),
        ]);

        $equities = [10000, 10150, 10075, 10320, 10460];
        foreach ($equities as $index => $equity) {
            PaperPortfolioSnapshot::query()->create([
                'broker_account_id' => $account->id,
                'paper_session_id' => $session->id,
                'snapshot_time' => now()->subDays(4 - $index)->startOfDay()->addHours(12),
                'equity' => $equity,
                'cash' => 8000,
                'invested_value' => 2400,
                'realized_pnl' => 0,
                'unrealized_pnl' => 0,
                'gross_exposure_pct' => 22,
                'heat_score' => 22,
                'drawdown_pct' => match ($index) {
                    0 => 0.0,
                    1 => 0.0,
                    2 => 0.73,
                    3 => 0.0,
                    default => 0.0,
                },
            ]);
        }

        $tradePnls = [35.0, -10.0, 20.0];
        foreach ($tradePnls as $index => $pnl) {
            $decision = TradeDecision::query()->create([
                'broker_account_id' => $account->id,
                'asset_id' => $asset->id,
                'decision' => 'buy',
                'side' => 'buy',
                'score' => 0.86,
                'confidence' => 0.79,
                'requested_quantity' => 0.01,
                'requested_notional' => 200.0,
                'requires_human_approval' => false,
                'status' => 'filled',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            TradeAttribution::query()->create([
                'trade_decision_id' => $decision->id,
                'asset_id' => $asset->id,
                'expected_probability' => 0.61,
                'expected_expectancy' => 0.014,
                'realized_return_pct' => 0.8,
                'realized_pnl' => $pnl,
                'attributed_at' => now()->subDays(2 - $index),
                'hold_hours' => 18,
            ]);
        }

        $decisionForRisk = TradeDecision::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'decision' => 'hold',
            'score' => 0.5,
            'confidence' => 0.5,
            'requires_human_approval' => false,
            'status' => 'approved',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        RiskEvent::query()->create([
            'severity' => 'warning',
            'event_type' => 'max_drawdown_warning',
            'trade_decision_id' => $decisionForRisk->id,
            'message' => 'Drawdown near threshold.',
            'triggered_at' => now()->subHours(6),
        ]);

        $run = BacktestRun::query()->create([
            'strategy_name' => (string) config('trading.strategy_name', 'BTC_ETH_Momentum_Filtered_v1'),
            'run_started_at' => now()->subDays(3),
            'run_completed_at' => now()->subDays(1),
            'status' => 'completed',
            'trigger' => 'manual',
        ]);

        BacktestRunMetric::query()->create([
            'backtest_run_id' => $run->id,
            'metric_name' => 'sharpe_ratio',
            'metric_group' => 'portfolio',
            'metric_value' => 1.62,
        ]);
        BacktestRunMetric::query()->create([
            'backtest_run_id' => $run->id,
            'metric_name' => 'max_drawdown_pct',
            'metric_group' => 'portfolio',
            'metric_value' => 11.2,
        ]);
        BacktestRunMetric::query()->create([
            'backtest_run_id' => $run->id,
            'metric_name' => 'win_rate',
            'metric_group' => 'portfolio',
            'metric_value' => 0.57,
        ]);
        BacktestRunMetric::query()->create([
            'backtest_run_id' => $run->id,
            'metric_name' => 'profit_factor',
            'metric_group' => 'portfolio',
            'metric_value' => 1.41,
        ]);

        $this->getJson('/api/broker/performance-dashboard?broker=coinbase&account_id='.$account->id.'&window_days=30')
            ->assertOk()
            ->assertJsonPath('meta.broker', 'coinbase')
            ->assertJsonPath('meta.account_id', $account->id)
            ->assertJsonPath('meta.window_days', 30)
            ->assertJsonPath('window_summary.snapshot_count', 5)
            ->assertJsonPath('window_summary.trade_count', 3)
            ->assertJsonPath('window_summary.risk_event_count', 1)
            ->assertJsonPath('performance.measurement_state', 'measured')
            ->assertJsonPath('performance.completeness_state', 'complete')
            ->assertJsonPath('portfolio_summary.strategy_return_pct', 4.6)
            ->assertJsonPath('kpis.paper_trade_count', 3)
            ->assertJsonPath('kpis.paper_wins', 2)
            ->assertJsonPath('kpis.paper_losses', 1)
            ->assertJsonPath('kpis.paper_profit_factor', 5.5)
            ->assertJsonPath('kpis.paper_total_realized_pnl', 45)
            ->assertJsonPath('kpis.paper_avg_hold_hours', 18)
            ->assertJsonPath('kpis.paper_gross_exposure_pct', 22)
            ->assertJsonPath('backtest_latest.run_id', $run->id)
            ->assertJsonPath('backtest_latest.metrics.sharpe', 1.62)
            ->assertJsonPath('backtest_latest.metrics.win_rate_pct', 57)
            ->assertJsonPath('comparison.paper.profit_factor', 5.5)
            ->assertJsonPath('operations.risk_events_total', 1)
            ->assertJsonPath('operations.critical_risk_events', 0)
            ->assertJsonCount(5, 'paper_series.equity')
            ->assertJsonCount(5, 'paper_series.gross_exposure_pct')
            ->assertJsonCount(3, 'recent_trades')
            ->assertJsonCount(1, 'recent_risk_events');
    }
}
