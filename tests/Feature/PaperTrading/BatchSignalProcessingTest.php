<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\PaperTrading\SignalPaperCycle;
use App\Services\PaperTrading\StrategySignals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\PaperMarketGateway;
use Tests\Support\SignalExecutionFixture as F;
use Tests\TestCase;

class BatchSignalProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    private function seedUniverse(): array
    {
        $state = F::seed();
        [$c, , , , $watchlists, $reader] = $state;
        $reader->members = array_map(fn ($s) => ['id' => 'fixture-'.$s, 'object_type' => 'instrument', 'symbol' => $s], ['DEMO', 'ALFA', 'BRAVO']);
        $watchlists->collect($c, $c->user_id, (string) Str::uuid());

        return $state;
    }

    public function test_later_batches_add_only_their_symbols_and_retry_preserves_first_evaluation(): void
    {
        [$c] = $this->seedUniverse();
        $first = app(StrategySignals::class)->record($c, $c->user_id, ['DEMO'])->sole();
        $this->travel(25)->seconds();
        $second = app(StrategySignals::class)->record($c, $c->user_id, ['ALFA'])->sole();
        $this->assertSame('ALFA', $second->symbol);
        $this->assertTrue($second->cutoff->gt($first->cutoff));
        $this->assertSame($first->cycle_id, $second->cycle_id);
        $this->assertSame($first->id, app(StrategySignals::class)->record($c, $c->user_id, ['DEMO'])->sole()->id);
        $this->assertSame($first->input_fingerprint, $first->fresh()->input_fingerprint);
        $this->assertSame(1, StrategyCycle::count());
        $this->assertSame(2, StrategySignal::count());
        $this->expectException(HttpException::class);
        app(StrategySignals::class)->record($c, $c->user_id, ['OUTSIDE']);
    }

    public function test_forward_cycle_evaluates_each_batch_before_reading_the_next_and_unverified_execution_stays_blocked(): void
    {
        [$c] = $this->seedUniverse();
        config(['paper.batch_collection_enabled' => true, 'paper.market_collection_enabled' => true, 'paper.market_inputs_enabled' => true,
            'paper.market_batch_symbols' => 1, 'alpaca_data.enabled' => false,
            'robinhood_mcp.execution_inputs_enabled' => false, 'robinhood_mcp.reference_data_enabled' => false]);
        $gateway = new PaperMarketGateway;
        $quotes = 0;
        $gateway->respond = function ($connection, $op, $filters) use (&$quotes) {
            if ($op === Operation::Quotes) {
                $this->assertSame($quotes++, StrategySignal::count(), 'Previous batch must be evaluated before the next quote request.');
                $fields = ['symbol' => ($filters['symbols'][0] ?? $filters['symbol']), 'bid' => '101', 'ask' => '101.1', 'bid_as_of' => now()->toISOString(), 'ask_as_of' => now()->toISOString()];
            } else {
                $this->travel(8)->seconds();
                $fields = ['symbol' => $filters['symbol'], 'id' => 'synthetic', 'name' => 'Synthetic'];
            }

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($op, $fields, null)], true);
        };
        $this->app->instance(BrokerGateway::class, $gateway);
        $this->assertSame(4, app(SignalPaperCycle::class)->tick($c->id)); // Three watchlist symbols plus the broker-held SYNTH.
        $this->assertSame(1, $gateway->scopes);
        $this->assertSame(1, StrategyCycle::count());
        $this->assertSame(4, StrategySignal::count());
        $this->assertSame('broker_position_not_managed_by_strategy', StrategySignal::where('symbol', 'SYNTH')->sole()->reason);
        foreach (StrategySignal::all() as $signal) {
            $this->assertNotNull($signal->payload['evidence']['quote']);
            $source = CarbonImmutable::parse($signal->payload['evidence']['market_input_times']['source_at']);
            $this->assertSame(8, (int) $source->diffInSeconds($signal->cutoff));
        }
        $this->assertSame(0, SimulationOrder::count()); // Incomplete execution evidence stays blocked.
    }
}
