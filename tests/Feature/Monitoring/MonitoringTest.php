<?php

namespace Tests\Feature\Monitoring;

use App\Jobs\MonitorAccountJob;
use App\Models\Monitoring\Holding;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Monitoring\PolicyVersion;
use App\Models\Monitoring\Run;
use App\Models\Monitoring\Snapshot;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\Monitor;
use App\Services\Monitoring\MonitoringScheduler;
use App\Services\Monitoring\VersionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private MonitoringGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->gateway = new MonitoringGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
    }

    public function test_partial_read_keeps_prior_holdings_and_retry_publishes_once(): void
    {
        $connection = McpFixture::connection();
        $monitor = app(Monitor::class);
        $first = $monitor->sync($connection->id, 'first');
        $this->assertSame('complete', $first->status);
        $this->gateway->partial = true;
        $this->gateway->empty = true;
        $failed = $monitor->sync($connection->id, 'next');
        $this->assertSame('failed', $failed->status);
        $this->assertSame('incomplete_pagination', $failed->error_code);
        $this->assertSame(1, Snapshot::where('status', 'complete')->count());
        $this->assertSame(1, Holding::count());
        $this->gateway->partial = false;
        $recovered = $monitor->sync($connection->id, 'next');
        $this->assertSame($failed->id, $recovered->id);
        $this->assertSame('complete', $recovered->status);
        $this->assertCount(0, Snapshot::where('run_id', $recovered->id)->first()->observations['holdings']);
        $calls = count($this->gateway->calls);
        $this->assertSame($recovered->id, $monitor->sync($connection->id, 'next')->id);
        $this->assertCount($calls, $this->gateway->calls);
        $this->assertSame(2, Snapshot::count());
        $this->assertSame(1, PolicyVersion::count());
    }

    public function test_one_run_uses_one_broker_session_and_one_batched_quote_read(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->held = ['AAA', 'BBB', 'CCC'];
        $this->gateway->unquoted = ['CCC'];
        $run = app(Monitor::class)->sync($connection->id, 'batched');
        $this->assertSame('complete', $run->status);
        $this->assertSame(1, $this->gateway->scopes);
        $this->assertSame(['portfolio', 'positions', 'orders', 'quotes'], $this->gateway->calls);
        $this->assertSame(['symbols' => ['AAA', 'BBB', 'CCC']], $this->gateway->filters[3]);
        $payload = Snapshot::where('run_id', $run->id)->firstOrFail()->payload;
        $this->assertSame(['AAA', 'BBB'], array_keys($payload['quotes']));
        foreach ($payload['quotes'] as $symbol => $quote) {
            $this->assertSame('complete', $quote['completeness']);
            $this->assertCount(1, $quote['records']);
            $this->assertSame($symbol, $quote['records'][0]['data']['symbol']);
        }
        // A symbol the broker did not quote is explicitly unknown, never priced.
        $this->assertSame(['CCC'], array_keys($payload['quote_failures']));
        $this->assertSame('incomplete_quote', $payload['quote_failures']['CCC']['error_code']);
    }

    public function test_binding_change_during_read_prevents_publication(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->beforeRead = function ($record, $operation) use ($connection) {
            if ($operation === Operation::Orders) {
                $connection->update(['bound_account_id' => 'different-synthetic']);
            }
        };
        $run = app(Monitor::class)->sync($connection->id, 'changed');
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, Snapshot::where('status', 'complete')->count());
        $this->assertSame(0, Holding::count());
    }

    public function test_error_text_is_not_persisted_and_incidents_and_outbox_are_deduplicated(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->beforeRead = fn () => throw new \RuntimeException('secret upstream value');
        app(Monitor::class)->sync($connection->id, 'fail1');
        app(Monitor::class)->sync($connection->id, 'fail2');
        $this->assertSame(1, Incident::count());
        $this->assertSame(1, Outbox::count());
        $this->assertSame('monitoring_failed', Run::first()->error_code);
        $this->assertStringNotContainsString('secret upstream', json_encode(DB::table('mcp_runs')->get()));
    }

    public function test_expired_worker_is_fenced_and_restart_reclaims_same_run(): void
    {
        $connection = McpFixture::connection();
        $reclaimed = null;
        $once = true;
        $this->gateway->beforeRead = function () use ($connection, &$once, &$reclaimed) {
            if (! $once) {
                return;
            }
            $once = false;
            $this->travel(11)->minutes();
            $reclaimed = app(Monitor::class)->sync($connection->id, 'restart');
        };
        $run = app(Monitor::class)->sync($connection->id, 'restart');
        $this->assertSame('complete', $run->status);
        $this->assertSame($reclaimed->id, $run->id);
        $this->assertSame(1, Snapshot::where('status', 'complete')->count());
        $this->assertSame(1, Holding::count());
        $this->assertSame(1, Incident::where('incident_key', 'abandoned_run')->count());
    }

    public function test_exposure_and_sleeve_residual_are_precise_without_double_reserving_buying_power(): void
    {
        $connection = McpFixture::connection();
        app(VersionLedger::class)->allocate($connection, $connection->user_id, 'SYNTH:equity', 'active', '1');
        $run = app(Monitor::class)->sync($connection->id, 'risk');
        $snapshot = Snapshot::where('run_id', $run->id)->firstOrFail();
        $o = $snapshot->observations;
        $this->assertSame('400.000000000000000000', $o['equity_exposure']);
        $this->assertSame('150.0', $o['pending_buy_limit_notional']);
        $this->assertNull($o['pending_buy_commitments']);
        $this->assertSame('550.00', $o['buying_power']);
        $this->assertSame('1.000000000000000001', $o['holdings'][0]['attribution']['unclassified']);
        $this->assertFalse($o['performance']['available']);
        $this->assertContains('market_session_unknown', $o['pause_reasons']);
        $this->assertStringNotContainsString('SYNTH', DB::table('mcp_snapshots')->first()->payload);
        $policy = PolicyVersion::first();
        $this->assertSame('disabled', $policy->payload['mode']);
        $this->expectException(\LogicException::class);
        $policy->update(['payload' => ['mode' => 'bounded_auto']]);
    }

    public function test_invalid_run_identifiers_are_rejected_before_reading(): void
    {
        $connection = McpFixture::connection();
        foreach ([['', null], ['undefined', null], ['valid', 'undefined'], ['valid', '']] as [$key, $id]) {
            try {
                app(Monitor::class)->sync($connection->id, $key, runId: $id);
                $this->fail('Invalid identifier accepted');
            } catch (\InvalidArgumentException) {
                $this->assertSame(0, Run::count());
            }
        }
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_dashboard_is_scoped_freshness_changes_with_time_and_ack_uses_server_actor(): void
    {
        $connection = McpFixture::connection();
        $other = McpFixture::connection();
        $user = User::findOrFail($connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        $this->get('/mcp/dashboard')->assertRedirect('/login');
        $this->actingAs(User::find($other->user_id))->get('/mcp/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/mcp/dashboard')->assertOk()->assertSee('No complete snapshot');
        app(Monitor::class)->sync($connection->id, 'ui');
        app(Monitor::class)->sync($other->id, 'private');
        $otherIncident = Incident::where('mcp_connection_id', $other->id)->first();
        $this->post('/mcp/incidents/'.$otherIncident->id.'/acknowledge')->assertNotFound();
        $ownIncident = Incident::where('mcp_connection_id', $connection->id)->first();
        $this->post('/mcp/incidents/'.$ownIncident->id.'/acknowledge', ['actor' => $other->user_id])->assertRedirect();
        $this->assertSame($user->id, $ownIncident->fresh()->acknowledged_by);
        foreach (['overview', 'holdings', 'research', 'orders', 'performance', 'health'] as $page) {
            $this->get('/mcp/dashboard/'.$page)->assertOk()->assertDontSee('synthetic-agentic')->assertHeader('Cache-Control', 'no-store, private')->assertSee('Data provenance');
        }
        $this->travel(6)->minutes();
        $this->get('/mcp/dashboard')->assertSee('stale');
        $connection->update(['bound_account_id' => 'new-synthetic-account']);
        $this->get('/mcp/dashboard/holdings')->assertSee('No complete snapshot')->assertDontSee('SYNTH');
    }

    public function test_dashboard_mutations_require_csrf_and_run_id(): void
    {
        $connection = McpFixture::connection();
        $user = User::find($connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        $this->actingAs($user)->postJson('/mcp/dashboard/sync', ['run_id' => 'undefined'])->assertUnprocessable();
        app()->instance('env', 'local');
        $this->post('/mcp/dashboard/sync', ['run_id' => (string) Str::uuid()])->assertStatus(419);
    }

    public function test_scheduler_defaults_off_deduplicates_and_separates_reconciliation_queue(): void
    {
        Queue::fake();
        $connection = McpFixture::connection();
        $user = User::find($connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        $scheduler = app(MonitoringScheduler::class);
        $this->assertSame(0, $scheduler->tick());
        Queue::assertNothingPushed();
        app(Monitor::class)->sync($connection->id, 'seed');
        config(['mcp.monitoring_enabled' => true]);
        $this->assertSame(2, $scheduler->tick());
        $this->assertSame(0, $scheduler->tick());
        Queue::assertPushedOn('monitoring', MonitorAccountJob::class);
        Queue::assertPushedOn('reconciliation', MonitorAccountJob::class);
        Queue::assertPushed(MonitorAccountJob::class, 2);
        $this->assertSame(0, Outbox::where('kind', 'notification')->whereNotNull('dispatched_at')->count());
    }

    public function test_retry_of_older_staged_snapshot_becomes_latest_publication(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->partial = true;
        $older = app(Monitor::class)->sync($connection->id, 'older');
        $this->gateway->partial = false;
        app(Monitor::class)->sync($connection->id, 'newer');
        $this->gateway->empty = true;
        app(Monitor::class)->sync($connection->id, 'older');
        $latest = Snapshot::where('status', 'complete')->latest('publication_version')->first();
        $this->assertSame($older->id, $latest->run_id);
        $this->assertSame(2, $latest->publication_version);
        $this->assertSame([], $latest->observations['holdings']);
    }

    public function test_expired_quote_worker_cannot_overwrite_replacement_payload(): void
    {
        $connection = McpFixture::connection();
        $once = true;
        $this->gateway->beforeRead = function ($record, $operation) use ($connection, &$once) {
            if ($operation !== Operation::Quotes || ! $once) {
                return;
            }
            $once = false;
            $this->travel(11)->minutes();
            app(Monitor::class)->sync($connection->id, 'quote-restart');
            // The old worker resumes with different metadata after the replacement has published.
            $this->gateway->sourceTimes = false;
        };
        $run = app(Monitor::class)->sync($connection->id, 'quote-restart');
        $snapshot = Snapshot::where('run_id', $run->id)->firstOrFail();
        $this->assertSame('complete', $run->status);
        $this->assertNotNull($snapshot->payload['quotes']['SYNTH']['records'][0]['source_as_of']);
        $this->assertSame('fresh', $snapshot->observations['freshness']['quote:SYNTH']['status']);
    }

    public function test_missing_source_time_stays_unknown_in_persistence_and_ui(): void
    {
        $connection = McpFixture::connection();
        $user = User::find($connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        $this->gateway->sourceTimes = false;
        app(Monitor::class)->sync($connection->id, 'unknown-time');
        $this->assertSame('source_time_unknown', Snapshot::first()->observations['freshness']['portfolio']['status']);
        $this->actingAs($user)->get('/mcp/dashboard')->assertSee('source_time_unknown');
        $this->gateway->beforeRead = fn () => throw new ReadFailure(ReadError::Timeout);
        $this->travel(1)->seconds();
        app(Monitor::class)->sync($connection->id, 'timeout-ui');
        $this->get('/mcp/dashboard/holdings')->assertSee('Latest observation failed')->assertSee('SYNTH');
    }

    public function test_monitoring_disabled_job_cannot_read_and_circuit_breaks_repeated_failures(): void
    {
        Queue::fake();
        $connection = McpFixture::connection();
        $user = User::find($connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        (new MonitorAccountJob($connection->id, 'disabled'))->handle(app(Monitor::class));
        $this->assertSame([], $this->gateway->calls);
        $this->gateway->beforeRead = fn () => throw new ReadFailure(ReadError::Authentication);
        foreach (range(1, 3) as $i) {
            app(Monitor::class)->sync($connection->id, 'failure:'.$i);
        }
        config(['mcp.monitoring_enabled' => true]);
        $this->assertSame(0, app(MonitoringScheduler::class)->tick());
        Queue::assertNothingPushed();
        $this->assertSame(1, Incident::where('incident_key', 'integration_circuit_open')->count());
    }

    public function test_queued_work_cannot_follow_an_account_rebinding_or_replay_old_work(): void
    {
        $connection = McpFixture::connection();
        $user = User::find($connection->user_id);
        config(['mcp.operator_emails' => [$user->email], 'mcp.monitoring_enabled' => true]);
        $job = new MonitorAccountJob($connection->id, 'queued', scope: AccountScope::key($connection), scheduledAt: now()->toIso8601String());
        $connection->update(['bound_account_id' => 'other-synthetic-account']);
        $job->handle(app(Monitor::class));
        $old = new MonitorAccountJob($connection->id, 'old', scope: AccountScope::key($connection), scheduledAt: now()->subHour()->toIso8601String());
        $old->handle(app(Monitor::class));
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_quote_failure_preserves_holdings_without_inventing_a_retrieval_time(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->beforeRead = function ($record, $operation) {
            if ($operation === Operation::Quotes) {
                throw new ReadFailure(ReadError::Stale);
            }
        };
        $run = app(Monitor::class)->sync($connection->id, 'quote-failed');
        $this->assertSame('complete', $run->status);
        $freshness = Snapshot::where('run_id', $run->id)->first()->observations['freshness']['quote:SYNTH'];
        $this->assertSame('unavailable', $freshness['status']);
        $this->assertNull($freshness['retrieved_at']);
        $this->assertNotNull($freshness['attempted_at']);
        $this->assertSame('stale_data', $freshness['error_code']);
        $this->assertSame(1, Holding::count());
    }

    public function test_provider_retry_after_blocks_other_triggers_until_cooldown_expires(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->beforeRead = fn () => throw new ReadFailure(ReadError::RateLimited, 120);
        $failed = app(Monitor::class)->sync($connection->id, 'rate-cooldown');
        $this->gateway->beforeRead = null;
        $this->assertSame($failed->id, app(Monitor::class)->sync($connection->id, 'different-trigger')->id);
        $this->assertCount(1, $this->gateway->calls);
        $this->travel(121)->seconds();
        $this->assertSame('complete', app(Monitor::class)->sync($connection->id, 'different-trigger')->status);
    }

    public function test_transient_failure_can_retry_same_durable_run(): void
    {
        $connection = McpFixture::connection();
        $this->gateway->beforeRead = fn () => throw new ReadFailure(ReadError::RateLimited);
        $failed = app(Monitor::class)->sync($connection->id, 'rate');
        $this->assertSame('rate_limited', $failed->error_code);
        $this->gateway->beforeRead = null;
        $this->assertSame($failed->id, app(Monitor::class)->sync($connection->id, 'rate')->id);
        $this->assertSame(1, Snapshot::count());
    }
}
