<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\CashEntry;
use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\PaperTrading\BrokerSimulationQuote;
use App\Services\PaperTrading\OfflineSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\BrokerInputFixture;
use Tests\Support\ExecutionFixture;
use Tests\Support\PaperActionFixture;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class PaperSimulationConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    private function race(McpConnection $connection, array $requests): array
    {
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($requests as $request) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-paper-simulation-worker.php'), (string) $connection->id,
                    base64_encode(json_encode($request, JSON_THROW_ON_ERROR)), now()->toIso8601String()], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            sort($results);

            return $results;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function test_two_stale_scenarios_cannot_reserve_the_same_capital(): void
    {
        [$connection, $portfolio, $policy] = F::seed();
        $request = ['operation' => 'submit', 'policy_id' => $policy->id, 'ledger_version' => 1,
            'assumptions' => F::assumptions()->data, 'quote' => F::quote()->data];
        $this->assertSame(['conflict', 'open'], $this->race($connection, [$request + ['intent' => F::intent(['quantity' => '15'])],
            $request + ['intent' => F::intent(['quantity' => '15'])]]));
        $this->assertSame(1, SimulationOrder::where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame('1515.150000000000000000', $portfolio->fresh()->reserved_cash);
        $this->assertSame('10000.000000000000000000', $portfolio->fresh()->cash);
    }

    public function test_competing_orders_share_one_tick_liquidity_and_duplicate_workers_do_not_refill(): void
    {
        [$connection, $portfolio, $policy] = F::seed();
        $orders = [];
        foreach ([1, 2] as $ledger) {
            $orders[] = app(OfflineSimulator::class)->submit($connection, $connection->user_id, $policy->id, $ledger,
                F::intent(['quantity' => '5']), F::assumptions(), ['DEMO' => F::quote()]);
        }
        $this->travel(1)->seconds();
        $request = ['operation' => 'observe', 'quote' => F::quote(['ask_size' => '6'])->data];
        $this->assertSame(['filled', 'partial'], $this->race($connection, [$request + ['order_id' => $orders[0]->id], $request + ['order_id' => $orders[1]->id]]));
        $this->assertSame('6.000000000000000000', SimulationPosition::where('paper_portfolio_id', $portfolio->id)->sole()->quantity);
        $this->assertSame(2, SimulationEvent::whereIn('sim_order_id', array_map(fn ($o) => $o->id, $orders))->where('kind', 'fill')->count());
        $partial = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('status', 'partial')->sole();
        $this->travel(1)->seconds();
        $request = ['operation' => 'observe', 'order_id' => $partial->id, 'quote' => F::quote()->data];
        $this->assertSame(['filled', 'filled'], $this->race($connection, [$request, $request]));
        $this->assertSame('10.000000000000000000', SimulationPosition::where('paper_portfolio_id', $portfolio->id)->sole()->quantity);
        $this->assertSame('8998.900000000000000000', $portfolio->fresh()->cash);
        $this->assertSame('0.000000000000000000', $portfolio->fresh()->reserved_cash);
        $this->assertSame(4, CashEntry::where('paper_portfolio_id', $portfolio->id)->count());
    }

    public function test_real_data_workers_share_one_book_and_duplicate_workers_do_not_refill(): void
    {
        [$connection, $portfolio, $policy, $execution] = ExecutionFixture::seed();
        config(['paper.real_data_fills_enabled' => true]);
        $input = BrokerInputFixture::record($connection);
        $quote = BrokerSimulationQuote::fromStored($connection, $input->id, CarbonImmutable::now(), 60);
        $orders = [];
        foreach ([1, 2] as $ledger) {
            $orders[] = app(OfflineSimulator::class)->submit($connection, $connection->user_id, $policy->id, $ledger,
                F::intent(['quantity' => '3']), F::assumptions(), ['DEMO' => $quote], $execution->id);
        }
        $this->travel(1)->seconds();
        BrokerInputFixture::record($connection);
        $request = ['operation' => 'real_observe'];
        $this->assertSame(['filled', 'partial'], $this->race($connection, [$request + ['order_id' => $orders[0]->id], $request + ['order_id' => $orders[1]->id]]));
        $partial = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->where('status', 'partial')->sole();
        $request['order_id'] = $partial->id;
        $this->assertSame(['partial', 'partial'], $this->race($connection, [$request, $request]));
        $this->assertSame('4.000000000000000000', SimulationPosition::where('paper_portfolio_id', $portfolio->id)->sole()->quantity);
        $this->assertSame('9599.560000000000000000', $portfolio->fresh()->cash);
        $this->assertSame(2, SimulationEvent::whereIn('sim_order_id', array_map(fn ($o) => $o->id, $orders))->where('kind', 'fill')->count());
    }

    public function test_duplicate_corporate_action_workers_pay_one_entitlement_and_advance_the_ledger_once(): void
    {
        [$connection, $portfolio, $policy, $execution] = ExecutionFixture::seed();
        config(['paper.real_data_fills_enabled' => true]);
        $input = BrokerInputFixture::record($connection);
        $quote = BrokerSimulationQuote::fromStored($connection, $input->id, CarbonImmutable::now(), 60);
        $order = app(OfflineSimulator::class)->submit($connection, $connection->user_id, $policy->id, 1,
            F::intent(['quantity' => '4']), F::assumptions(), ['DEMO' => $quote], $execution->id);
        $this->travel(1)->seconds();
        $input = BrokerInputFixture::record($connection);
        $quote = BrokerSimulationQuote::fromStored($connection, $input->id, CarbonImmutable::now(), 60);
        app(OfflineSimulator::class)->observe($connection, $connection->user_id, $order->id, $quote);
        $this->travelTo(CarbonImmutable::parse('2026-10-22T14:00:00Z'));
        $input = PaperActionFixture::input($connection, [PaperActionFixture::dividend()]);
        $version = $portfolio->fresh()->ledger_version;
        $request = ['operation' => 'actions', 'input_id' => $input->id];
        $this->assertSame(['applied', 'applied'], $this->race($connection, [$request, $request]));
        $this->assertSame('9600.560000000000000000', $portfolio->fresh()->cash);
        $this->assertSame($version + 2, $portfolio->fresh()->ledger_version);
        $this->assertSame(1, CashEntry::where('paper_portfolio_id', $portfolio->id)->where('kind', 'simulated_dividend')->count());
        $this->assertSame(3, CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_paper_action_records'")->schemaname);
    }

    public function test_postgres_constraints_protect_quantity_and_reservations_and_place_new_tables_in_schema(): void
    {
        [$connection, $portfolio, $policy] = F::seed();
        $order = app(OfflineSimulator::class)->submit($connection, $connection->user_id, $policy->id, 1, F::intent(), F::assumptions(), ['DEMO' => F::quote()]);
        foreach (['quantity' => '-1', 'reserved_cash' => '-0.000000000000000001', 'filled_quantity' => '11'] as $field => $value) {
            try {
                DB::transaction(fn () => DB::table('mcp_paper_sim_orders')->where('id', $order->id)->update([$field => $value]));
                $this->fail('Invalid order passed PostgreSQL constraints.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $tables = DB::select("SELECT schemaname, tableowner FROM pg_tables WHERE tablename LIKE 'mcp_paper_sim_%'");
        $this->assertCount(4, $tables);
        foreach ($tables as $table) {
            $this->assertSame('mcp_trading', $table->schemaname);
            $this->assertSame(DB::selectOne('SELECT current_user AS role')->role, $table->tableowner);
        }
    }
}
