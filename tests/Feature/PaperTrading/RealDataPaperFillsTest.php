<?php

namespace Tests\Feature\PaperTrading;

use App\Models\BrokerOrder;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationObservation;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\DecisionFailure;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BrokerInputFixture;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class RealDataPaperFillsTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $policy;

    private $execution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio, $this->policy, $this->execution] = E::seed();
        config(['paper.real_data_fills_enabled' => true, 'paper.offline_simulation_enabled' => false]);
    }

    /** Controlled future coverage contract for engine tests; the current production collector leaves reference facts UNKNOWN. */
    private function input(array $changes = []): MarketInput
    {
        return BrokerInputFixture::record($this->connection, $changes);
    }

    private function quote(MarketInput $input): BrokerSimulationQuote
    {
        return BrokerSimulationQuote::fromStored($this->connection, $input->id, CarbonImmutable::now(), 60);
    }

    private function submit(array $intent = []): SimulationOrder
    {
        return app(OfflineSimulator::class)->submit($this->connection, $this->connection->user_id, $this->policy->id, $this->portfolio->fresh()->ledger_version,
            F::intent($intent), F::assumptions(['allow_fractional' => $this->execution->payload['allow_fractional'], 'allow_fractional_limit' => $this->execution->payload['allow_fractional_limit']]), ['DEMO' => $this->quote($this->input())], $this->execution->id);
    }

    private function decimal(string $expected, string $actual): void
    {
        $this->assertSame(0, Decimal::from($expected)->compareTo(Decimal::from($actual)));
    }

    public function test_real_observation_partial_fills_repolls_restart_and_round_trip_preserve_accounting_and_provenance(): void
    {
        $buy = $this->submit();
        $this->assertSame('real_data_paper_simulation', $buy->intent['origin']);
        $this->decimal('1010.1', $this->portfolio->fresh()->reserved_cash);
        $this->travel(1)->seconds();
        $first = $this->input();
        $processor = app(RealDataPaperFills::class);
        $processor->process($this->connection, $this->connection->user_id, $buy->id);
        $this->decimal('4', $buy->fresh()->filled_quantity);
        $this->decimal('9599.56', $this->portfolio->fresh()->cash);
        $bookTime = $first->payload['execution_reads']['price_book']['source_as_of'];
        $this->travel(1)->seconds();
        $this->input(['execution_reads.price_book.source_as_of' => $bookTime]);
        // New persisted input/retrieval and service instance still identify the SAME liquidity sample.
        (new RealDataPaperFills)->process($this->connection, $this->connection->user_id, $buy->id);
        $this->assertSame(1, SimulationObservation::count());
        $this->assertSame(1, SimulationEvent::where('kind', 'fill')->count());
        $this->travel(1)->seconds();
        $this->input(['execution_reads.price_book.fields.book_ask_quantity' => '6']);
        $this->assertSame('filled', $processor->process($this->connection, $this->connection->user_id, $buy->id)->status);
        $this->decimal('8998.9', $this->portfolio->fresh()->cash);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $sell = $this->submit(['side' => 'SELL', 'price_bound' => '98']);
        $this->travel(1)->seconds();
        $this->input();
        $this->assertSame('filled', $processor->process($this->connection, $this->connection->user_id, $sell->id)->status);
        $this->decimal('9987.81', $this->portfolio->fresh()->cash);
        $this->decimal('0', SimulationPosition::sole()->quantity);
        $this->decimal('-12.19', SimulationPosition::sole()->realized_pnl);
        $cash = Decimal::from('0');
        foreach (CashEntry::all() as $entry) {
            $cash = $cash->plus(Decimal::from($entry->amount));
        }
        $this->decimal((string) $cash, $this->portfolio->fresh()->cash);
        $event = SimulationEvent::where('kind', 'fill')->firstOrFail();
        $this->assertSame('real_data_paper_simulation', $event->evidence['mode']);
        $this->assertSame('robinhood_paper_observation', $event->observation->snapshot['provenance']);
        $this->assertSame($first->id, $event->observation->snapshot['market_input_id']);
        $this->actingAs(User::findOrFail($this->connection->user_id))->get('/mcp/dashboard/control')
            ->assertOk()->assertSee('Robinhood data · simulated')->assertSee('real_data_paper_simulation');
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
    }

    public function test_same_sample_competing_orders_share_displayed_liquidity(): void
    {
        $first = $this->submit(['quantity' => '3']);
        $second = $this->submit(['quantity' => '3']);
        $this->travel(1)->seconds();
        $this->input();
        $service = app(RealDataPaperFills::class);
        $service->process($this->connection, $this->connection->user_id, $first->id);
        $service->process($this->connection, $this->connection->user_id, $second->id);
        $this->decimal('3', $first->fresh()->filled_quantity);
        $this->decimal('1', $second->fresh()->filled_quantity);
        $this->assertSame(1, SimulationObservation::count());
    }

    public function test_unknown_reference_data_stale_books_and_denied_tradability_are_audited_without_fills(): void
    {
        $order = $this->submit();
        foreach ([['quote.eligible' => null], ['quote.corporate_actions_clear' => null],
            ['execution_reads.price_book.semantic_fingerprint' => str_repeat('0', 64)],
            ['execution_reads.price_book.fields.book_ask' => '101'],
            ['execution_reads.price_book.source_as_of' => now()->subMinutes(2)->toIso8601String()],
            ['execution_reads.tradability.fields.broker_tradeable' => false], ['execution_reads.tradability.retrieved_at' => '']] as $changes) {
            $this->travel(1)->seconds();
            $this->input($changes);
            app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        }
        $this->assertSame(7, SimulationEvent::where('kind', 'unfilled')->count());
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame(7, SimulationEvent::where('kind', 'unfilled')->count());
        $this->decimal('10000', $this->portfolio->fresh()->cash);
    }

    public function test_same_time_and_unmarketable_observations_cannot_fill_and_expiry_releases_reservation_without_data(): void
    {
        $order = $this->submit();
        $this->input();
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame('observation_not_after_order', SimulationEvent::latest('id')->first()->reason);
        $this->travel(1)->seconds();
        $this->input(['quote.ask' => '102', 'execution_reads.price_book.fields.book_ask' => '102']);
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame('limit_not_marketable', SimulationEvent::latest('id')->first()->reason);
        $this->travel(11)->minutes();
        $this->assertSame('expired', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id)->status);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
    }

    public function test_failure_after_ledger_write_rolls_back_and_can_retry_once(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $this->input();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "mcp_paper_cash_entries"')) {
                throw new \RuntimeException('Controlled paper fill crash.');
            }
        });
        try {
            app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Controlled paper fill crash.', $e->getMessage());
        }
        $this->assertSame(0, SimulationObservation::count());
        $this->assertSame(0, SimulationPosition::count());
        $this->decimal('10000', $this->portfolio->fresh()->cash);
        $fail = false;
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame(1, SimulationEvent::where('kind', 'fill')->count());
    }

    public function test_real_data_path_rejects_synthetic_observations_and_fractional_quantities(): void
    {
        $this->execution = E::apply($this->connection, ['allow_fractional' => true, 'allow_fractional_limit' => true]);
        $fractional = $this->submit(['quantity' => '0.5']);
        $this->assertSame('rejected', $fractional->status);
        $order = $this->submit();
        config(['paper.offline_simulation_enabled' => true]);
        $this->expectException(HttpException::class);
        app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, F::quote());
    }

    public function test_action_detection_keeps_existing_position_blocked_after_a_later_clear_read(): void
    {
        $buy = $this->submit(['quantity' => '2']);
        $this->travel(1)->seconds();
        $this->input();
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $buy->id);
        $order = $this->submit(['quantity' => '2']);
        $this->travel(1)->seconds();
        $this->input(['quote.corporate_actions_clear' => false]);
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame('corporate_action_adjustment_required', SimulationPosition::sole()->blocked_reason);
        $this->travel(1)->seconds();
        $this->input();
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame('corporate_action_adjustment_required', SimulationEvent::latest('id')->first()->reason);
        $this->decimal('2', SimulationPosition::sole()->quantity);
    }

    public function test_command_processes_only_scoped_existing_real_data_order(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $this->input();
        $this->artisan('mcp:paper-fills')->assertFailed();
        $this->artisan('mcp:paper-fills', ['--account' => $this->connection->id, '--order' => $order->id])->expectsOutputToContain('real_data_paper_simulation')->assertSuccessful();
        $this->assertSame('partial', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_disabled_gate_foreign_actor_changed_binding_and_authorization_are_enforced(): void
    {
        $order = $this->submit();
        try {
            app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id + 100, $order->id);
            $this->fail('Foreign actor passed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->connection->increment('auth_generation');
        $this->assertSame('cancelled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id)->status);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $this->assertSame('authorization_changed', SimulationEvent::latest('id')->first()->reason);
        config(['paper.real_data_fills_enabled' => false]);
        $this->artisan('mcp:paper-fills', ['--account' => $this->connection->id, '--order' => $order->id])->assertFailed();
        $this->expectException(HttpException::class);
        $this->submit();
    }

    public function test_changed_settings_release_reservations_without_fresh_inputs_and_rebinding_hides_prior_orders(): void
    {
        $order = $this->submit();
        E::apply($this->connection, ['slippage_bps' => '20']);
        $this->assertSame('cancelled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id)->status);
        $this->assertSame('execution_settings_changed', SimulationEvent::latest('id')->first()->reason);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $this->connection->update(['bound_account_id' => 'another-synthetic-binding']);
        $this->expectException(ModelNotFoundException::class);
        app(RealDataPaperFills::class)->process($this->connection->fresh(), $this->connection->user_id, $order->id);
    }

    public function test_newer_unusable_input_prevents_direct_replay_of_an_older_favorable_quote(): void
    {
        $order = $this->submit();
        $this->travel(1)->seconds();
        $old = $this->quote($this->input());
        $this->travel(1)->seconds();
        $this->input(['quote.corporate_actions_clear' => null]);
        try {
            app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, $old);
            $this->fail('Superseded input passed.');
        } catch (DecisionFailure $e) {
            $this->assertSame('broker_observation_superseded', $e->reason);
        }
        $this->assertSame(0, SimulationEvent::where('kind', 'fill')->count());
    }

    public function test_stored_synthetic_or_late_available_evidence_cannot_be_promoted_to_a_real_quote(): void
    {
        $input = $this->input();
        try {
            BrokerSimulationQuote::fromStored($this->connection, $input->id, CarbonImmutable::now()->subSecond(), 60);
            $this->fail('Future local record passed.');
        } catch (DecisionFailure $e) {
            $this->assertSame('market_input_after_cutoff', $e->reason);
        }
        $this->expectException(HttpException::class);
        $this->quote(MarketInput::where('origin', 'synthetic')->firstOrFail());
    }
}
