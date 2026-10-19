<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\MarketInput;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\PaperTrading\BookQuoteMatcher;
use App\Services\PaperTrading\ExecutionDataReadiness;
use App\Services\PaperTrading\PaperMarketInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperMarketGateway;
use Tests\TestCase;

class ExecutionDataReadinessTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed();
    }

    private function payload(): array
    {
        $now = now()->toIso8601String();

        return ['provider' => TrustedHttp::RESOURCE, 'complete' => true,
            'quote' => ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100', 'bid_as_of' => $now, 'ask_as_of' => $now, 'retrieved_at' => $now],
            'execution_reads' => ['price_book' => ['provider' => TrustedHttp::RESOURCE, 'complete' => true, 'source_as_of' => $now, 'retrieved_at' => $now,
                'semantic_fingerprint' => config('robinhood_mcp.operations.price_book.semantic_fingerprint'),
                'fields' => ['symbol' => 'DEMO', 'book_bid' => '99', 'book_ask' => '100', 'book_bid_quantity' => '0', 'book_ask_quantity' => '6', 'book_quantity_unit' => 'shares']]]];
    }

    public function test_book_shares_require_pinned_semantics_matching_prices_and_independent_fresh_times(): void
    {
        $matcher = app(BookQuoteMatcher::class);
        $payload = $this->payload();
        $match = $matcher->match($payload, CarbonImmutable::now(), 20);
        $this->assertSame('matched', $match['status']);
        $this->assertSame('0', $match['bid_size']); // Explicit observed zero is different from an unknown side.
        $this->assertSame('6', $match['ask_size']);
        foreach ([
            ['execution_reads.price_book.semantic_fingerprint', str_repeat('0', 64)],
            ['execution_reads.price_book.fields.book_quantity_unit', 'lots'],
            ['execution_reads.price_book.fields.book_ask', '100.01'],
            ['execution_reads.price_book.fields.symbol', 'OTHER'],
            ['execution_reads.price_book.fields.book_ask_quantity', 6.0],
            ['execution_reads.price_book.source_as_of', now()->subSeconds(21)->toIso8601String()],
            ['execution_reads.price_book.retrieved_at', now()->subSecond()->toIso8601String()],
            ['quote.bid_as_of', now()->subSeconds(21)->toIso8601String()],
            ['quote.retrieved_at', now()->addSecond()->toIso8601String()],
            ['execution_reads.price_book.complete', false],
        ] as [$path, $value]) {
            $changed = $payload;
            data_set($changed, $path, $value);
            $result = $matcher->match($changed, CarbonImmutable::now(), 20);
            $this->assertSame('unavailable', $result['status'], $path);
            $this->assertNull($result['bid_size'], $path);
            $this->assertNull($result['ask_size'], $path);
        }
    }

    public function test_collection_derives_size_only_from_reviewed_book_and_preserves_missing_classification(): void
    {
        config(['paper.market_inputs_enabled' => true, 'robinhood_mcp.execution_inputs_enabled' => true]);
        $this->connection->update(['capability_manifest' => ['get_equity_price_book' => ['semantic_fingerprint' => config('robinhood_mcp.operations.price_book.semantic_fingerprint')]]]);
        $gateway = new PaperMarketGateway;
        $gateway->respond = function ($connection, $operation) {
            $p = $this->payload();
            $fields = match ($operation) {
                Operation::Quotes => $p['quote'],
                Operation::Instruments => ['symbol' => 'DEMO', 'id' => 'synthetic-instrument'],
                Operation::PriceBook => $p['execution_reads']['price_book']['fields'],
                Operation::Tradability => ['symbol' => 'DEMO', 'account_id' => $connection->bound_account_id, 'account_scope' => 'request_binding', 'broker_tradeable' => true],
                default => throw new \RuntimeException('Unexpected read.'),
            };
            unset($fields['retrieved_at']);

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($operation, $fields,
                $operation === Operation::PriceBook ? now()->toDateTimeImmutable() : null)], true);
        };
        $this->app->instance(BrokerGateway::class, $gateway);
        $input = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertSame('matched', $input->payload['book_quote_match']['status']);
        $this->assertSame('0', $input->payload['quote']['bid_size']);
        $this->assertSame('6', $input->payload['quote']['ask_size']);
        $this->assertNull($input->payload['quote']['eligible']);
        $this->assertNull($input->payload['quote']['corporate_actions_clear']);
        $report = app(ExecutionDataReadiness::class)->inspect($input, CarbonImmutable::now(), 20);
        $this->assertSame('blocked', $report['status']);
        $this->assertSame('verified_prices_times_and_share_units', $report['checks']['quote_and_book']);
        $this->assertSame('observed_tradeable_source_time_unknown', $report['checks']['broker_tradability']);
        $this->assertSame('no_complete_corporate_action_coverage', $report['checks']['corporate_actions']);
        $this->assertSame('instrument_eligibility_unverified', app(PaperMarketInputs::class)->executionReason($input, CarbonImmutable::now(), 20));
    }

    public function test_synthetic_missing_and_late_inputs_never_report_real_execution_readiness(): void
    {
        $service = app(ExecutionDataReadiness::class);
        $report = $service->for($this->connection, CarbonImmutable::now());
        $this->assertSame(0, $report['ready_count']);
        $this->assertSame('synthetic_only', $report['symbols']['DEMO']['status']);
        $input = MarketInput::sole();
        $this->assertSame('not_collected', $service->inspect(null, CarbonImmutable::now(), 20)['checks']['market_observation']);
        $this->assertSame('synthetic_only', $service->inspect($input, CarbonImmutable::now(), 20)['status']);
        $copy = $input->replicate();
        $copy->origin = 'broker_read';
        $copy->recorded_at = now()->addSecond();
        $this->assertSame('source_or_local_availability_unverified', $service->inspect($copy, CarbonImmutable::now(), 20)['checks']['market_observation']);
    }

    public function test_readiness_command_and_dashboard_are_scoped_read_only_and_do_not_dispatch(): void
    {
        $this->artisan('mcp:paper-readiness')->assertFailed();
        $this->artisan('mcp:paper-readiness', ['--account' => $this->connection->id])->expectsOutputToContain('no_complete_corporate_action_coverage')->assertFailed();
        $this->actingAs(User::findOrFail($this->connection->user_id))->get('/mcp/dashboard/control')->assertOk()->assertSee('Execution data readiness')->assertSee('no_reviewed_stock_etf_classification');
        $this->assertDatabaseCount('mcp_paper_market_inputs', 1);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertDatabaseCount('mcp_paper_decision_runs', 0);
        Http::assertNothingSent();
    }
}
