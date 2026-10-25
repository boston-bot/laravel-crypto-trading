<?php

namespace Tests\Feature\PaperTrading;

use App\Jobs\CollectPaperMarketInput;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Services\Broker\Agentic\BrokerBackoff;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\BatchMarketInputCollector;
use App\Services\PaperTrading\MarketInputCollector;
use App\Services\PaperTrading\PaperMarketInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperMarketGateway;
use Tests\TestCase;

class BatchMarketInputCollectorTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private PaperMarketGateway $gateway;

    private int $executionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $p, , $execution] = E::seed(['allowed_symbols' => ['ALFA', 'BRAVO', 'DEMO']]);
        $this->executionId = $execution->id;
        SimulationPosition::create(['paper_portfolio_id' => $p->id, 'symbol' => 'DEMO', 'sleeve' => 'active', 'quantity' => '1',
            'reserved_quantity' => '0', 'cost_basis' => '100', 'realized_pnl' => '0', 'updated_at' => now()]);
        config(['paper.batch_collection_enabled' => true, 'paper.market_collection_enabled' => true, 'paper.market_inputs_enabled' => true]);
        $this->gateway = new PaperMarketGateway;
        $this->gateway->respond = fn ($c, $op, $filters) => $this->response($op, $filters);
        $this->app->instance(BrokerGateway::class, $this->gateway);
    }

    private function response(Operation $op, array $filters, ?array $only = null): ReadResult
    {
        $symbols = $only ?? ($filters['symbols'] ?? [$filters['symbol']]);
        $rows = array_map(fn ($s) => BrokerRecord::from($op, $op === Operation::Quotes
            ? ['symbol' => $s, 'bid' => '99', 'ask' => '100', 'bid_as_of' => now()->toISOString(), 'ask_as_of' => now()->toISOString()]
            : ['symbol' => $s, 'id' => 'synthetic-instrument', 'name' => 'Synthetic'], null), $symbols);

        return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), $rows, true);
    }

    public function test_full_universe_quotes_are_batched_holdings_first_and_retries_share_immutable_inputs(): void
    {
        $collector = app(BatchMarketInputCollector::class);
        $this->assertSame(['recorded' => 3, 'selected' => 3, 'stopped' => null], $collector->tick($this->connection->id));
        $this->assertSame([Operation::Quotes, ['symbols' => ['DEMO', 'ALFA', 'BRAVO']]], $this->gateway->calls[0]);
        $this->assertCount(4, $this->gateway->calls);
        $this->assertSame(1, $this->gateway->scopes);
        $this->assertSame([true, true, true, true], $this->gateway->inside);
        $collector->tick($this->connection->id);
        $this->assertCount(4, $this->gateway->calls);
        $this->assertSame(3, MarketInput::where('origin', 'broker_read')->count());
        $this->assertSame(0, SimulationOrder::count());
        Http::assertNothingSent();
    }

    public function test_oldest_unheld_symbols_rotate_before_recently_refreshed_symbols(): void
    {
        app(BatchMarketInputCollector::class)->tick($this->connection->id);
        $this->travel(5)->minutes();
        app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'ALFA', (string) Str::uuid());
        $this->gateway->calls = [];
        app(BatchMarketInputCollector::class)->tick($this->connection->id);
        $this->assertSame([Operation::Quotes, ['symbols' => ['DEMO', 'BRAVO', 'ALFA']]], $this->gateway->calls[0]);
    }

    public function test_record_consumer_failure_keeps_the_input_and_retry_resumes_without_refetching_it(): void
    {
        $request = (string) Str::uuid();
        $seen = [];
        $consumer = function ($rows) use (&$seen) {
            $seen[] = array_key_first($rows);
            throw new \RuntimeException('Synthetic consumer crash.');
        };
        try {
            app(PaperMarketInputs::class)->collectMany($this->connection, $this->connection->user_id, ['DEMO', 'ALFA'], $request, fn () => null, afterRecord: $consumer);
            $this->fail('Expected consumer failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic consumer crash.', $e->getMessage());
        }
        $input = MarketInput::where('origin', 'broker_read')->sole();
        $this->assertSame(['DEMO'], $seen);
        $this->gateway->calls = [];
        $rows = app(PaperMarketInputs::class)->collectMany($this->connection, $this->connection->user_id, ['DEMO', 'ALFA'], $request, fn () => null,
            afterRecord: function ($rows) use (&$seen) {
                $seen[] = array_key_first($rows);
            });
        $this->assertSame(['DEMO', 'DEMO', 'ALFA'], $seen);
        $this->assertSame($input->id, $rows['DEMO']->id);
        $this->assertSame([Operation::Quotes, ['symbol' => 'ALFA']], $this->gateway->calls[0]);
        $this->assertSame(2, MarketInput::where('origin', 'broker_read')->count());
        $this->assertSame(0, SimulationOrder::count());
        Http::assertNothingSent();
    }

    public function test_missing_symbol_stays_unknown_and_the_next_batch_does_not_borrow_another_price(): void
    {
        $this->gateway->respond = fn ($c, $op, $filters) => $this->response($op, $filters, $op === Operation::Quotes ? ['DEMO', 'ALFA'] : null);
        app(BatchMarketInputCollector::class)->tick($this->connection->id);
        $rows = MarketInput::where('origin', 'broker_read')->get()->keyBy('symbol');
        $this->assertTrue($rows['ALFA']->payload['complete']);
        $this->assertFalse($rows['BRAVO']->payload['complete']);
        $this->assertNull($rows['BRAVO']->payload['quote']['bid']);
        $this->assertSame('broker_incomplete_pagination', $rows['BRAVO']->reason);
    }

    public function test_rate_limit_and_processing_budget_stop_reads_without_single_symbol_retries(): void
    {
        $this->gateway->respond = function () {
            app(BrokerBackoff::class)->record($this->connection->id, 90);
            throw new ReadFailure(ReadError::RateLimited);
        };
        $result = app(BatchMarketInputCollector::class)->tick($this->connection->id);
        $this->assertSame('rate_limited', $result['stopped']);
        $this->assertSame(0, $result['recorded']);
        $this->assertCount(1, $this->gateway->calls);
        $this->travel(91)->seconds();
        $this->gateway->respond = fn ($c, $op, $filters) => $this->response($op, $filters);
        $checks = 0;
        app(PaperMarketInputs::class)->collectMany($this->connection, $this->connection->user_id, ['DEMO', 'ALFA'], (string) Str::uuid(), function () use (&$checks) {
            if (++$checks >= 3) {
                throw new ReadFailure(ReadError::Stale);
            }
        });
        $this->assertCount(2, $this->gateway->calls); // One prior rate-limit read and one new batch read; no enrichment.
        $this->assertSame(0, MarketInput::where('origin', 'broker_read')->count());
    }

    public function test_delayed_enrichment_preserves_quote_source_time_and_execution_freshness_rejects_it(): void
    {
        $this->gateway->respond = function ($c, $op, $filters) {
            if ($op === Operation::Instruments) {
                $this->travel(25)->seconds();
            }

            return $this->response($op, $filters);
        };
        app(BatchMarketInputCollector::class)->tick($this->connection->id);
        $input = MarketInput::where('origin', 'broker_read')->where('symbol', 'DEMO')->sole();
        $this->assertSame('2026-10-19T14:00:00+00:00', $input->payload['quote']['bid_as_of']);
        $this->assertSame('quote_component_time_unknown_or_stale', app(PaperMarketInputs::class)->executionReason($input, CarbonImmutable::now(), 20));
    }

    public function test_deadline_after_partial_batch_still_delivers_only_recorded_symbols_to_callback(): void
    {
        $delivered = [];
        $rows = app(PaperMarketInputs::class)->collectMany($this->connection, $this->connection->user_id, ['ALFA', 'BRAVO'], (string) Str::uuid(), function () {
            if (MarketInput::where('origin', 'broker_read')->exists()) {
                throw new ReadFailure(ReadError::Stale);
            }
        }, function ($batch) use (&$delivered) {
            $delivered[] = array_keys($batch);
        });
        $this->assertSame(['ALFA'], array_keys($rows));
        $this->assertSame([['ALFA']], $delivered);
        $this->assertSame(1, MarketInput::where('origin', 'broker_read')->count());
    }

    public function test_default_off_and_off_session_cadence_have_no_reads(): void
    {
        config(['paper.batch_collection_enabled' => false]);
        $this->artisan('mcp:market-cycle', ['--once' => true])->assertSuccessful();
        config(['paper.batch_collection_enabled' => true]);
        $this->assertSame(0, app(MarketInputCollector::class)->tick(true, $this->connection->id));
        $job = new CollectPaperMarketInput($this->connection->id, AccountScope::key($this->connection), $this->connection->auth_generation, $this->executionId, 'DEMO', (string) Str::uuid(), now()->toISOString());
        $job->handle(app(PaperMarketInputs::class));
        $this->travelTo(CarbonImmutable::parse('2026-10-19T21:05:00Z'));
        $this->assertSame(0, app(BatchMarketInputCollector::class)->tick($this->connection->id, true)['selected']);
        $this->assertSame([], $this->gateway->calls);
        Http::assertNothingSent();
    }
}
