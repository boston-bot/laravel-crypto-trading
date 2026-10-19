<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\User;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\CorporateActionClearance;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\PaperCorporateActions;
use App\Services\PaperTrading\PaperIncome;
use App\Services\PaperTrading\PaperPolicies;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\SimulationOverview;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BrokerInputFixture as B;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperActionFixture as A;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class PaperCorporateActionsTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $policy;

    private $execution;

    private array $knownEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio, $this->policy, $this->execution] = E::seed();
        config(['paper.real_data_fills_enabled' => true, 'paper.corporate_actions_enabled' => true]);
    }

    private function day(string $day): void
    {
        $this->travelTo(CarbonImmutable::parse($day.'T14:00:00Z'));
        app(MarketCalendar::class)->store($day, 'regular', $day.'T13:30:00Z', $day.'T20:00:00Z', 'synthetic_fixture');
    }

    private function order(array $changes = [])
    {
        $input = A::input($this->connection, $this->knownEvents);
        $quote = BrokerSimulationQuote::fromStored($this->connection, $input->id, CarbonImmutable::now(), 60);

        return app(OfflineSimulator::class)->submit($this->connection, $this->connection->user_id, $this->policy->id, $this->portfolio->fresh()->ledger_version,
            F::intent($changes), F::assumptions(), ['DEMO' => $quote], $this->execution->id);
    }

    private function fill($order): void
    {
        $this->travel(1)->seconds();
        A::input($this->connection, $this->knownEvents, ['execution_reads.price_book.fields.book_ask_quantity' => '100']);
        app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);
        $this->assertSame('filled', $order->fresh()->status);
    }

    private function process(array $events)
    {
        $this->knownEvents = $events;
        $input = A::input($this->connection, $events);

        return app(PaperCorporateActions::class)->process($this->connection, $this->connection->user_id, $input->id);
    }

    private function decimal(string $expected, string $actual): void
    {
        $this->assertSame(0, Decimal::from($expected)->compareTo(Decimal::from($actual)));
    }

    public function test_expired_order_releases_reservations_before_requiring_complete_action_data(): void
    {
        $order = $this->order();
        $this->day('2026-10-20');
        A::input($this->connection, [], ['corporate_action_reads.alpaca.complete' => false]);

        $result = app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id);

        $this->assertSame('expired', $result->status);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $this->assertSame(0, CorporateActionRecord::count());
        Http::assertNothingSent();
    }

    public function test_whole_share_split_preserves_basis_cash_releases_pending_reservations_and_deduplicates_after_restart(): void
    {
        $this->fill($this->order());
        $pending = $this->order(['quantity' => '2']);
        $cash = $this->portfolio->fresh()->cash;
        $basis = SimulationPosition::sole()->cost_basis;
        $this->day('2026-10-20');
        $event = A::event();
        $result = $this->process([$event])->last();
        $this->assertSame('applied', $result->status);
        $this->decimal('20', SimulationPosition::sole()->quantity);
        $this->decimal($basis, SimulationPosition::sole()->cost_basis);
        $this->decimal($cash, $this->portfolio->fresh()->cash);
        $this->decimal('0', $this->portfolio->fresh()->reserved_cash);
        $this->assertSame('expired', $pending->fresh()->status);
        $version = $this->portfolio->fresh()->ledger_version;
        $repeat = $this->process([$event])->last();
        $this->assertSame($result->id, $repeat->id);
        $this->assertSame($version, $this->portfolio->fresh()->ledger_version);
        $this->assertSame(2, CorporateActionRecord::count());
        $this->assertStringNotContainsString('synthetic-action', DB::table('mcp_paper_action_records')->where('id', $result->id)->value('payload'));
        Http::assertNothingSent();
    }

    public function test_dividend_entitlement_survives_sale_and_is_paid_once_on_payable_date_as_income(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-20');
        $event = A::dividend();
        $cash = $this->portfolio->fresh()->cash;
        $entitlement = $this->process([$event])->last();
        $this->assertSame('entitlement', $entitlement->phase);
        $this->assertSame('2.500000000000000000', $entitlement->payload['gross_amount']);
        $this->decimal($cash, $this->portfolio->fresh()->cash);
        $sell = $this->order(['side' => 'SELL', 'price_bound' => '98']);
        $this->fill($sell);
        $this->decimal('0', SimulationPosition::sole()->quantity);
        $beforePayment = $this->portfolio->fresh()->cash;
        $this->day('2026-10-22');
        $application = $this->process([$event])->last();
        $this->assertSame('applied', $application->status);
        $this->decimal((string) Decimal::from($beforePayment)->plus(Decimal::from('2.5')), $this->portfolio->fresh()->cash);
        $this->process([$event]);
        $this->assertSame(1, CashEntry::where('kind', 'simulated_dividend')->count());
        $overview = app(SimulationOverview::class)->for($this->connection, app(PaperPolicies::class)->state($this->connection));
        $this->decimal('2.5', $overview['dividend_income']);
        $cashSum = Decimal::from('0');
        foreach (CashEntry::all() as $entry) {
            $cashSum = $cashSum->plus(Decimal::from($entry->amount));
        }
        $this->decimal((string) $cashSum, $this->portfolio->fresh()->cash);
        $this->actingAs(User::findOrFail($this->connection->user_id))->get('/mcp/dashboard/control')->assertOk()->assertSee('Paper corporate actions')->assertSee('Gross simulated cash dividends');
    }

    public function test_late_dividend_uses_ex_date_holdings_and_excludes_later_purchases(): void
    {
        $this->fill($this->order(['quantity' => '4']));
        $this->day('2026-10-20');
        $this->fill($this->order(['quantity' => '3']));
        $this->day('2026-10-22');
        $application = $this->process([A::dividend()])->last();
        $this->assertSame('applied', $application->status);
        $this->decimal('1', CashEntry::where('kind', 'simulated_dividend')->sole()->amount);
        $this->decimal('7', SimulationPosition::sole()->quantity);
    }

    public function test_late_split_with_intervening_trades_freezes_without_rewriting_fills_or_positions(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-20');
        $this->fill($this->order(['quantity' => '1']));
        $cash = $this->portfolio->fresh()->cash;
        $result = $this->process([A::event()])->last();
        $this->assertSame('late_split_intervening_trades', $result->reason);
        $this->decimal('11', SimulationPosition::sole()->quantity);
        $this->decimal($cash, $this->portfolio->fresh()->cash);
        $this->assertSame('corporate_action_review_required', SimulationPosition::sole()->blocked_reason);
    }

    public function test_corrections_and_disappearing_events_freeze_without_double_payment_or_reversal(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-22');
        $event = A::dividend();
        $this->process([$event]);
        $cash = $this->portfolio->fresh()->cash;
        $changed = A::dividend(['rates' => ['rate' => '0.30']]);
        $result = $this->process([$changed])->sole();
        $this->assertSame('provider_event_corrected_requires_review', $result->reason);
        $this->decimal($cash, $this->portfolio->fresh()->cash);
        $this->assertSame(1, CashEntry::where('kind', 'simulated_dividend')->count());
        $result = $this->process([])->sole();
        $this->assertSame('event_missing_from_refresh', $result->reason);
        $this->decimal($cash, $this->portfolio->fresh()->cash);
    }

    public function test_fractional_reverse_split_and_special_dividend_terms_are_blocked(): void
    {
        $this->fill($this->order(['quantity' => '3']));
        $this->day('2026-10-20');
        $result = $this->process([A::event(['type' => 'reverse_splits', 'rates' => ['new_rate' => '1', 'old_rate' => '2']])])->last();
        $this->assertSame('split_fraction_or_ratio_requires_review', $result->reason);
        $this->decimal('3', SimulationPosition::sole()->quantity);
        $this->assertSame(0, CorporateActionRecord::where('phase', 'application')->count());
    }

    public function test_special_foreign_due_bill_and_unsupported_actions_remain_blocked(): void
    {
        $this->day('2026-10-22');
        $event = A::dividend(['flags' => ['special' => true]]);
        $result = $this->process([$event])->last();
        $this->assertSame('dividend_terms_require_review', $result->reason);
        $this->assertSame(0, CashEntry::where('kind', 'simulated_dividend')->count());
        $this->assertSame('corporate_action_review_required', SimulationPosition::sole()->blocked_reason);
        $this->assertSame(0, CorporateActionRecord::where('phase', 'application')->count());
    }

    public function test_late_split_cannot_rewrite_an_already_booked_later_entitlement(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-22');
        $dividend = A::dividend(['id' => 'later-dividend', 'dates' => ['ex_date' => '2026-10-21', 'record_date' => '2026-10-21', 'payable_date' => '2026-10-30']]);
        $this->process([$dividend]);
        $result = $this->process([A::event(), $dividend])->last();
        $this->assertSame('late_action_dependency_requires_review', $result->reason);
        $this->decimal('10', SimulationPosition::sole()->quantity);
        $this->assertSame(0, CashEntry::where('kind', 'simulated_dividend')->count());
    }

    public function test_post_event_buyer_has_no_split_entitlement_and_numeric_formatting_is_not_a_correction(): void
    {
        $this->day('2026-10-20');
        $this->fill($this->order());
        $result = $this->process([A::event()])->last();
        $this->assertSame('no_effect', $result->status);
        $this->decimal('10', SimulationPosition::sole()->quantity);
        $repeat = $this->process([A::event(['rates' => ['new_rate' => '2.000', 'old_rate' => '1.0']])])->last();
        $this->assertSame($result->id, $repeat->id);
        $this->assertSame(0, CorporateActionRecord::where('phase', 'block')->count());
    }

    public function test_dividend_receivable_changes_equity_but_not_cash_and_cash_only_baseline_cannot_hide_it(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-20');
        $before = $this->portfolio->fresh()->cash;
        $this->process([A::dividend()]);
        $income = app(PaperIncome::class);
        $this->decimal('2.5', (string) $income->receivable($this->portfolio));
        $this->decimal($before, $this->portfolio->fresh()->cash);
        $this->decimal('0', (string) $income->received($this->portfolio));
        $version = $this->portfolio->fresh()->ledger_version;
        $this->process([A::dividend()]);
        $this->assertSame($version, $this->portfolio->fresh()->ledger_version);
    }

    public function test_future_events_are_only_observed_and_newer_incomplete_inputs_cannot_be_processed(): void
    {
        $result = $this->process([A::event()])->sole();
        $this->assertSame('observed', $result->status);
        $this->assertDatabaseCount('mcp_paper_sim_positions', 0);
        $input = B::record($this->connection, ['corporate_action_reads.alpaca.complete' => false]);
        $this->expectException(HttpException::class);
        app(PaperCorporateActions::class)->process($this->connection, $this->connection->user_id, $input->id);
    }

    public function test_failure_after_cash_mutation_rolls_back_every_phase_and_retries_once(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-22');
        $input = A::input($this->connection, [A::dividend()]);
        $cash = $this->portfolio->fresh()->cash;
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "mcp_paper_cash_entries"')) {
                throw new \RuntimeException('Synthetic action crash.');
            }
        });
        try {
            app(PaperCorporateActions::class)->process($this->connection, $this->connection->user_id, $input->id);
            $this->fail('Expected rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic action crash.', $e->getMessage());
        }
        $this->decimal($cash, $this->portfolio->fresh()->cash);
        $this->assertSame(0, CorporateActionRecord::count());
        $fail = false;
        app(PaperCorporateActions::class)->process($this->connection, $this->connection->user_id, $input->id);
        $this->assertSame(1, CashEntry::where('kind', 'simulated_dividend')->count());
    }

    public function test_observed_clearance_is_bounded_and_never_relabels_unknown_publication_time(): void
    {
        $input = A::input($this->connection, []);
        $service = app(CorporateActionClearance::class);
        $policy = $service->inspect($input, CarbonImmutable::now(), 60);
        $this->assertTrue($policy['clear']);
        $this->assertTrue($policy['publication_delay_possible']);
        $this->assertNull($input->payload['corporate_action_reads']['alpaca']['corporate_actions_clear']);
        $this->assertNull($input->payload['corporate_action_reads']['alpaca']['source_as_of']);
        $this->assertSame('observed_window_reconciled', $policy['status']);
        $short = A::input($this->connection, [], ['corporate_action_reads.alpaca' => array_replace($input->payload['corporate_action_reads']['alpaca'], ['start' => '2026-10-20'])]);
        $this->assertNull($service->inspect($short, CarbonImmutable::now(), 60)['clear']);
        $this->travel(61)->seconds();
        $this->assertNull($service->inspect($input, CarbonImmutable::now(), 60)['clear']);
    }

    public function test_unreconciled_reported_action_blocks_clearance_until_accounting_and_history_remain_point_in_time(): void
    {
        $this->fill($this->order());
        $this->day('2026-10-20');
        $input = A::input($this->connection, [A::event()]);
        $cutoff = CarbonImmutable::now();
        $service = app(CorporateActionClearance::class);
        $this->assertSame('known_action_not_reconciled', $service->inspect($input, $cutoff, 60)['reason']);
        $this->travel(1)->seconds();
        app(PaperCorporateActions::class)->process($this->connection, $this->connection->user_id, $input->id);
        $this->assertTrue($service->inspect($input, CarbonImmutable::now(), 60)['clear']);
        $this->assertNull($service->inspect($input, $cutoff, 60)['clear']);
    }

    public function test_gate_actor_scoping_and_immutable_audit_are_enforced(): void
    {
        $input = A::input($this->connection, [A::event()]);
        try {
            app(PaperCorporateActions::class)->process($this->connection, $this->connection->user_id + 100, $input->id);
            $this->fail('Foreign actor passed.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->artisan('mcp:paper-actions', ['--account' => $this->connection->id, '--input' => $input->id])->assertSuccessful();
        config(['paper.corporate_actions_enabled' => false]);
        $this->artisan('mcp:paper-actions', ['--account' => $this->connection->id, '--input' => $input->id])->assertFailed();
        $this->expectException(\LogicException::class);
        CorporateActionRecord::sole()->update(['status' => 'applied']);
    }
}
