<?php

namespace Tests\Integration;

use App\Models\Execution\BrokerOrderBinding;
use App\Services\Execution\ExecutionDispatcher;
use App\Services\Execution\ExecutionGateway;
use App\Services\Execution\LivePositionFacts;
use App\Services\Execution\SubmissionChecks;
use App\Services\PaperTrading\StrategySignals;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExecutionJournalFixture;
use Tests\Support\FakeExecutionGateway;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\Support\SignalExecutionFixture;
use Tests\TestCase;

class LiveExitStateTest extends TestCase
{
    private ?int $fixture = null;

    private LiveBrokerGateway $broker;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        app()->instance(ExecutionGateway::class, new FakeExecutionGateway);
    }

    protected function tearDown(): void
    {
        ExecutionJournalFixture::cleanup($this->fixture);
        parent::tearDown();
    }

    private function entry(): array
    {
        $this->broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($this->broker, ['max_orders_per_session' => 4]);
        $this->fixture = $c->id;
        config(['mcp.brokerage_writes_enabled' => true, 'mcp.live_exit_signals_enabled' => true]);
        $intent = F::stage($c, $signal);
        $this->assertSame('accepted', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $id = BrokerOrderBinding::where('intent_id', $intent->id)->sole()->broker_order_id;
        $this->travel(1)->seconds();
        $this->broker->orders = [LiveBrokerGateway::order($id, 'filled', '1', '1', [['fill-a', '0.4', '100'], ['fill-b', '0.6', '103']])];
        $this->broker->positions['DEMO'] = ['1', '101.80'];
        F::sync($c, $this->broker);

        return [$c, $intent];
    }

    public function test_partial_live_exit_preserves_episode_and_full_exit_enforces_live_cooldown(): void
    {
        [$c, $entry] = $this->entry();
        $facts = app(LivePositionFacts::class)->inspect($c, 'DEMO', CarbonImmutable::now());
        $this->assertSame('101.800000000000000000', $facts['context']['entry_price']);
        SignalExecutionFixture::quote($c, '70', '70.01');
        $exit = app(StrategySignals::class)->recordLiveExits($c, $c->user_id)->sole();
        $this->assertSame('initial_stop_hit', $exit->reason);
        $intent = F::stage($c, $exit);
        $this->assertSame('accepted', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $intent->id));
        $sellId = BrokerOrderBinding::where('intent_id', $intent->id)->sole()->broker_order_id;
        $this->broker->orders[] = LiveBrokerGateway::order($sellId, 'cancelled', '1', '0.25', [['sell-part', '0.25', '70']], side: 'sell');
        $this->broker->positions['DEMO'] = ['0.75', '52.50'];
        $this->travel(1)->seconds();
        F::sync($c, $this->broker);
        $facts = app(LivePositionFacts::class)->inspect($c, 'DEMO', CarbonImmutable::now());
        $this->assertSame('0.750000000000000000', $facts['quantity']);
        $this->assertSame($entry->id, $facts['context']['entry_order_id']);
        $this->assertSame('101.800000000000000000', $facts['context']['entry_price']);
        $this->travel(5)->minutes();
        F::sync($c, $this->broker);
        SignalExecutionFixture::quote($c, '102', '102.01', '100', ['execution_reads.tradability.fields.fractional_tradability' => 'tradable']); // Stop recovered, but an uncompleted exit stays latched.
        $next = (new StrategySignals)->recordLiveExits($c, $c->user_id)->sole();
        $this->assertSame('exit_remains_pending', $next->reason);
        $this->assertSame('0.750000000000000000', $next->payload['evidence']['report']['position']['quantity']);
        $this->assertSame(0, Decimal::from($next->payload['evidence']['report']['position']['context']['trailing_stop'])->compareTo(
            Decimal::from($exit->payload['evidence']['report']['position']['context']['trailing_stop'])));
        $sell = F::stage($c, $next);
        $this->assertSame('0.750000', $sell->payload['quantity']);
        $this->assertSame('accepted', app(ExecutionDispatcher::class)->dispatch($c, $c->user_id, $sell->id));
        $secondId = BrokerOrderBinding::where('intent_id', $sell->id)->sole()->broker_order_id;
        $this->broker->orders[] = LiveBrokerGateway::order($secondId, 'filled', '0.75', '0.75', [['sell-rest', '0.75', '102']], side: 'sell');
        unset($this->broker->positions['DEMO']);
        $this->travel(1)->seconds();
        F::sync($c, $this->broker);
        SignalExecutionFixture::quote($c);
        $this->assertFalse(app(LivePositionFacts::class)->inspect($c, 'DEMO', CarbonImmutable::now())['held']);
        $this->assertSame('live_reentry_cooldown', app(SubmissionChecks::class)->plan($c, F::signal($c, 'BUY'))['reason']);
        $this->assertSame(0, DB::table('mcp_paper_sim_orders')->count());
    }

    public function test_stale_quote_and_broker_quantity_disagreement_never_produce_exit_signals(): void
    {
        [$c] = $this->entry();
        $this->travel(31)->seconds();
        $assessment = app(StrategySignals::class)->liveExitAssessment($c, 'DEMO', CarbonImmutable::now(), 30);
        $this->assertSame('fresh_scoped_quote_unavailable', $assessment['result']['reason']);
        SignalExecutionFixture::quote($c, '70', '70.01');
        $this->broker->positions['DEMO'] = ['0.5', '35'];
        F::sync($c, $this->broker);
        $this->assertSame('live_broker_position_disagrees', app(LivePositionFacts::class)->inspect($c, 'DEMO', CarbonImmutable::now())['reason']);
        $row = app(StrategySignals::class)->recordLiveExits($c, $c->user_id)->sole();
        $this->assertSame('unavailable', $row->status);
        $this->assertNull($row->side);
        $this->assertSame([], app(LivePositionFacts::class)->symbols($c, CarbonImmutable::parse('2026-10-19T13:59:59Z')), 'Future local fills cannot enter an earlier cutoff.');
    }

    public function test_live_and_paper_cycles_are_independent_and_source_mismatch_is_a_database_error(): void
    {
        [$c] = $this->entry();
        SignalExecutionFixture::quote($c, '70', '70.01');
        $exit = app(StrategySignals::class)->recordLiveExits($c, $c->user_id)->sole();
        $this->assertSame(2, DB::table('mcp_strategy_cycles')->where('mcp_connection_id', $c->id)->count());
        $before = DB::table('mcp_strategy_signals')->where('position_source', 'paper')->count();
        app(StrategySignals::class)->record($c, $c->user_id);
        $this->assertSame($before, DB::table('mcp_strategy_signals')->where('position_source', 'paper')->count());
        $this->artisan('mcp:live-exits', ['--account' => $c->id])->expectsOutputToContain('live_exit_signal_evidence')->assertSuccessful();
        try {
            DB::transaction(fn () => DB::table('mcp_strategy_signals')->where('id', $exit->id)->update(['position_source' => 'paper']));
            $this->fail('Signal/cycle source mismatch must be refused.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
