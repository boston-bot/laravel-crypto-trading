<?php

namespace Tests\Feature\Trading;

use App\Models\Asset;
use App\Models\BacktestRun;
use App\Models\BacktestRunMetric;
use App\Models\BrokerAccount;
use App\Models\PaperOrderEvent;
use App\Models\PaperPortfolioSnapshot;
use App\Models\TradeAttribution;
use App\Models\TradeDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class TradingScorecardCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_scorecard_can_pass_with_sufficient_backtest_and_paper_evidence(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 20, 12, 0, 0, 'UTC'));

        config()->set('scorecard.minimum_weighted_score_pct', 60.0);
        config()->set('scorecard.backtest.min_runs', 1);
        config()->set('scorecard.backtest.min_sharpe', 1.0);
        config()->set('scorecard.backtest.max_drawdown_pct', 15.0);
        config()->set('scorecard.backtest.min_win_rate_pct', 45.0);
        config()->set('scorecard.backtest.min_profit_factor', 1.1);
        config()->set('scorecard.paper.min_days', 3);
        config()->set('scorecard.paper.min_snapshots', 4);
        config()->set('scorecard.paper.min_trades', 3);
        config()->set('scorecard.paper.min_sharpe', 0.1);
        config()->set('scorecard.paper.max_drawdown_pct', 12.0);
        config()->set('scorecard.paper.min_hit_rate_pct', 40.0);
        config()->set('scorecard.paper.min_profit_factor', 1.0);
        config()->set('scorecard.paper.max_avg_slippage_bps', 80.0);
        config()->set('scorecard.operations.max_data_staleness_minutes', 180);
        config()->set('scorecard.operations.max_rejected_rate_pct', 50.0);

        $account = BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => 'cb-account-1',
            'currency' => 'USD',
            'buying_power' => 10000,
            'cash_balance' => 10000,
            'equity' => 10000,
            'status' => 'active',
            'snapshot_at' => now()->subMinutes(5),
        ]);

        $asset = Asset::query()->create([
            'broker' => 'coinbase',
            'symbol' => 'BTC',
            'asset_type' => 'crypto',
            'is_tradable' => true,
            'is_enabled' => true,
        ]);

        $backtestRun = BacktestRun::query()->create([
            'strategy_name' => 'BTC_ETH_Momentum_Filtered_v1',
            'run_started_at' => now()->subDays(2),
            'run_completed_at' => now()->subDays(1),
            'status' => 'completed',
            'trigger' => 'manual',
        ]);

        BacktestRunMetric::query()->create([
            'backtest_run_id' => $backtestRun->id,
            'metric_name' => 'sharpe_ratio',
            'metric_group' => 'portfolio',
            'metric_value' => 1.8,
        ]);
        BacktestRunMetric::query()->create([
            'backtest_run_id' => $backtestRun->id,
            'metric_name' => 'max_drawdown_pct',
            'metric_group' => 'portfolio',
            'metric_value' => 9.5,
        ]);
        BacktestRunMetric::query()->create([
            'backtest_run_id' => $backtestRun->id,
            'metric_name' => 'win_rate',
            'metric_group' => 'portfolio',
            'metric_value' => 0.58,
        ]);
        BacktestRunMetric::query()->create([
            'backtest_run_id' => $backtestRun->id,
            'metric_name' => 'profit_factor',
            'metric_group' => 'portfolio',
            'metric_value' => 1.7,
        ]);

        $equities = [10000.0, 10100.0, 10040.0, 10320.0, 10410.0];
        foreach ($equities as $index => $equity) {
            PaperPortfolioSnapshot::query()->create([
                'broker_account_id' => $account->id,
                'snapshot_time' => now()->subDays(4 - $index)->startOfDay()->addHours(18),
                'equity' => $equity,
                'cash' => 8000.0,
                'invested_value' => 2000.0,
                'realized_pnl' => 0.0,
                'unrealized_pnl' => 0.0,
                'gross_exposure_pct' => 20.0,
                'heat_score' => 20.0,
                'drawdown_pct' => 0.0,
            ]);
        }

        for ($index = 0; $index < 3; $index++) {
            $decision = TradeDecision::query()->create([
                'broker_account_id' => $account->id,
                'asset_id' => $asset->id,
                'decision' => 'buy',
                'side' => 'buy',
                'score' => 0.9,
                'confidence' => 0.8,
                'requested_quantity' => 0.01,
                'requested_notional' => 150.0,
                'requires_human_approval' => false,
                'status' => 'filled',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $pnl = match ($index) {
                0 => 12.0,
                1 => -4.0,
                default => 9.0,
            };

            TradeAttribution::query()->create([
                'trade_decision_id' => $decision->id,
                'asset_id' => $asset->id,
                'expected_probability' => 0.6,
                'expected_expectancy' => 0.01,
                'realized_return_pct' => 0.7,
                'realized_pnl' => $pnl,
                'attributed_at' => now()->subDays(2 - $index),
                'attribution_json' => ['mode' => 'paper'],
            ]);
        }

        PaperOrderEvent::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'event_type' => 'fill',
            'status' => 'filled',
            'side' => 'buy',
            'event_time' => now()->subDays(2),
            'quantity' => 0.01,
            'notional' => 150,
            'slippage_bps' => 25.0,
        ]);
        PaperOrderEvent::query()->create([
            'broker_account_id' => $account->id,
            'asset_id' => $asset->id,
            'event_type' => 'fill',
            'status' => 'filled',
            'side' => 'buy',
            'event_time' => now()->subDays(1),
            'quantity' => 0.01,
            'notional' => 150,
            'slippage_bps' => 35.0,
        ]);

        $this->artisan('trading:scorecard --broker=coinbase --days=30 --json')
            ->expectsOutputToContain('"verdict": "PASS"')
            ->expectsOutputToContain('"weighted_score_pct"')
            ->assertExitCode(0);
    }

    public function test_scorecard_fails_when_no_broker_account_exists(): void
    {
        $this->artisan('trading:scorecard --broker=coinbase')
            ->expectsOutputToContain('No broker account found for coinbase.')
            ->assertExitCode(1);
    }
}
