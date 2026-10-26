<?php

namespace Tests\Support;

use App\Models\Execution\ExecutionPolicy;
use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\LivePolicies;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\StrategySignals;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A real deterministic BUY signal, a synthetic broker-confirmed snapshot and an applied synthetic live policy.
 * Evidence for boundary tests only: no live authorization, no broker contact, writes stay disabled.
 */
final class LiveExecutionFixture
{
    public const POLICY = ['sleeve_capital' => '1000.00', 'max_order_notional' => '250.00', 'risk_per_trade_pct' => '1', 'max_position_pct' => '20',
        'max_open_positions' => 4, 'daily_loss_pause_pct' => '2', 'max_orders_per_session' => 3, 'order_type' => 'market', 'allow_fractional' => true,
        'slippage_bps' => '5', 'limit_offset_bps' => '5', 'quote_max_age_seconds' => 30];

    /** @return array{0: McpConnection, 1: StrategySignal, 2: ExecutionPolicy} */
    public static function seed(LiveBrokerGateway $broker, array $policy = []): array
    {
        [$c] = StrategySignalFixture::seed();
        self::sync($c, $broker);
        SignalExecutionFixture::quote($c);
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        if ($signal->status !== 'signal' || $signal->side !== 'BUY') {
            throw new RuntimeException('Fixture did not produce a BUY signal.');
        }

        return [$c, $signal, app(LivePolicies::class)->apply($c, $c->user_id, 0, (string) Str::uuid(), $policy + self::POLICY)];
    }

    public static function sync(McpConnection $c, LiveBrokerGateway $broker): void
    {
        app()->instance(BrokerGateway::class, $broker);
        app(Monitor::class)->sync($c->id, 'live-fixture:'.Str::uuid());
    }

    public static function stage(McpConnection $c, StrategySignal $signal, array $patch = []): OrderIntent
    {
        $plan = app(SubmissionChecks::class)->plan($c, $signal);
        if ($plan['draft'] === null) {
            throw new RuntimeException('No admissible draft: '.$plan['reason']);
        }

        return app(ExecutionJournal::class)->stage($c, $c->user_id, $signal->id, $patch + $plan['draft'] + ['expires_at' => now()->addMinutes(4)->toISOString()]);
    }

    /** Another recorded signal with the original's evidence on its own cycle, differing only by side. */
    public static function signal(McpConnection $c, string $side): StrategySignal
    {
        $base = StrategySignal::where('mcp_connection_id', $c->id)->where('symbol', 'DEMO')->oldest('id')->firstOrFail();
        $cycle = StrategyCycle::findOrFail($base->cycle_id);
        $copy = $cycle->replicate()->fill(['cycle_at' => $cycle->cycle_at->addMicroseconds(StrategyCycle::where('mcp_connection_id', $c->id)->count())]);
        $copy->save();

        return StrategySignal::create(array_replace($base->only(['mcp_connection_id', 'paper_portfolio_id', 'symbol', 'status', 'reason',
            'rule_version', 'parameters_version', 'profile_id', 'watchlist_revision_id', 'market_input_id', 'cutoff', 'recorded_at', 'payload']),
            ['cycle_id' => $copy->id, 'side' => $side, 'input_fingerprint' => hash('sha256', (string) Str::uuid())]));
    }
}
