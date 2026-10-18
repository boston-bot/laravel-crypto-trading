<?php

namespace Tests\Feature\PaperTrading;

use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\PaperTrading\PaperMarketInputs;
use App\Services\PaperTrading\StoredDecisionInputs;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\PaperMarketGateway;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class PaperMarketInputsTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private PaperMarketGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed();
        config(['paper.market_inputs_enabled' => true]);
        $this->gateway = new PaperMarketGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
    }

    public function test_broker_reads_preserve_component_times_and_do_not_invent_eligibility_or_liquidity(): void
    {
        $request = (string) Str::uuid();
        $record = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', $request);
        $this->assertSame('broker_read', $record->origin);
        $this->assertTrue($record->payload['complete']);
        $this->assertSame('2026-10-19T13:59:50+00:00', $record->source_at->toIso8601String());
        $this->assertSame('2026-10-19T13:59:58+00:00', $record->payload['quote']['ask_as_of']);
        $this->assertNull($record->payload['quote']['eligible']);
        $this->assertNull($record->payload['quote']['bid_size']);
        $this->assertNull($record->payload['quote']['asset_type']);
        $this->assertSame('instrument_eligibility_unverified', app(PaperMarketInputs::class)->executionReason($record, CarbonImmutable::now(), 60));
        $inputs = app(StoredDecisionInputs::class)->load($this->connection, CarbonImmutable::now());
        $this->assertSame('unknown', $inputs['subjects']['DEMO']['eligibility']);
        $this->assertSame('broker_read', $inputs['subjects']['DEMO']['origin']);
        $this->assertSame($record->id, app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', $request)->id);
        $this->assertCount(2, $this->gateway->calls);
        $this->assertSame([Operation::Quotes, Operation::Instruments], array_column($this->gateway->calls, 0));
        Http::assertNothingSent();
    }

    public function test_unknown_component_times_and_partial_reads_are_not_zero_or_complete(): void
    {
        $this->gateway->respond = fn ($connection, $operation, $filters) => new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [], false);
        $record = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertFalse($record->payload['complete']);
        $this->assertNull($record->source_at);
        $this->assertNull($record->payload['quote']['bid']);
        $this->assertSame('market_input_incomplete', app(PaperMarketInputs::class)->executionReason($record, CarbonImmutable::now(), 60));
        $this->assertCount(1, $this->gateway->calls);
    }

    public function test_extra_provider_metadata_cannot_grant_policy_permissions(): void
    {
        $this->gateway->respond = function ($connection, $operation, $filters) {
            $fields = ['symbol' => 'DEMO', 'id' => 'synthetic', 'bid' => '99', 'ask' => '100', 'eligible' => true,
                'asset_type' => 'stock', 'bid_size' => '1000', 'ask_size' => '1000', 'corporate_actions_clear' => true,
                'bid_as_of' => now()->toIso8601String(), 'ask_as_of' => now()->toIso8601String()];

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [new BrokerRecord($operation, $fields, null)], true);
        };
        $record = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertNull($record->payload['quote']['eligible']);
        $this->assertNull($record->payload['quote']['bid_size']);
    }

    public function test_collection_disabled_and_changed_binding_cannot_dispatch_or_publish_under_another_scope(): void
    {
        config(['paper.market_inputs_enabled' => false]);
        try {
            app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
            $this->fail('Disabled collection dispatched.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame([], $this->gateway->calls);
        config(['paper.market_inputs_enabled' => true]);
        $stale = $this->connection->fresh();
        $this->connection->update(['bound_account_id' => 'changed-fixture-binding']);
        $this->expectException(HttpException::class);
        app(PaperMarketInputs::class)->collect($stale, $stale->user_id, 'DEMO', (string) Str::uuid());
    }

    public function test_additional_execution_reads_preserve_provenance_without_granting_eligibility_or_size(): void
    {
        config(['robinhood_mcp.execution_inputs_enabled' => true]);
        $this->gateway->respond = function ($connection, $operation, $filters) {
            $fields = match ($operation) {
                Operation::Quotes => ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100', 'bid_as_of' => now()->toIso8601String(), 'ask_as_of' => now()->toIso8601String()],
                Operation::Instruments => ['symbol' => 'DEMO', 'id' => 'synthetic-instrument'],
                Operation::Tradability => ['symbol' => 'DEMO', 'account_id' => $connection->bound_account_id, 'account_scope' => 'request_binding',
                    'broker_tradeable' => true, 'fractional_tradability' => 'tradable'],
                Operation::PriceBook => ['symbol' => 'DEMO', 'book_bid' => '99', 'book_ask' => '100', 'book_bid_quantity' => '5',
                    'book_ask_quantity' => '6', 'book_quantity_unit' => 'broker_quantity_unverified'],
                default => throw new \RuntimeException('Unexpected operation.'),
            };

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [BrokerRecord::from($operation, $fields,
                $operation === Operation::PriceBook ? now()->subSecond()->toDateTimeImmutable() : null)], true);
        };
        $record = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $reads = $record->payload['execution_reads'];
        $this->assertCount(4, $this->gateway->calls);
        $this->assertTrue($reads['tradability']['complete']);
        $this->assertTrue($reads['tradability']['fields']['broker_tradeable']);
        $this->assertArrayNotHasKey('account_id', $reads['tradability']['fields']);
        $this->assertNull($reads['tradability']['source_as_of']);
        $this->assertNotNull($reads['price_book']['source_as_of']);
        $this->assertSame('5', $reads['price_book']['fields']['book_bid_quantity']);
        $this->assertNull($record->payload['quote']['eligible']);
        $this->assertNull($record->payload['quote']['fractional']);
        $this->assertNull($record->payload['quote']['bid_size']);
        $this->assertNull($record->payload['quote']['corporate_actions_clear']);
        $this->assertSame('instrument_eligibility_unverified', app(PaperMarketInputs::class)->executionReason($record, CarbonImmutable::now(), 20));
        $this->assertStringNotContainsString('broker_tradeable', DB::table('mcp_paper_market_inputs')->where('id', $record->id)->value('payload'));
    }

    public function test_supplemental_read_failures_are_independent_unknown_evidence_and_not_a_successful_fallback(): void
    {
        config(['robinhood_mcp.execution_inputs_enabled' => true]);
        $this->gateway->respond = function ($connection, $operation) {
            if ($operation === Operation::PriceBook) {
                throw new ReadFailure(ReadError::RateLimited);
            }

            return new ReadResult(TrustedHttp::RESOURCE, now()->toDateTimeImmutable(), [], false);
        };
        $record = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertFalse($record->payload['complete']);
        $this->assertFalse($record->payload['execution_reads']['tradability']['complete']);
        $this->assertFalse($record->payload['execution_reads']['price_book']['complete']);
        $this->assertSame('rate_limited', $record->payload['execution_reads']['price_book']['error']);
        $this->assertNull($record->payload['execution_reads']['price_book']['fields']);
        $this->assertSame('market_input_incomplete', app(PaperMarketInputs::class)->executionReason($record, CarbonImmutable::now(), 20));
    }

    public function test_late_arrival_within_the_same_second_cannot_enter_an_earlier_decision(): void
    {
        $cutoff = CarbonImmutable::parse('2026-10-19T14:00:00.100000Z');
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00.900000Z'));
        $new = app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id, F::quote(['eligible' => false]), (string) Str::uuid());
        $old = app(PaperMarketInputs::class)->latest($this->connection, 'DEMO', $cutoff);
        $this->assertNotSame($new->id, $old->id);
        $this->assertTrue($old->payload['quote']['eligible']);
        $this->assertFalse(app(PaperMarketInputs::class)->latest($this->connection, 'DEMO', CarbonImmutable::now())->payload['quote']['eligible']);
    }

    public function test_component_source_time_must_precede_its_own_quote_retrieval_and_prices_are_validated_before_sizing(): void
    {
        $this->gateway->respond = function ($connection, $operation, $filters) {
            if ($operation === Operation::Quotes) {
                $fields = ['symbol' => 'DEMO', 'bid' => '99', 'ask' => '100', 'bid_as_of' => '2026-10-19T14:00:01Z', 'ask_as_of' => '2026-10-19T14:00:01Z'];

                return new ReadResult(TrustedHttp::RESOURCE, new \DateTimeImmutable('2026-10-19T14:00:00Z'), [BrokerRecord::from($operation, $fields, null)], true);
            }

            return new ReadResult(TrustedHttp::RESOURCE, new \DateTimeImmutable('2026-10-19T14:00:02Z'),
                [BrokerRecord::from($operation, ['symbol' => 'DEMO', 'id' => 'synthetic'], null)], true);
        };
        $this->travel(3)->seconds();
        $record = app(PaperMarketInputs::class)->collect($this->connection, $this->connection->user_id, 'DEMO', (string) Str::uuid());
        $this->assertSame('quote_component_time_unknown_or_stale', app(PaperMarketInputs::class)->executionReason($record, CarbonImmutable::now(), 60));
        $record = app(PaperMarketInputs::class)->fixture($this->connection, $this->connection->user_id, F::quote(['bid' => '101', 'ask' => '100']), (string) Str::uuid());
        $this->assertSame('quote_prices_unknown_or_invalid', app(PaperMarketInputs::class)->executionReason($record, CarbonImmutable::now(), 60));
    }
}
