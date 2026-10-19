<?php

namespace Tests\Feature\Mcp;

use App\Models\McpConnection;
use App\Models\McpOauthCredential;
use App\Services\Broker\Agentic\BrokerBackoff;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\QuoteBatch;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodGateway;
use App\Services\Broker\Agentic\TrustedHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;
use Tests\TestCase;

class SessionReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['robinhood_mcp.enabled' => true]);
    }

    /** Like McpFixture::rpcFake, but the server issues a session ID, so the SDK closes sessions with DELETE. */
    private function fake(array $tools, callable $call): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([TrustedHttp::RESOURCE => function ($request) use ($tools, $call) {
            $rpc = json_decode($request->body(), true);
            if ($request->method() === 'DELETE' || ($rpc['method'] ?? '') === 'notifications/initialized') {
                return Http::response('', 202);
            }
            $result = match ($rpc['method']) {
                'initialize' => ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => (object) []], 'serverInfo' => ['name' => 'synthetic-server', 'version' => '1.0']],
                'tools/list' => ['tools' => $tools],
                'tools/call' => $call($rpc['params'], $request),
            };
            if (! is_array($result)) {
                return $result;
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => $result], 200, ['Mcp-Session-Id' => 'synthetic-session']);
        }]);
    }

    /** @return array<string, int> MCP traffic by JSON-RPC method, with DELETE counted as session close. */
    private function traffic(): array
    {
        $counts = [];
        foreach (Http::recorded() as [$request]) {
            if ($request->url() !== TrustedHttp::RESOURCE) {
                continue;
            }
            $method = $request->method() === 'DELETE' ? 'DELETE' : (json_decode($request->body(), true)['method'] ?? '?');
            $counts[$method] = ($counts[$method] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    private function quoteTool(McpConnection $connection, int $max = 3): array
    {
        $tool = ['name' => 'synthetic_read_quotes',
            'inputSchema' => ['type' => 'object', 'properties' => (object) ['symbols' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['symbols'], 'additionalProperties' => false],
            'outputSchema' => ['type' => 'object', 'properties' => ['records' => ['type' => 'array']], 'required' => ['records']]];
        config(['robinhood_mcp.operations.quotes' => ['tool' => $tool['name'], 'reviewed_read_only' => true, 'fingerprint' => Capabilities::fingerprint($tool),
            'filters' => ['symbol' => 'symbols'], 'array_filters' => ['symbol'], 'required_filter' => 'symbol', 'records' => 'records', 'source_as_of' => 'as_of',
            'fields' => ['symbol' => 'symbol', 'bid' => 'bid', 'ask' => 'ask'], 'pagination' => ['kind' => 'single'], 'exact_symbol' => true, 'max_batch_symbols' => $max]]);
        $manifest = $connection->capability_manifest ?? [];
        $manifest[$tool['name']] = $tool + ['fingerprint' => Capabilities::fingerprint($tool)];
        $connection->update(['capability_manifest' => $manifest]);

        return $tool;
    }

    private function quotes(array $symbols): array
    {
        return McpFixture::result(array_map(fn ($symbol) => ['symbol' => $symbol, 'bid' => '10.00', 'ask' => '10.05'], $symbols));
    }

    public function test_reads_inside_one_scope_share_a_session_and_catalog_check_while_unscoped_reads_do_not(): void
    {
        $connection = McpFixture::connection();
        $tool = McpFixture::configure($connection, Operation::Positions);
        $this->fake([$tool], fn () => McpFixture::result([McpFixture::position()]));
        $gateway = app(RobinhoodGateway::class);
        $gateway->within($connection, function () use ($gateway, $connection): void {
            foreach (range(1, 3) as $i) {
                $this->assertTrue($gateway->read($connection, Operation::Positions)->complete);
            }
        });
        $this->assertSame(['DELETE' => 1, 'initialize' => 1, 'notifications/initialized' => 1, 'tools/call' => 3, 'tools/list' => 1], $this->traffic());

        $this->fake([$tool], fn () => McpFixture::result([McpFixture::position()]));
        $gateway->read($connection, Operation::Positions);
        $gateway->read($connection, Operation::Positions);
        $this->assertSame(['DELETE' => 2, 'initialize' => 2, 'notifications/initialized' => 2, 'tools/call' => 2, 'tools/list' => 2], $this->traffic());
    }

    public function test_unchanged_catalog_is_not_rewritten_but_a_changed_one_is(): void
    {
        $connection = McpFixture::connection();
        $tool = McpFixture::configure($connection, Operation::Positions);
        $this->fake([$tool], fn () => McpFixture::result([McpFixture::position()]));
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'capability_fingerprint')) {
                $writes++;
            }
        });
        $gateway = app(RobinhoodGateway::class);
        $gateway->read($connection, Operation::Positions);
        $this->assertSame(1, $writes);
        $fingerprint = $connection->fresh()->capability_fingerprint;
        $gateway->read($connection, Operation::Positions);
        $gateway->read($connection, Operation::Positions);
        $this->assertSame(1, $writes);
        $this->assertNotNull($connection->fresh()->capabilities_checked_at);

        $extra = ['name' => 'synthetic_unreviewed', 'inputSchema' => ['type' => 'object', 'properties' => (object) []]];
        $this->fake([$tool, $extra], fn () => McpFixture::result([McpFixture::position()]));
        $gateway->read($connection, Operation::Positions);
        $this->assertSame(2, $writes);
        $this->assertNotSame($fingerprint, $connection->fresh()->capability_fingerprint);
        $this->assertArrayHasKey('synthetic_unreviewed', $connection->fresh()->capability_manifest);
    }

    public function test_scoped_session_reopens_after_its_age_limit_an_authorization_change_or_a_transport_failure(): void
    {
        $connection = McpFixture::connection();
        $tool = McpFixture::configure($connection, Operation::Positions);
        $fail = false;
        $this->fake([$tool], function ($params, $request) use (&$fail) {
            if ($fail) {
                $fail = false;

                return Http::response('', 503);
            }

            return McpFixture::result([McpFixture::position()]);
        });
        $gateway = app(RobinhoodGateway::class);
        $gateway->within($connection, function () use ($gateway, $connection, &$fail): void {
            $gateway->read($connection, Operation::Positions);
            $this->travel(config('robinhood_mcp.session_max_seconds') + 1)->seconds();
            $gateway->read($connection, Operation::Positions);
            $this->assertSame(2, $this->traffic()['initialize']);

            $connection->increment('auth_generation');
            $this->assertTrue($gateway->read($connection, Operation::Positions)->complete);
            $this->assertSame(3, $this->traffic()['initialize']);

            $fail = true;
            try {
                $gateway->read($connection, Operation::Positions);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Transport, $failure->reason);
            }
            $this->assertTrue($gateway->read($connection, Operation::Positions)->complete);
            $this->assertSame(4, $this->traffic()['initialize']);
        });
        $this->assertSame(4, $this->traffic()['DELETE']);
    }

    public function test_scoped_session_refreshes_a_token_that_would_expire_during_it(): void
    {
        $connection = McpFixture::connection();
        $tool = McpFixture::configure($connection, Operation::Positions);
        McpOauthCredential::where('mcp_connection_id', $connection->id)->update(['expires_at' => now()->addSeconds(100)]);
        $this->fake([$tool], fn () => McpFixture::result([McpFixture::position()]));
        Http::fake(['https://api.robinhood.com/oauth2/token/' => Http::response(['access_token' => 'synthetic-longer-access', 'refresh_token' => 'synthetic-next-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'internal'])]);
        $gateway = app(RobinhoodGateway::class);
        // A single-read session needs only the normal margin, so this token is still used as is.
        $gateway->read($connection, Operation::Positions);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'oauth2/token'));
        $gateway->within($connection, fn () => $gateway->read($connection, Operation::Positions));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2/token'));
        Http::assertSent(fn ($request) => $request->url() === TrustedHttp::RESOURCE && $request->hasHeader('Authorization', 'Bearer synthetic-longer-access'));
    }

    public function test_batched_quotes_use_one_call_skip_unrequested_rows_and_refuse_invalid_batches_before_network(): void
    {
        $connection = McpFixture::connection();
        $tool = $this->quoteTool($connection);
        $this->fake([$tool], function ($params) {
            $this->assertSame(['AAA', 'BBB'], $params['arguments']['symbols']);

            return $this->quotes(['BBB', 'ZZZ', 'AAA']);
        });
        $result = app(RobinhoodGateway::class)->read($connection, Operation::Quotes, ['symbols' => ['AAA', 'BBB']]);
        $this->assertTrue($result->complete);
        $this->assertSame(['BBB', 'AAA'], array_map(fn ($record) => $record->fields['symbol'], $result->records));
        $this->assertSame(1, $this->traffic()['tools/call']);

        $this->fake([$tool], fn () => throw new \RuntimeException('Must not call'));
        foreach ([['AAA', 'BBB', 'CCC', 'DDD'], ['AAA', 'AAA'], ['aaa'], [], 'AAA', ['AAA', ['BBB']]] as $invalid) {
            try {
                app(RobinhoodGateway::class)->read($connection, Operation::Quotes, ['symbols' => $invalid]);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Malformed, $failure->reason);
            }
        }
        try {
            app(RobinhoodGateway::class)->read($connection, Operation::Quotes, ['symbols' => ['AAA', 'BBB'], 'symbol' => 'AAA']);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Malformed, $failure->reason);
        }
        Http::assertNothingSent();

        // A profile without a reviewed batch bound cannot be batched.
        McpFixture::configure($connection, Operation::Positions);
        try {
            app(RobinhoodGateway::class)->read($connection, Operation::Positions, ['symbols' => ['AAA', 'BBB']]);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Malformed, $failure->reason);
        }
        Http::assertNothingSent();
    }

    public function test_quote_batch_falls_back_to_single_reads_so_one_bad_row_only_affects_its_symbol(): void
    {
        $connection = McpFixture::connection();
        $tool = $this->quoteTool($connection, 2);
        $this->fake([$tool], function ($params) {
            $symbols = $params['arguments']['symbols'];
            if (count($symbols) > 1 && in_array('BAD', $symbols, true)) {
                return $this->quotes([...$symbols, 'BAD']); // duplicate row: the whole batch is ambiguous
            }
            if ($symbols === ['BAD']) {
                return McpFixture::result([]);
            }

            return $this->quotes($symbols);
        });
        $gateway = app(RobinhoodGateway::class);
        $batch = $gateway->within($connection, fn () => QuoteBatch::read($gateway, $connection, ['AAA', 'BAD', 'CCC', 'DDD', 'AAA']));
        $this->assertSame(['AAA', 'CCC', 'DDD'], array_keys($batch['quotes']));
        $this->assertSame(['BAD' => ReadError::Incomplete->value], $batch['failures']);
        foreach ($batch['quotes'] as $symbol => $quote) {
            $this->assertCount(1, $quote->records);
            $this->assertSame($symbol, $quote->records[0]->fields['symbol']);
            $this->assertTrue($quote->complete);
        }
        // Batch [AAA, BAD] fails, then two singles; batch [CCC, DDD] succeeds. All inside one session.
        $this->assertSame(['DELETE' => 1, 'initialize' => 1, 'notifications/initialized' => 1, 'tools/call' => 4, 'tools/list' => 1], $this->traffic());
    }

    public function test_rate_limit_pauses_every_later_read_locally_until_retry_after_passes(): void
    {
        $connection = McpFixture::connection();
        $tool = McpFixture::configure($connection, Operation::Positions);
        $this->fake([$tool], fn () => Http::response('', 429, ['Retry-After' => '120']));
        try {
            app(RobinhoodGateway::class)->read($connection, Operation::Positions);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::RateLimited, $failure->reason);
            $this->assertSame(120, $failure->retryAfter);
        }
        $sent = count(Http::recorded());
        $this->travel(60)->seconds();
        try {
            app(RobinhoodGateway::class)->read($connection, Operation::Positions);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::RateLimited, $failure->reason);
            $this->assertSame(60, $failure->retryAfter);
        }
        $this->assertCount($sent, Http::recorded());

        $this->travel(61)->seconds();
        $this->fake([$tool], fn () => McpFixture::result([McpFixture::position()]));
        $this->assertTrue(app(RobinhoodGateway::class)->read($connection, Operation::Positions)->complete);
    }

    public function test_missing_or_short_retry_after_uses_the_minimum_backoff(): void
    {
        $connection = McpFixture::connection();
        $tool = McpFixture::configure($connection, Operation::Positions);
        $this->fake([$tool], fn () => Http::response('', 429));
        try {
            app(RobinhoodGateway::class)->read($connection, Operation::Positions);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(config('robinhood_mcp.rate_limit_min_backoff_seconds'), $failure->retryAfter);
        }
        $this->assertSame(config('robinhood_mcp.rate_limit_min_backoff_seconds'), app(BrokerBackoff::class)->remaining($connection->id));
        // A shorter later pause never shortens the one already in force.
        app(BrokerBackoff::class)->record($connection->id, 1);
        $this->assertSame(config('robinhood_mcp.rate_limit_min_backoff_seconds'), app(BrokerBackoff::class)->remaining($connection->id));
        $this->assertSame(0, app(BrokerBackoff::class)->remaining($connection->id + 1));
    }
}
