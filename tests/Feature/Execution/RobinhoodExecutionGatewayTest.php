<?php

namespace Tests\Feature\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Broker\Agentic\SessionFactory;
use App\Services\Execution\ExecutionFailure;
use App\Services\Execution\RobinhoodExecutionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;
use Tests\TestCase;

/** Synthetic JSON-RPC server with the real reviewed write-tool schemas. No broker contact. */
class RobinhoodExecutionGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '3f1c2d4e-5a6b-5c7d-8e9f-0a1b2c3d4e5f';

    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['robinhood_mcp.enabled' => true]);
    }

    private function tools(?callable $change = null): array
    {
        $tools = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-write-tool-schemas.json')), true)['tools'];
        if ($change) {
            $tools = $change($tools);
        }

        return array_values($tools);
    }

    private function intent(McpConnection $c, array $patch = []): OrderIntent
    {
        return (new OrderIntent)->forceFill(['mcp_connection_id' => $c->id, 'idempotency_key' => self::KEY, 'payload' => $patch + ['symbol' => 'DEMO', 'side' => 'BUY',
            'type' => 'market', 'quantity' => '1', 'price_bound' => '102.051', 'session' => 'regular_hours', 'time_in_force' => 'day']]);
    }

    private function fake(mixed $placeData, ?callable $tools = null): void
    {
        McpFixture::rpcFake($this->tools($tools), function ($params) use ($placeData) {
            $this->calls[] = $params;
            $data = $params['name'] === 'review_equity_order'
                ? ['symbol' => 'DEMO', 'side' => 'buy', 'type' => 'market', 'order_checks' => [], 'quote_data' => null, 'customer_approval_required' => true]
                : $placeData;

            return is_array($data) ? ['content' => [], 'structuredContent' => ['data' => $data, 'guide' => 'synthetic']] : $data;
        });
    }

    private function order(array $patch = []): array
    {
        return ['order' => $patch + ['id' => 'rh-order-1', 'ref_id' => strtoupper(self::KEY), 'symbol' => 'DEMO', 'side' => 'buy', 'quantity' => '1.00000000'], 'approval' => null];
    }

    public function test_pinned_fingerprints_match_the_captured_schemas(): void
    {
        foreach ($this->tools() as $tool) {
            $this->assertSame(RobinhoodOrderContract::SCHEMA_FINGERPRINT[$tool['name']], Capabilities::fingerprint($tool));
        }
    }

    public function test_writes_disabled_opens_no_session_and_sends_nothing(): void
    {
        $c = McpFixture::connection();
        $this->fake($this->order());
        try {
            app(RobinhoodExecutionGateway::class)->within($c, fn () => $this->fail('Work must not run.'));
            $this->fail('Disabled writes must refuse.');
        } catch (ExecutionFailure $failure) {
            $this->assertSame(['session_disabled', false], [$failure->reason, $failure->maybeSent]);
        }
        Http::assertNothingSent();
    }

    public function test_review_then_place_send_only_intent_terms_and_the_stable_ref_id(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        $gateway = app(RobinhoodExecutionGateway::class);
        $this->fake($this->order());
        $intent = $this->intent($c);
        [$review, $placed] = $gateway->within($c, fn () => [$gateway->review($c, $intent), $gateway->place($c, $intent)]);
        $terms = ['account_number' => 'synthetic-agentic', 'symbol' => 'DEMO', 'side' => 'buy', 'type' => 'market', 'quantity' => '1',
            'time_in_force' => 'gfd', 'market_hours' => 'regular_hours'];
        $this->assertSame([['name' => 'review_equity_order', 'arguments' => $terms], ['name' => 'place_equity_order', 'arguments' => $terms + ['ref_id' => self::KEY]]],
            array_map(fn ($call) => ['name' => $call['name'], 'arguments' => $call['arguments']], $this->calls));
        $this->assertSame(['order_checks' => [], 'customer_approval_required' => true], $review);
        $this->assertSame(['kind' => 'order', 'order_id' => 'rh-order-1'], $placed);
    }

    public function test_limit_orders_carry_the_bound_and_fractional_limits_are_refused_before_sending(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        $gateway = app(RobinhoodExecutionGateway::class);
        $this->fake($this->order());
        $gateway->within($c, fn () => $gateway->review($c, $this->intent($c, ['type' => 'limit', 'price_bound' => '101.65'])));
        $this->assertSame('101.65', $this->calls[0]['arguments']['limit_price']);
        foreach ([['type' => 'limit', 'quantity' => '0.5'], ['time_in_force' => 'gtc'], ['session' => 'extended_hours'], ['side' => 'SHORT']] as $patch) {
            try {
                $gateway->within($c, fn () => $gateway->place($c, $this->intent($c, $patch)));
                $this->fail('Unsupported terms must refuse.');
            } catch (ExecutionFailure $failure) {
                $this->assertSame(['intent_terms_unsupported', false], [$failure->reason, $failure->maybeSent]);
            }
        }
        $this->assertCount(1, $this->calls);
    }

    public function test_approval_response_is_recorded_as_an_approval_not_an_order(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        $gateway = app(RobinhoodExecutionGateway::class);
        $this->fake(['order' => null, 'approval' => ['approval_id' => 'approval-9', 'account_number' => 'synthetic-agentic', 'asset_class' => 'ASSET_CLASS_EQUITY',
            'state' => 'APPROVAL_STATE_PENDING', 'is_edited' => false, 'expires_at' => '2026-10-19T14:30:00Z']]);
        $placed = $gateway->within($c, fn () => $gateway->place($c, $this->intent($c)));
        $this->assertSame(['kind' => 'approval', 'approval_id' => 'approval-9', 'state' => 'APPROVAL_STATE_PENDING', 'is_edited' => false,
            'expires_at' => '2026-10-19T14:30:00Z'], $placed);
    }

    public function test_changed_write_schema_refuses_before_any_tool_call(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        $gateway = app(RobinhoodExecutionGateway::class);
        $this->fake($this->order(), function ($tools) {
            $tools['place_equity_order']['inputSchema']['properties']['auto_confirm'] = ['type' => 'boolean'];

            return $tools;
        });
        try {
            $gateway->within($c, fn () => $this->fail('Work must not run.'));
            $this->fail('Changed schema must refuse.');
        } catch (ExecutionFailure $failure) {
            $this->assertSame(['write_schema_changed', false], [$failure->reason, $failure->maybeSent]);
        }
        $this->assertSame([], $this->calls);
    }

    public function test_place_failures_after_sending_are_unknown_and_never_retried(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        $gateway = app(RobinhoodExecutionGateway::class);
        foreach (['place_outcome_unknown' => Http::response('', 503), 'place_response_unrecognized' => ['order' => null, 'approval' => null],
            'place_order_differs' => $this->order(['quantity' => '2'])] as $reason => $response) {
            $this->calls = [];
            $this->fake($response);
            try {
                $gateway->within($c, fn () => $gateway->place($c, $this->intent($c)));
                $this->fail('Expected an unknown outcome.');
            } catch (ExecutionFailure $failure) {
                $this->assertSame([$reason, true], [$failure->reason, $failure->maybeSent]);
            }
            $this->assertCount(1, $this->calls, 'One place request; no retry.');
        }
        $this->fake($this->order(['ref_id' => 'another-agents-ref']));
        try {
            $gateway->within($c, fn () => $gateway->place($c, $this->intent($c)));
            $this->fail('A different ref_id must not bind.');
        } catch (ExecutionFailure $failure) {
            $this->assertSame(['place_order_differs', true], [$failure->reason, $failure->maybeSent]);
        }
    }

    public function test_read_sessions_cannot_reach_order_tools_even_with_writes_enabled(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        $this->fake($this->order());
        $session = app(SessionFactory::class)->open($c);
        try {
            $session->call('place_equity_order', ['account_number' => 'synthetic-agentic', 'symbol' => 'DEMO', 'side' => 'buy', 'type' => 'market']);
            $this->fail('A read session must not call an order tool.');
        } catch (\Throwable) {
            $this->assertSame([], $this->calls);
        } finally {
            $session->close();
        }
        Http::assertNotSent(fn ($request) => ($request['method'] ?? null) === 'tools/call');
    }

    public function test_tools_cannot_be_called_outside_the_verified_session(): void
    {
        config(['mcp.brokerage_writes_enabled' => true]);
        $c = McpFixture::connection();
        try {
            app(RobinhoodExecutionGateway::class)->place($c, $this->intent($c));
            $this->fail('No open session.');
        } catch (ExecutionFailure $failure) {
            $this->assertSame(['write_session_not_open', false], [$failure->reason, $failure->maybeSent]);
        }
        Http::assertNothingSent();
    }
}
