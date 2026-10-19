<?php

namespace Tests\Feature\PaperTrading;

use App\Jobs\PaperDecisionJob;
use App\Models\BrokerOrder;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\OrderHandoff;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\PaperTrading\DecisionRunner;
use App\Services\PaperTrading\DecisionScheduler;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\PaperOrderHandoff;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\StoredDecisionInputs;
use App\Services\Research\ResearchModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BrokerInputFixture;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\FakeDecisionModel;
use Tests\Support\ResearchFixture;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class PaperOrderHandoffTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $execution;

    private FakeDecisionModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio, , $this->execution] = E::seed();
        ResearchFixture::enable();
        config(['paper.decisions_enabled' => true, 'paper.handoff_enabled' => true]);
        $this->model = new FakeDecisionModel;
        $this->model->respond = fn ($request) => FakeDecisionModel::answer($request, function ($data) {
            $data['decisions'][0]['action'] = 'BUY';
            $data['decisions'][0]['target_weight_pct'] = '10';

            return $data;
        });
        $this->app->instance(ResearchModel::class, $this->model);
        Queue::fake();
    }

    private function proposal(): DecisionRun
    {
        app(DecisionScheduler::class)->tick();
        $run = DecisionRun::where('mcp_connection_id', $this->connection->id)->latest('scheduled_at')->latest('id')->firstOrFail();

        return app(DecisionRunner::class)->process($run->id);
    }

    public function test_broker_observations_reach_paper_order_and_later_fill_with_original_provenance(): void
    {
        config(['paper.real_data_fills_enabled' => true, 'paper.offline_simulation_enabled' => false]);
        BrokerInputFixture::record($this->connection);
        $run = $this->proposal();
        $this->assertSame('proposed', $run->status, $run->reason ?? '');
        $handoff = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('order_created', $handoff->status, $handoff->reason ?? '');
        $order = SimulationOrder::findOrFail($handoff->sim_order_id);
        $this->assertSame('real_data_paper_simulation', $order->intent['origin']);
        $this->travel(1)->seconds();
        BrokerInputFixture::record($this->connection, ['execution_reads.price_book.fields.book_ask_quantity' => '100']);
        $this->assertSame('filled', app(RealDataPaperFills::class)->process($this->connection, $this->connection->user_id, $order->id)->status);
        $this->assertSame('9.000000000000000000', SimulationPosition::sole()->quantity);
        $this->assertSame('9099.010000000000000000', $this->portfolio->fresh()->cash);
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
    }

    public function test_disabled_real_data_gate_records_unavailable_handoff_without_orders(): void
    {
        BrokerInputFixture::record($this->connection);
        $run = $this->proposal();
        $handoff = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('real_data_paper_fills_disabled', $handoff->reason);
        $this->assertSame('unavailable', $handoff->status);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_applied_percentage_fee_reaches_order_reservation_and_later_fill(): void
    {
        $this->execution = E::apply($this->connection, ['fee_bps' => '100', 'fee_per_share' => '0']);
        $run = $this->proposal();
        $outcome = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('order_created', $outcome->status);
        $order = SimulationOrder::findOrFail($outcome->sim_order_id);
        $this->assertSame('100', $order->assumptions['fee_bps']);
        $this->assertSame('910.818000000000000000', $this->portfolio->fresh()->reserved_cash);
        $this->travel(1)->seconds();
        $order = app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $order->id, F::quote());
        $this->assertSame('filled', $order->status);
        $this->assertSame('9090.091000000000000000', $this->portfolio->fresh()->cash);
        Http::assertNothingSent();
    }

    public function test_stored_verified_fixture_inputs_reach_the_model_and_a_deduplicated_bounded_paper_order(): void
    {
        $run = $this->proposal();
        $this->assertSame('proposed', $run->status, $run->reason ?? '');
        $this->assertSame($this->execution->id, $run->execution_version_id);
        $this->assertSame($this->execution->payload, $run->request_snapshot['input']['paper_execution']);
        $this->assertSame('0', $run->request_snapshot['input']['paper_portfolio']['positions']['DEMO']);
        $handoff = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('order_created', $handoff->status, $handoff->reason ?? '');
        $order = SimulationOrder::findOrFail($handoff->sim_order_id);
        $this->assertSame('9.000000000000000000', $order->quantity);
        $this->assertSame('100.200000000000000000', $order->price_bound);
        $this->assertSame('open', $order->status);
        $this->assertSame($this->execution->id, $order->execution_version_id);
        $this->assertSame('901.890000000000000000', $this->portfolio->fresh()->reserved_cash);
        $this->assertSame('10000.000000000000000000', $this->portfolio->fresh()->cash);
        $this->assertNotEmpty($handoff->evidence['market_input_ids']);
        $this->travel(6)->minutes();
        app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id);
        $this->assertSame(1, OrderHandoff::count());
        $this->assertSame(1, SimulationOrder::count());
        $this->assertCount(1, $this->model->requests);
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
    }

    public function test_wait_is_recorded_distinctly_without_order_or_cash_reservation(): void
    {
        $this->model->respond = null;
        $run = $this->proposal();
        $this->assertSame('wait', $run->status);
        $result = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('no_order', $result->status);
        $this->assertSame('wait', $result->reason);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_execution_change_while_model_runs_supersedes_the_paid_result(): void
    {
        $answer = $this->model->respond;
        $this->model->respond = function ($request) use ($answer) {
            E::apply($this->connection, ['slippage_bps' => '20']);

            return $answer($request);
        };
        $run = $this->proposal();
        $this->assertSame('superseded', $run->status);
        $this->assertSame('execution_settings_changed', $run->reason);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_execution_change_after_proposal_blocks_handoff_and_after_acceptance_cancels_fills(): void
    {
        $run = $this->proposal();
        $handoff = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        E::apply($this->connection, ['slippage_bps' => '20']);
        $this->travel(1)->seconds();
        $order = app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $handoff->sim_order_id, F::quote());
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('0.000000000000000000', $this->portfolio->fresh()->reserved_cash);
    }

    public function test_stale_ledger_and_expired_proposals_record_unavailable_without_replay(): void
    {
        $run = $this->proposal();
        $this->portfolio->increment('ledger_version');
        $result = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('paper_ledger_changed', $result->reason);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_owner_permissions_and_unknown_newer_market_inputs_cannot_be_overridden_by_model_text(): void
    {
        E::apply($this->connection, ['allow_entries' => false]);
        $run = $this->proposal();
        $this->assertSame('proposed', $run->status);
        $result = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('owner_execution_permission_denied', $result->reason);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_newer_unknown_input_blocks_a_previously_valid_proposal(): void
    {
        $run = $this->proposal();
        $this->travel(1)->seconds();
        app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id, F::quote(['eligible' => null]), (string) Str::uuid());
        $result = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole();
        $this->assertSame('instrument_eligibility_unverified', $result->reason);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_late_arriving_input_is_absent_from_a_historical_cutoff_even_with_old_source_time(): void
    {
        $cutoff = CarbonImmutable::now();
        $this->travel(1)->seconds();
        app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id,
            F::quote(['source_at' => $cutoff->toIso8601String(), 'eligible' => false]), (string) Str::uuid());
        $inputs = app(StoredDecisionInputs::class)->load($this->connection, $cutoff);
        $this->assertSame('eligible', $inputs['subjects']['DEMO']['eligibility']);
        $current = app(StoredDecisionInputs::class)->load($this->connection, CarbonImmutable::now());
        $this->assertSame('unknown', $current['subjects']['DEMO']['eligibility']);
    }

    public function test_a_fake_buy_and_later_exit_flow_through_proposals_orders_and_later_observations(): void
    {
        $buy = $this->proposal();
        $buyHandoff = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $buy->id)->sole();
        $this->travel(1)->seconds();
        app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $buyHandoff->sim_order_id, F::quote());
        $this->assertSame('9.000000000000000000', SimulationPosition::sole()->quantity);
        $this->travel(5)->minutes();
        app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id, F::quote(['bid' => '110', 'ask' => '111']), (string) Str::uuid());
        $this->model->respond = fn ($request) => FakeDecisionModel::answer($request, function ($data) {
            $data['decisions'][0]['action'] = 'SELL';
            $data['decisions'][0]['target_weight_pct'] = '0';

            return $data;
        });
        $sell = $this->proposal();
        $this->assertSame('proposed', $sell->status, $sell->reason ?? '');
        $this->assertSame('9.000000000000000000', $sell->request_snapshot['input']['paper_portfolio']['positions']['DEMO']);
        $sellHandoff = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $sell->id)->sole();
        $this->assertSame('order_created', $sellHandoff->status, $sellHandoff->reason ?? '');
        $this->travel(1)->seconds();
        app(OfflineSimulator::class)->observe($this->connection, $this->connection->user_id, $sellHandoff->sim_order_id, F::quote(['bid' => '110', 'ask' => '111']));
        $this->assertSame('0.000000000000000000', SimulationPosition::sole()->quantity);
        $this->assertSame('10087.930000000000000000', $this->portfolio->fresh()->cash);
        $this->assertSame(0, BrokerOrder::count());
        Http::assertNothingSent();
    }

    public function test_failure_after_order_reservation_rolls_back_the_whole_handoff_and_retries_without_a_new_model_call(): void
    {
        $run = $this->proposal();
        $fail = true;
        DB::listen(function (QueryExecuted $query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into "mcp_paper_order_handoffs"')) {
                throw new \RuntimeException('Synthetic handoff crash.');
            }
        });
        try {
            app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id);
            $this->fail('Crash not observed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic handoff crash.', $e->getMessage());
        }
        $this->assertSame(0, SimulationOrder::count());
        $this->assertSame(0, OrderHandoff::count());
        $this->assertSame('0.000000000000000000', $this->portfolio->fresh()->reserved_cash);
        $fail = false;
        app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id);
        $this->assertSame(1, SimulationOrder::count());
        $this->assertCount(1, $this->model->requests);
    }

    public function test_batch_reservations_recheck_external_ledger_once_and_preserve_all_symbol_outcomes(): void
    {
        E::apply($this->connection, ['allowed_symbols' => ['DEMO', 'SECOND']]);
        app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id, F::quote(['symbol' => 'SECOND']), (string) Str::uuid());
        $this->model->respond = fn ($request) => FakeDecisionModel::answer($request, function ($data) {
            foreach ($data['decisions'] as &$decision) {
                $decision['action'] = 'BUY';
                $decision['target_weight_pct'] = '10';
            }

            return $data;
        });
        $run = $this->proposal();
        $this->assertSame('proposed', $run->status, $run->reason ?? '');
        $results = app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id);
        $this->assertSame(['order_created', 'order_created'], $results->pluck('status')->all());
        $this->assertSame(2, SimulationOrder::count());
        $this->assertSame('1803.780000000000000000', $this->portfolio->fresh()->reserved_cash);
    }

    public function test_queue_handoff_requires_its_own_flag_and_does_not_recall_the_model_for_a_completed_run(): void
    {
        $run = $this->proposal();
        $job = new PaperDecisionJob($run->id, $run->scope);
        config(['paper.handoff_enabled' => false]);
        $job->handle(app(DecisionRunner::class));
        $this->assertSame(0, OrderHandoff::count());
        config(['paper.handoff_enabled' => true]);
        $job->handle(app(DecisionRunner::class));
        $job->handle(app(DecisionRunner::class));
        $this->assertSame(1, SimulationOrder::count());
        $this->assertCount(1, $this->model->requests);
    }

    public function test_original_market_availability_retains_microseconds_through_request_and_handoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00.100000Z'));
        app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id, F::quote(), (string) Str::uuid());
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00.900000Z'));
        $run = $this->proposal();
        $this->assertSame('2026-10-19T14:00:00.900000+00:00', $run->request_snapshot['input']['requested_at']);
        $this->assertSame('order_created', app(PaperOrderHandoff::class)->process($this->connection, $this->connection->user_id, $run->id)->sole()->status);
    }

    public function test_applied_quote_age_is_used_in_the_request_and_after_the_model_returns(): void
    {
        E::apply($this->connection, ['quote_max_age_seconds' => 1]);
        $answer = $this->model->respond;
        $this->model->respond = function ($request) use ($answer) {
            $this->assertSame(1, FakeDecisionModel::context($request)['proposal_guards']['quote_max_age_seconds']);
            $this->travel(2)->seconds();

            return $answer($request);
        };
        $run = $this->proposal();
        $this->assertSame('rejected', $run->status);
        $this->assertSame('quote_unknown_or_stale', $run->reason);
    }
}
