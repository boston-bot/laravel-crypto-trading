<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\ExternalOrders;
use App\Services\Monitoring\Monitor;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\RealDataPaperFills;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BrokerInputFixture;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\ExternalOrderGateway;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class ExternalOrderEntrySafetyTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $policy;

    private $execution;

    private ExternalOrderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio, $this->policy, $this->execution] = E::seed();
        config(['paper.real_data_fills_enabled' => true, 'mcp.external_order_detection_enabled' => true]);
        $this->gateway = new ExternalOrderGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
        $this->sync();
    }

    private function sync(): void
    {
        $this->assertSame('complete', app(Monitor::class)->sync($this->connection->id, 'safety:'.Str::uuid())->status);
    }

    private function input()
    {
        return BrokerInputFixture::record($this->connection, ['execution_reads.price_book.fields.book_ask_quantity' => '100']);
    }

    private function submit(array $changes = [])
    {
        $quote = BrokerSimulationQuote::fromStored($this->connection, $this->input()->id, CarbonImmutable::now(), 60);

        return app(OfflineSimulator::class)->submit($this->connection, $this->connection->user_id, $this->policy->id,
            $this->portfolio->fresh()->ledger_version, F::intent($changes), F::assumptions(), ['DEMO' => $quote], $this->execution->id);
    }

    public function test_new_buy_is_rejected_and_existing_buy_reservations_are_released_before_any_further_fill(): void
    {
        $buy = $this->submit();
        $this->assertSame('open', $buy->status);
        $this->assertNotSame('0.000000000000000000', $this->portfolio->fresh()->reserved_cash);
        $this->gateway->orders = [['id' => 'external-buy']];
        $this->sync();
        $next = $this->submit();
        $this->assertSame('rejected', $next->status);
        $this->assertSame(ExternalOrders::INCIDENT, SimulationEvent::where('sim_order_id', $next->id)->sole()->reason);
        $this->travel(2)->minutes(); // No fresh market data needed to release the invalidated buy.
        $cancelled = app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $buy->id);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('0.000000000000000000', $this->portfolio->fresh()->reserved_cash);
        $this->assertDatabaseCount('mcp_paper_sim_positions', 0);
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
    }

    public function test_external_order_pause_does_not_block_a_held_position_sell(): void
    {
        $buy = $this->submit();
        $this->travel(1)->seconds();
        $this->input();
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $buy->id)->status);
        $this->gateway->orders = [['id' => 'external-during-hold']];
        $this->sync();
        $sell = $this->submit(['side' => 'SELL', 'price_bound' => '98']);
        $this->assertSame('open', $sell->status);
        $this->travel(1)->seconds();
        $this->input();
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $sell->id)->status);
        $this->assertSame('0.000000000000000000', SimulationPosition::sole()->quantity);
        $this->assertSame(ExternalOrders::INCIDENT, app(ExternalOrders::class)->entryReason($this->connection));
    }

    public function test_expired_or_incomplete_order_history_stops_a_buy_without_creating_an_external_order(): void
    {
        $this->travel(5)->minutes();
        $this->assertSame('rejected', $this->submit()->status);
        $this->assertSame('broker_order_history_unverified', SimulationEvent::latest('id')->first()->reason);
        $this->assertDatabaseCount('mcp_external_orders', 0);
    }
}
