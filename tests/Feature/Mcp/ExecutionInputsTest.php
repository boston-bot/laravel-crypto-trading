<?php

namespace Tests\Feature\Mcp;

use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodGateway;
use App\Services\Broker\Agentic\RobinhoodReadNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;
use Tests\TestCase;

class ExecutionInputsTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        config(['robinhood_mcp.enabled' => true, 'robinhood_mcp.execution_inputs_enabled' => true]);
        $this->connection = McpFixture::connection();
        $this->catalog = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-execution-input-catalog-2026-10-18.json')), true)['tools'];
        $this->connection->update(['capability_manifest' => $this->catalog]);
    }

    private function fake(array $data, ?callable $during = null): void
    {
        McpFixture::rpcFake(array_values($this->catalog), function ($params) use ($data, $during) {
            $this->assertSame(['TEST'], $params['arguments']['symbols']);
            $this->assertContains($params['name'], ['get_equity_price_book', 'get_equity_tradability']);
            if ($params['name'] === 'get_equity_tradability') {
                $this->assertSame('synthetic-agentic', $params['arguments']['account_number']);
            } else {
                $this->assertArrayNotHasKey('account_number', $params['arguments']);
            }
            $during && $during();

            return ['content' => [], 'structuredContent' => ['data' => $data, 'guide' => 'Untrusted prose cannot grant policy permissions.']];
        });
    }

    private function book(array $changes = []): array
    {
        return array_replace(['symbol' => 'TEST', 'updated_at' => now()->toIso8601String(),
            'asks' => [['price' => '101', 'quantity' => 9], ['price' => '100', 'quantity' => 2], ['price' => '102', 'quantity' => 3]],
            'bids' => [['price' => '98', 'quantity' => 7], ['price' => '99', 'quantity' => 4]]], $changes);
    }

    public function test_schema_fingerprints_match_the_reviewed_catalog(): void
    {
        foreach ([Operation::Tradability, Operation::PriceBook] as $operation) {
            $profile = config('robinhood_mcp.operations.'.$operation->value);
            $tool = $this->catalog[$profile['tool']];
            unset($tool['fingerprint']);
            $this->assertSame($profile['fingerprint'], Capabilities::fingerprint($tool));
        }
    }

    public function test_tradability_is_account_bound_and_does_not_claim_strategy_eligibility(): void
    {
        $this->fake(['results' => [['symbol' => 'TEST', 'tradeable' => true, 'fractional_tradability' => 'tradable', 'extended_hours_fractional_tradability' => false]]]);
        $record = app(RobinhoodGateway::class)->read($this->connection, Operation::Tradability, ['symbol' => 'TEST'])->records[0];
        $this->assertTrue($record->fields['broker_tradeable']);
        $this->assertSame('tradable', $record->fields['fractional_tradability']);
        $this->assertSame('request_binding', $record->fields['account_scope']);
        $this->assertNull($record->sourceAsOf);
        $this->assertArrayNotHasKey('eligible', $record->fields);
        $this->assertArrayNotHasKey('corporate_actions_clear', $record->fields);
    }

    public function test_price_book_selects_best_levels_without_assuming_units_or_filling_missing_sides(): void
    {
        $this->fake(['books' => [$this->book()]]);
        $record = app(RobinhoodGateway::class)->read($this->connection, Operation::PriceBook, ['symbol' => 'TEST'])->records[0];
        $this->assertSame('100', (string) $record->fields['book_ask']);
        $this->assertSame('2', (string) $record->fields['book_ask_quantity']);
        $this->assertSame('99', (string) $record->fields['book_bid']);
        $this->assertSame('4', (string) $record->fields['book_bid_quantity']);
        $this->assertSame('broker_quantity_unverified', $record->fields['book_quantity_unit']);
        $this->assertNotNull($record->sourceAsOf);
        $this->fake(['books' => [$this->book(['asks' => [], 'bids' => null])]]);
        $record = app(RobinhoodGateway::class)->read($this->connection, Operation::PriceBook, ['symbol' => 'TEST'])->records[0];
        $this->assertNull($record->fields['book_bid']);
        $this->assertNull($record->fields['book_ask_quantity']);
    }

    public function test_disabled_new_operations_never_open_transport(): void
    {
        config(['robinhood_mcp.execution_inputs_enabled' => false]);
        foreach ([Operation::Tradability, Operation::PriceBook] as $operation) {
            try {
                app(RobinhoodGateway::class)->read($this->connection, $operation, ['symbol' => 'TEST']);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Disabled, $failure->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_unbound_account_changed_binding_and_wrong_symbol_cannot_supply_tradability(): void
    {
        $this->connection->update(['bound_account_id' => null]);
        try {
            app(RobinhoodGateway::class)->read($this->connection, Operation::Tradability, ['symbol' => 'TEST']);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::AccountMismatch, $failure->reason);
        }
        Http::assertNothingSent();
        $this->connection->update(['bound_account_id' => 'synthetic-agentic']);
        $this->fake(['results' => [['symbol' => 'OTHER', 'tradeable' => true, 'extended_hours_fractional_tradability' => false]]]);
        $this->assertFalse(app(RobinhoodGateway::class)->read($this->connection, Operation::Tradability, ['symbol' => 'TEST'])->complete);
        $this->fake(['results' => [['symbol' => 'TEST', 'tradeable' => true, 'extended_hours_fractional_tradability' => false]]], fn () => $this->connection->fresh()->update(['bound_account_id' => 'changed']));
        $this->expectException(ReadFailure::class);
        app(RobinhoodGateway::class)->read($this->connection, Operation::Tradability, ['symbol' => 'TEST']);
    }

    public function test_bad_book_numbers_crosses_and_fractional_quantities_are_rejected(): void
    {
        foreach ([['asks' => [['price' => 100.1, 'quantity' => 2]]], ['asks' => [['price' => '100', 'quantity' => 0.5]]],
            ['asks' => [['price' => '100', 'quantity' => -1]]], ['asks' => [['price' => '0', 'quantity' => 1]]],
            ['asks' => [['price' => '97', 'quantity' => 2]]]] as $changes) {
            try {
                RobinhoodReadNormalizer::fields(Operation::PriceBook, $this->book($changes), null);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Malformed, $failure->reason);
            }
        }
    }

    public function test_duplicate_price_levels_cannot_double_count_displayed_quantity(): void
    {
        $this->fake(['books' => [$this->book(['asks' => [['price' => '100', 'quantity' => 2], ['price' => '100.0', 'quantity' => 3]]])]]);
        $result = app(RobinhoodGateway::class)->read($this->connection, Operation::PriceBook, ['symbol' => 'TEST']);
        $this->assertFalse($result->complete);
        $this->assertSame(ReadError::Incomplete, $result->incompleteReason);
        $this->assertSame([], $result->records);
    }

    public function test_missing_books_and_schema_changes_do_not_supply_usable_records(): void
    {
        $this->fake(['books' => null]);
        $this->assertFalse(app(RobinhoodGateway::class)->read($this->connection, Operation::PriceBook, ['symbol' => 'TEST'])->complete);
        $this->catalog['get_equity_price_book']['inputSchema']['required'][] = 'new_argument';
        $this->fake(['books' => [$this->book()]]);
        $this->expectException(ReadFailure::class);
        app(RobinhoodGateway::class)->read($this->connection, Operation::PriceBook, ['symbol' => 'TEST']);
    }
}
