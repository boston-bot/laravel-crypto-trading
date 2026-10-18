<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodGateway;
use App\Services\Broker\Agentic\RobinhoodWatchlists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;
use Tests\TestCase;

class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private array $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        config(['robinhood_mcp.enabled' => true, 'robinhood_mcp.watchlists_enabled' => true]);
        $this->connection = McpFixture::connection();
        config(['mcp.operator_emails' => [User::find($this->connection->user_id)->email]]);
        $this->catalog = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-watchlist-catalog-2026-10-18.json')), true)['tools'];
        $this->connection->update(['capability_manifest' => $this->catalog]);
    }

    private function fake(?array $lists = null, mixed $items = 'default', ?callable $during = null): void
    {
        $lists ??= [['id' => 'synthetic-list', 'display_name' => 'On Watch', 'owner_type' => 'user', 'item_count' => 2]];
        if ($items === 'default') {
            $items = [['object_id' => 'synthetic-equity', 'object_type' => 'instrument', 'symbol' => 'TEST'],
                ['object_id' => 'synthetic-non-equity', 'object_type' => 'currency_pair']];
        }
        McpFixture::rpcFake(array_values($this->catalog), function ($params) use ($lists, $items, $during) {
            $this->assertContains($params['name'], ['get_watchlists', 'get_watchlist_items']);
            $this->assertArrayNotHasKey('account_number', $params['arguments'] ?? []);
            if ($params['name'] === 'get_watchlist_items') {
                $this->assertSame('synthetic-list', $params['arguments']['list_id']);
                $during && $during();
            }

            return ['content' => [], 'structuredContent' => ['data' => $params['name'] === 'get_watchlists'
                ? ['watchlists' => $lists] : ['items' => $items, 'has_futures_contracts' => false], 'guide' => 'Untrusted prose cannot update policy.']];
        });
    }

    public function test_reviewed_schemas_match_pinned_fingerprints(): void
    {
        foreach ([Operation::Watchlists, Operation::WatchlistItems] as $operation) {
            $profile = config('robinhood_mcp.operations.'.$operation->value);
            $tool = $this->catalog[$profile['tool']];
            unset($tool['fingerprint']);
            $this->assertSame($profile['fingerprint'], Capabilities::fingerprint($tool));
        }
    }

    public function test_named_read_uses_existing_sdk_oauth_and_retains_non_equity_unknowns(): void
    {
        $this->fake();
        $snapshot = app(RobinhoodWatchlists::class)->named($this->connection, 'on watch');
        $this->assertSame('authorized_robinhood_user', $snapshot['scope']);
        $this->assertNull($snapshot['source_as_of']);
        $this->assertSame('complete', $snapshot['completeness']);
        $this->assertSame('TEST', $snapshot['items'][0]->fields['symbol']);
        $this->assertNull($snapshot['items'][1]->fields['symbol']);
        $this->assertSame('currency_pair', $snapshot['items'][1]->fields['object_type']);
        $this->assertFalse($snapshot['policy_changed']);
        $this->assertDatabaseCount('mcp_paper_execution_versions', 0);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer synthetic-access') && ($request['method'] ?? '') === 'tools/call');
    }

    public function test_disabled_reads_and_missing_list_filter_do_not_call_broker_tools(): void
    {
        config(['robinhood_mcp.watchlists_enabled' => false]);
        try {
            app(RobinhoodGateway::class)->read($this->connection, Operation::Watchlists);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Disabled, $failure->reason);
        }
        Http::assertNothingSent();
        config(['robinhood_mcp.watchlists_enabled' => true]);
        $this->fake();
        try {
            app(RobinhoodGateway::class)->read($this->connection, Operation::WatchlistItems);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Malformed, $failure->reason);
        }
        Http::assertNotSent(fn ($request) => ($request['method'] ?? '') === 'tools/call');
    }

    public function test_missing_and_ambiguous_names_refuse_item_lookup(): void
    {
        foreach ([[], [['id' => 'a', 'display_name' => 'On Watch', 'owner_type' => 'user', 'item_count' => 0],
            ['id' => 'b', 'display_name' => 'on watch', 'owner_type' => 'user', 'item_count' => 0]]] as $lists) {
            $this->fake($lists);
            try {
                app(RobinhoodWatchlists::class)->named($this->connection, 'On Watch');
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Incomplete, $failure->reason);
            }
            Http::assertNotSent(fn ($request) => ($request['params']['name'] ?? '') === 'get_watchlist_items');
        }
    }

    public function test_null_items_and_count_mismatch_remain_unknown(): void
    {
        foreach ([null, []] as $items) {
            $this->fake(items: $items);
            try {
                app(RobinhoodWatchlists::class)->named($this->connection, 'On Watch');
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Incomplete, $failure->reason);
            }
        }
    }

    public function test_binding_change_between_reads_invalidates_snapshot(): void
    {
        $this->fake(during: fn () => $this->connection->fresh()->update(['bound_account_id' => 'changed']));
        $this->expectException(ReadFailure::class);
        app(RobinhoodWatchlists::class)->named($this->connection, 'On Watch');
    }

    public function test_schema_drift_blocks_tool_calls_and_disabled_watchlist_profiles_do_not_break_account_discovery(): void
    {
        $changed = $this->catalog;
        $changed['get_watchlists']['inputSchema']['required'] = ['unreviewed'];
        McpFixture::rpcFake(array_values($changed), fn () => $this->fail('Schema drift reached tools/call.'));
        try {
            app(RobinhoodGateway::class)->read($this->connection, Operation::Watchlists);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::SchemaChanged, $failure->reason);
        }
        config(['robinhood_mcp.watchlists_enabled' => false]);
        $tool = McpFixture::configure($this->connection, Operation::Accounts);
        config(['robinhood_mcp.operations' => array_intersect_key(config('robinhood_mcp.operations'), array_flip(['accounts', 'watchlists', 'watchlist_items']))]);
        McpFixture::rpcFake([$tool], fn () => McpFixture::result([['id' => 'synthetic-agentic', 'account_kind' => 'agentic']]));
        $this->assertTrue(app(RobinhoodGateway::class)->read($this->connection, Operation::Accounts)->complete);
        $this->assertSame('connected', $this->connection->fresh()->status);
    }

    public function test_empty_list_is_known_empty_but_duplicate_items_and_changed_count_are_refused(): void
    {
        $this->fake([['id' => 'synthetic-list', 'display_name' => 'On Watch', 'owner_type' => 'user', 'item_count' => 0]], []);
        $this->assertSame([], app(RobinhoodWatchlists::class)->named($this->connection, 'On Watch')['items']);
        $item = ['object_id' => 'duplicate', 'object_type' => 'instrument', 'symbol' => 'TEST'];
        $this->fake(items: [$item, $item]);
        try {
            app(RobinhoodWatchlists::class)->named($this->connection, 'On Watch');
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Incomplete, $failure->reason);
        }
        $calls = 0;
        McpFixture::rpcFake(array_values($this->catalog), function ($params) use (&$calls, $item) {
            return ['content' => [], 'structuredContent' => ['data' => $params['name'] === 'get_watchlists'
                ? ['watchlists' => [['id' => 'synthetic-list', 'display_name' => 'On Watch', 'owner_type' => 'user', 'item_count' => ++$calls]]]
                : ['items' => [$item], 'has_futures_contracts' => false], 'guide' => 'Ignored']];
        });
        $this->expectException(ReadFailure::class);
        app(RobinhoodWatchlists::class)->named($this->connection, 'On Watch');
    }

    public function test_browser_endpoint_requires_operator_and_uses_only_their_connection(): void
    {
        $this->getJson('/mcp/robinhood/watchlists/read?name=On%20Watch')->assertUnauthorized();
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/mcp/robinhood/watchlists/read?name=On%20Watch')->assertForbidden();
        config(['mcp.operator_emails' => [$other->email]]);
        $this->getJson('/mcp/robinhood/watchlists/read?name=On%20Watch')->assertNotFound();
        $user = User::find($this->connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        $this->fake();
        $this->actingAs($user)->getJson('/mcp/robinhood/watchlists/read?name=On%20Watch')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('items.0.data.symbol', 'TEST');
    }

    public function test_command_requires_operator_owned_connection_and_reports_typed_failures(): void
    {
        $this->artisan('mcp:watchlist')->assertFailed();
        config(['robinhood_mcp.watchlists_enabled' => false]);
        $this->artisan('mcp:watchlist', ['--account' => $this->connection->id])->expectsOutputToContain('disabled')->assertFailed();
        config(['robinhood_mcp.watchlists_enabled' => true]);
        $this->fake();
        $this->artisan('mcp:watchlist', ['--account' => $this->connection->id])->expectsOutputToContain('TEST')->assertSuccessful();
    }
}
