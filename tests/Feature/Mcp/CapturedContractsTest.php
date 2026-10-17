<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\RobinhoodGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\McpFixture;
use Tests\TestCase;

class CapturedContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['robinhood_mcp.enabled' => true]);
        $this->travelTo(Carbon::parse('2026-10-16T21:47:00Z'));
    }

    private function fixture(Operation $operation): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-'.$operation->value.'-sanitized.json')), true, flags: JSON_THROW_ON_ERROR)['response'];
    }

    private function capturedFake(callable $callback): void
    {
        $catalog = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-catalog-2026-10-16.json')), true, flags: JSON_THROW_ON_ERROR)['tools'];
        McpFixture::rpcFake(array_values($catalog), function ($params) use ($callback) {
            return ['content' => [], 'structuredContent' => $callback($params)];
        });
    }

    public function test_captured_sanitized_contracts_normalize_with_explicit_scope_and_coverage(): void
    {
        $connection = McpFixture::connection();
        $catalog = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-catalog-2026-10-16.json')), true)['tools'];
        $connection->update(['capability_manifest' => $catalog]);
        foreach (['accounts' => 3, 'portfolio' => 1, 'positions' => 2, 'orders' => 3, 'instruments' => 1, 'quotes' => 1] as $name => $count) {
            $operation = Operation::from($name);
            $body = $this->fixture($operation);
            $this->capturedFake(function ($params) use ($operation, $body) {
                if (in_array($operation, [Operation::Portfolio, Operation::Positions, Operation::Orders], true)) {
                    $this->assertSame('synthetic-agentic', $params['arguments']['account_number']);
                }
                if ($operation === Operation::Quotes) {
                    $this->assertSame(['TEST'], $params['arguments']['symbols']);
                }
                if ($operation === Operation::Instruments) {
                    $this->assertSame('TEST', $params['arguments']['query']);
                }

                return $body;
            });
            $result = app(RobinhoodGateway::class)->read($connection, $operation, in_array($operation, [Operation::Instruments, Operation::Quotes], true) ? ['symbol' => 'TEST'] : []);
            $this->assertTrue($result->complete, $name);
            $this->assertCount($count, $result->records, $name);
            if ($operation === Operation::Accounts) {
                $this->assertSame(['personal', 'personal', 'agentic'], array_map(fn ($row) => $row->fields['account_kind'], $result->records));
            }
            if ($operation === Operation::Positions) {
                $this->assertSame('equities', $result->coverage);
                $this->assertArrayNotHasKey('id', $result->records[0]->fields);
                $this->assertArrayNotHasKey('instrument_id', $result->records[0]->fields);
                $this->assertSame('request_binding', $result->records[0]->fields['account_scope']);
                $this->assertNull($result->records[0]->sourceAsOf);
            }
            if ($operation === Operation::Quotes) {
                $this->assertSame('100.16', (string) $result->records[0]->fields['last']);
                $this->assertSame('extended', $result->records[0]->fields['last_session']);
                $this->assertNotNull($result->records[0]->sourceAsOf);
            }
        }
    }

    public function test_real_contract_null_rows_are_unknown_and_missing_cursor_is_a_terminal_page(): void
    {
        $connection = McpFixture::connection();
        $catalog = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-catalog-2026-10-16.json')), true)['tools'];
        $connection->update(['capability_manifest' => $catalog]);
        $body = $this->fixture(Operation::Positions);
        $body['data']['positions'] = null;
        $this->capturedFake(fn () => $body);
        $result = app(RobinhoodGateway::class)->read($connection, Operation::Positions);
        $this->assertFalse($result->complete);
        $this->assertSame(ReadError::Incomplete, $result->incompleteReason);
        $body['data']['positions'] = [];
        $this->capturedFake(fn () => $body);
        $this->assertTrue(app(RobinhoodGateway::class)->read($connection, Operation::Positions)->complete);
    }

    public function test_real_contract_positions_continue_until_terminal_page_without_duplicate_symbols(): void
    {
        $connection = McpFixture::connection();
        $connection->update(['capability_manifest' => json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-catalog-2026-10-16.json')), true)['tools']]);
        $body = $this->fixture(Operation::Positions);
        $first = $body;
        $first['data']['positions'] = [$body['data']['positions'][0]];
        $first['data']['next'] = 'synthetic-cursor';
        $second = $body;
        $second['data']['positions'] = [$body['data']['positions'][1]];
        $this->capturedFake(fn ($params) => isset($params['arguments']['cursor']) ? $second : $first);
        $result = app(RobinhoodGateway::class)->read($connection, Operation::Positions);
        $this->assertTrue($result->complete);
        $this->assertSame(2, $result->pages);
        $this->assertCount(2, $result->records);
    }

    public function test_scoped_browser_response_masks_account_numbers(): void
    {
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email]]);
        $connection = McpFixture::connection($user);
        $connection->update(['capability_manifest' => json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-catalog-2026-10-16.json')), true)['tools']]);
        $this->capturedFake(fn () => $this->fixture(Operation::Portfolio));
        $response = $this->actingAs($user)->getJson('/mcp/robinhood/reads/portfolio')->assertOk();
        $response->assertJsonPath('records.0.data.account_id', '…ntic');
        $this->assertStringNotContainsString('synthetic-agentic', $response->getContent());
    }
}
