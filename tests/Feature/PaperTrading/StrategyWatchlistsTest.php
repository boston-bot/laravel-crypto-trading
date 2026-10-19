<?php

namespace Tests\Feature\PaperTrading;

use App\Models\Monitoring\Incident;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\WatchlistRead;
use App\Models\PaperTrading\WatchlistRevision;
use App\Models\PaperTrading\WatchlistSetting;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\OfflineSimulator;
use App\Services\PaperTrading\StrategyWatchlists;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\McpFixture;
use Tests\Support\SimulationFixture;
use Tests\Support\StrategyWatchlistGateway;
use Tests\TestCase;

class StrategyWatchlistsTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private StrategyWatchlistGateway $gateway;

    private StrategyWatchlists $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $this->connection = McpFixture::connection()->fresh();
        config(['mcp.operator_emails' => [User::find($this->connection->user_id)->email],
            'paper.watchlist_intake_enabled' => true, 'robinhood_mcp.watchlists_enabled' => true]);
        $this->gateway = new StrategyWatchlistGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
        $this->service = app(StrategyWatchlists::class);
        $this->service->configure($this->connection, $this->connection->user_id, 0, (string) Str::uuid(), 'Strategy Candidates');
    }

    private function read(?string $request = null): WatchlistRead
    {
        return $this->service->collect($this->connection, $this->connection->user_id, $request ?? (string) Str::uuid());
    }

    private function nominations(): array
    {
        return $this->service->nominations($this->connection->fresh(), CarbonImmutable::now());
    }

    public function test_successful_reads_are_immutable_encrypted_revisions_with_stable_hashes_and_retry_identity(): void
    {
        $this->gateway->members[] = ['id' => 'crypto', 'object_type' => 'currency_pair', 'symbol' => 'BTC'];
        $request = (string) Str::uuid();
        $first = $this->read($request);
        $this->assertSame($first->id, $this->read($request)->id);
        $this->assertSame(3, $this->gateway->calls);
        $initial = WatchlistRevision::findOrFail($first->revision_id);
        $this->assertSame(['TEST'], $this->nominations()['symbols']);
        $this->assertSame('unverified', $this->nominations()['execution_eligibility']);
        $this->assertNull($initial->payload['source_as_of']);
        $this->assertSame('non_equity_item', collect($initial->payload['members'])->firstWhere('id', 'crypto')['skip_reason']);
        $this->travel(5)->minutes();
        $this->gateway->members = array_reverse($this->gateway->members);
        $next = WatchlistRevision::findOrFail($this->read()->revision_id);
        $this->assertNotSame($initial->id, $next->id);
        $this->assertSame($initial->membership_hash, $next->membership_hash);
        $this->assertTrue($initial->changed_at->eq($next->changed_at));
        $this->assertStringNotContainsString('TEST', DB::table('mcp_strategy_watchlist_revisions')->value('payload'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertDatabaseCount('mcp_paper_execution_versions', 0);
        $this->expectException(\LogicException::class);
        $initial->update(['membership_hash' => str_repeat('0', 64)]);
    }

    public function test_failure_and_rejection_never_extend_grace_and_unchanged_success_recovers(): void
    {
        $this->read();
        $this->travel(29)->minutes();
        $this->gateway->fail = true;
        $this->assertNotNull($this->read()->reason);
        $this->assertTrue($this->nominations()['fresh']);
        $this->travel(1)->minutes();
        $this->read();
        $this->assertFalse($this->nominations()['fresh']);
        $this->assertSame([], $this->nominations()['symbols']);
        $this->assertSame('open', Incident::where('incident_key', 'strategy_watchlist_expired')->sole()->state);
        $this->gateway->fail = false;
        $this->read();
        $this->assertTrue($this->nominations()['fresh']);
        $this->assertSame('resolved', Incident::where('incident_key', 'strategy_watchlist_expired')->sole()->state);
    }

    public function test_cap_counts_all_items_and_rejects_whole_read_while_valid_removals_and_empty_lists_take_effect(): void
    {
        $this->read();
        $this->gateway->members = array_map(fn ($i) => ['id' => 'item-'.$i, 'object_type' => 'currency_pair'], range(1, 76));
        $this->assertSame('watchlist_over_cap', $this->read()->reason);
        $this->assertDatabaseCount('mcp_strategy_watchlist_revisions', 1);
        $this->assertSame(['TEST'], $this->nominations()['symbols']);
        $this->gateway->members = array_slice($this->gateway->members, 0, 75);
        $this->assertNull($this->read()->reason);
        $this->assertSame([], $this->nominations()['symbols']);
        $this->gateway->members = [];
        $this->assertNull($this->read()->reason);
        $this->assertTrue($this->nominations()['fresh']);
        $this->assertSame([], $this->nominations()['symbols']);
        $this->assertDatabaseCount('mcp_paper_sim_positions', 0);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
    }

    public function test_same_named_replacement_list_is_rejected_and_new_settings_require_new_evidence(): void
    {
        $this->read();
        $this->gateway->listId = 'replacement';
        $this->assertSame('watchlist_identity_changed', $this->read()->reason);
        $this->service->configure($this->connection, $this->connection->user_id, 1, (string) Str::uuid(), 'Strategy Candidates');
        $this->assertFalse($this->nominations()['fresh']);
        $this->assertNull($this->read()->reason);
        $this->assertTrue($this->nominations()['fresh']);
    }

    public function test_authorization_change_during_read_prevents_storage_and_old_authorization_never_nominates(): void
    {
        $this->read();
        $this->gateway->during = function () {
            $this->connection->fresh()->increment('auth_generation');
        };
        try {
            $this->read();
            $this->fail('Changed authorization accepted.');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof HttpException || $e instanceof ReadFailure);
        }
        $this->assertDatabaseCount('mcp_strategy_watchlist_revisions', 1);
        $this->assertFalse($this->nominations()['fresh']);
    }

    public function test_quiet_alert_uses_verified_trading_sessions_and_membership_change_resolves_it(): void
    {
        $this->read();
        $calendar = app(MarketCalendar::class);
        foreach (['2026-10-20', '2026-10-21'] as $date) {
            $calendar->store($date, 'regular', $date.'T13:30:00Z', $date.'T20:00:00Z', 'synthetic:calendar');
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-21T20:01:00Z'));
        $this->read();
        $this->assertSame(['info', 'open'], array_values(Incident::where('incident_key', 'strategy_watchlist_quiet')->sole()->only(['severity', 'state'])));
        $this->gateway->members[0]['symbol'] = 'NEW';
        $this->read();
        $this->assertSame('resolved', Incident::where('incident_key', 'strategy_watchlist_quiet')->sole()->state);
    }

    public function test_scheduler_is_default_off_and_duplicate_windows_are_noops(): void
    {
        config(['paper.watchlist_intake_enabled' => false]);
        $this->artisan('mcp:strategy-watchlist', ['--once' => true])->expectsOutputToContain('processed: 0')->assertSuccessful();
        $this->assertSame(0, $this->gateway->calls);
        config(['paper.watchlist_intake_enabled' => true]);
        $this->service->tick();
        $this->service->tick();
        $this->assertSame(3, $this->gateway->calls);
        $this->travel(5)->minutes();
        $this->service->tick();
        $this->assertSame(6, $this->gateway->calls);
        $this->assertDatabaseCount('mcp_strategy_watchlist_revisions', 2);
    }

    public function test_point_in_time_membership_never_uses_later_settings_or_reads_and_revoked_operator_cannot_nominate(): void
    {
        $this->read();
        $cutoff = CarbonImmutable::now();
        $this->travel(5)->minutes();
        $this->gateway->members[0]['symbol'] = 'NEW';
        $this->read();
        $this->assertSame(['TEST'], $this->service->nominations($this->connection, $cutoff)['symbols']);
        $this->assertSame(['NEW'], $this->nominations()['symbols']);
        $this->service->configure($this->connection, $this->connection->user_id, 1, (string) Str::uuid(), 'Strategy Candidates');
        $this->assertSame(['TEST'], $this->service->nominations($this->connection, $cutoff)['symbols']);
        $this->assertFalse($this->nominations()['fresh']);
        $this->read();
        config(['mcp.operator_emails' => []]);
        $this->assertSame([], $this->nominations()['symbols']);
    }

    public function test_settings_change_during_network_read_discards_observation(): void
    {
        $this->gateway->during = function () {
            $this->service->configure($this->connection, $this->connection->user_id, 1, (string) Str::uuid(), 'Replacement Strategy');
        };
        try {
            $this->read();
            $this->fail('Changed settings accepted.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('mcp_strategy_watchlist_revisions', 0);
        $this->assertDatabaseCount('mcp_strategy_watchlist_reads', 0);
    }

    public function test_failed_commit_rolls_back_membership_and_retry_records_exactly_one_revision(): void
    {
        $crash = true;
        WatchlistRead::creating(function () use (&$crash) {
            if ($crash) {
                throw new \RuntimeException('Synthetic commit failure');
            }
        });
        $request = (string) Str::uuid();
        try {
            $this->read($request);
            $this->fail('Synthetic crash did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic commit failure', $e->getMessage());
        } finally {
            $crash = false;
        }
        $this->assertDatabaseCount('mcp_strategy_watchlist_revisions', 0);
        $this->assertDatabaseCount('mcp_strategy_watchlist_reads', 0);
        $this->read($request);
        $this->assertDatabaseCount('mcp_strategy_watchlist_revisions', 1);
        $this->assertDatabaseCount('mcp_strategy_watchlist_reads', 1);
    }

    public function test_removed_or_expired_membership_leaves_held_shares_and_paper_exit_path_available(): void
    {
        [$connection, $portfolio, $policy] = SimulationFixture::seed();
        $this->connection = $connection;
        $this->gateway->members[0]['symbol'] = 'DEMO';
        $this->service->configure($connection, $connection->user_id, 0, (string) Str::uuid(), 'Strategy Candidates');
        $this->read();
        $simulator = app(OfflineSimulator::class);
        $buy = $simulator->submit($connection, $connection->user_id, $policy->id, $portfolio->fresh()->ledger_version,
            SimulationFixture::intent(), SimulationFixture::assumptions(), ['DEMO' => SimulationFixture::quote()]);
        $this->travel(1)->seconds();
        $this->assertSame('filled', $simulator->observe($connection, $connection->user_id, $buy->id, SimulationFixture::quote())->status);
        $this->gateway->members = [];
        $this->read();
        $this->assertSame([], $this->nominations()['symbols']);
        $this->assertSame('10.000000000000000000', SimulationPosition::sole()->quantity);
        $this->travel(31)->minutes();
        $this->assertFalse($this->nominations()['fresh']);
        $sell = $simulator->submit($connection, $connection->user_id, $policy->id, $portfolio->fresh()->ledger_version,
            SimulationFixture::intent(['side' => 'SELL', 'price_bound' => '98']), SimulationFixture::assumptions(), ['DEMO' => SimulationFixture::quote()]);
        $this->travel(1)->seconds();
        $this->assertSame('filled', $simulator->observe($connection, $connection->user_id, $sell->id, SimulationFixture::quote())->status);
        $this->assertSame('0.000000000000000000', SimulationPosition::sole()->quantity);
        Http::assertNothingSent();
    }

    private function member(string $symbol, string $type = 'instrument'): array
    {
        return ['id' => 'fixture-'.strtolower($symbol), 'object_type' => $type, 'symbol' => $symbol];
    }

    /** Core and Growth nominate; Awaiting only warms up data. */
    private function threeLists(array $core = ['AAA', 'BBB'], array $growth = ['BBB', 'CCC'], array $awaiting = ['DDD', 'AAA']): void
    {
        $this->gateway->lists = ['Core candidates' => ['id' => 'list-core', 'members' => array_map(fn ($s) => $this->member($s), $core)],
            'Growth opportunities' => ['id' => 'list-growth', 'members' => array_map(fn ($s) => $this->member($s), $growth)],
            'Awaiting price or evidence' => ['id' => 'list-awaiting', 'members' => [...array_map(fn ($s) => $this->member($s), $awaiting), $this->member('OPTN', 'option_strategy')]]];
        $this->service->configure($this->connection, $this->connection->user_id, $this->service->setting($this->connection)->version, (string) Str::uuid(), [
            ['name' => 'Core candidates', 'role' => 'nominate'], ['name' => 'Growth opportunities', 'role' => 'nominate'],
            ['name' => 'Awaiting price or evidence', 'role' => 'data_only']]);
    }

    public function test_several_lists_nominate_by_role_and_data_only_members_only_warm_up_collection(): void
    {
        $this->threeLists();
        $this->assertNull($this->read()->reason);
        $revision = WatchlistRevision::latest('id')->first();
        $this->assertSame(['Core candidates' => 'list-core', 'Growth opportunities' => 'list-growth', 'Awaiting price or evidence' => 'list-awaiting'], $revision->payload['list_ids']);
        $tagged = array_map(fn ($m) => [$m['symbol'], $m['list'], $m['role'], $m['skip_reason']], $revision->payload['members']);
        $this->assertContains(['BBB', 'Growth opportunities', 'nominate', null], $tagged);
        $this->assertContains(['OPTN', 'Awaiting price or evidence', 'data_only', 'non_equity_item'], $tagged);
        $nominations = $this->nominations();
        // A symbol on a nominating list nominates even when it also sits on the data-only list.
        $this->assertSame(['AAA', 'BBB', 'CCC'], $nominations['symbols']);
        $this->assertSame(['DDD'], $nominations['data_symbols']);
        // Moving a symbol from Awaiting to Core nominates it on the next read; removal from every list drops it.
        $this->threeLists(['AAA', 'BBB', 'DDD'], ['CCC'], []);
        $this->read();
        $this->assertSame([['AAA', 'BBB', 'CCC', 'DDD'], []], [$this->nominations()['symbols'], $this->nominations()['data_symbols']]);
    }

    public function test_any_failed_list_fails_the_whole_read_and_caps_apply_per_role(): void
    {
        $this->threeLists();
        $this->read();
        $before = WatchlistRevision::count();
        $this->gateway->fail = true;
        $this->gateway->failList = 'Growth opportunities';
        $this->assertSame('broker_transport_unavailable', $this->read()->reason);
        $this->assertSame($before, WatchlistRevision::count());
        $this->assertSame(['AAA', 'BBB', 'CCC'], $this->nominations()['symbols']); // last valid membership, within the grace period
        $this->gateway->fail = false;
        $symbols = fn (int $n, string $prefix) => array_map(fn ($i) => $prefix.chr(65 + intdiv($i, 26)).chr(65 + $i % 26), range(0, $n - 1));
        $this->threeLists($symbols(40, 'C'), $symbols(36, 'G'), $symbols(75, 'W'));
        $this->assertSame('watchlist_over_cap', $this->read()->reason); // 76 nominating items
        $this->threeLists($symbols(40, 'C'), $symbols(35, 'G'), $symbols(75, 'W'));
        $this->assertSame('watchlist_over_cap', $this->read()->reason); // 76 data-only items, counting the option
        $this->threeLists($symbols(40, 'C'), $symbols(35, 'G'), $symbols(74, 'W'));
        $this->assertNull($this->read()->reason);
        $this->assertCount(75, $this->nominations()['symbols']);
    }

    public function test_each_list_identity_is_pinned_for_its_settings_version(): void
    {
        $this->threeLists();
        $this->read();
        $this->gateway->lists['Growth opportunities']['id'] = 'list-growth-replacement';
        $this->assertSame('watchlist_identity_changed', $this->read()->reason);
    }

    public function test_multi_list_settings_are_validated_and_saved_from_the_control_form(): void
    {
        $configure = fn (array $lists) => $this->service->configure($this->connection, $this->connection->user_id, $this->service->setting($this->connection)->version, (string) Str::uuid(), $lists);
        foreach ([
            [['name' => 'Core candidates', 'role' => 'nominate'], ['name' => 'core CANDIDATES', 'role' => 'data_only']],
            [['name' => 'Core candidates', 'role' => 'nominate'], ['name' => 'On Watch', 'role' => 'data_only']],
            [['name' => 'Awaiting price or evidence', 'role' => 'data_only']],
        ] as $lists) {
            try {
                $configure($lists);
                $this->fail();
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode());
            }
        }
        foreach ([[['name' => 'Core candidates', 'role' => 'buy_now']], array_fill(0, 6, ['name' => 'X', 'role' => 'nominate'])] as $lists) {
            try {
                $configure($lists);
                $this->fail();
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->actingAs(User::find($this->connection->user_id));
        $this->post('/mcp/control/strategy-watchlist', ['base_version' => $this->service->setting($this->connection)->version, 'request_id' => (string) Str::uuid(),
            'account_scope' => AccountScope::key($this->connection), 'confirm_dedicated' => '1', 'lists' => [
                ['name' => 'Core candidates', 'role' => 'nominate'], ['name' => 'Growth opportunities', 'role' => 'nominate'],
                ['name' => 'Awaiting price or evidence', 'role' => 'data_only'], ['name' => '', 'role' => 'nominate'], ['name' => ' ', 'role' => 'data_only']]])->assertRedirect();
        $this->assertSame(['lists' => [['name' => 'Core candidates', 'role' => 'nominate'], ['name' => 'Growth opportunities', 'role' => 'nominate'],
            ['name' => 'Awaiting price or evidence', 'role' => 'data_only']]], $this->service->setting($this->connection)->payload);
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Growth opportunities')->assertSee('Data only (warm-up)');
    }

    public function test_control_form_is_operator_scoped_validated_versioned_and_never_enables_intake(): void
    {
        $url = '/mcp/control/strategy-watchlist';
        $payload = ['name' => 'Dedicated Next', 'base_version' => 1, 'request_id' => (string) Str::uuid(),
            'account_scope' => AccountScope::key($this->connection), 'confirm_dedicated' => '1'];
        $this->postJson($url, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, $payload)->assertForbidden();
        $this->actingAs(User::find($this->connection->user_id));
        config(['paper.watchlist_intake_enabled' => false]);
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->assertDatabaseCount('mcp_strategy_watchlist_settings', 2);
        $this->assertFalse(config('paper.watchlist_intake_enabled'));
        $this->postJson($url, array_replace($payload, ['request_id' => (string) Str::uuid()]))->assertConflict();
        $this->postJson($url, array_replace($payload, ['name' => 'On Watch']))->assertUnprocessable();
        $this->postJson($url, array_replace($payload, ['account_scope' => str_repeat('0', 64)]))->assertConflict();
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Dedicated Next')->assertSee('new strategy entries blocked');
        $this->assertDatabaseCount('mcp_strategy_watchlist_reads', 0);
        $this->expectException(\LogicException::class);
        WatchlistSetting::first()->delete();
    }
}
