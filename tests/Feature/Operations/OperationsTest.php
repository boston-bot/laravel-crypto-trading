<?php

namespace Tests\Feature\Operations;

use App\Jobs\QueueProbeJob;
use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Operations\ComponentStatus;
use App\Models\Operations\NotificationDelivery;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Monitoring\IncidentRecorder;
use App\Services\Monitoring\Monitor;
use App\Services\Operations\DiskSpace;
use App\Services\Operations\Notifications;
use App\Services\Operations\OperationsHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private const NTFY = 'https://ntfy.example.test/';

    private const PING = 'https://hc-ping.example.test/synthetic-check';

    private MonitoringGateway $gateway;

    private McpConnection $connection;

    private User $user;

    private DiskSpace $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->gateway = new MonitoringGateway;
        $this->app->instance(BrokerGateway::class, $this->gateway);
        $this->disk = new class extends DiskSpace
        {
            public ?float $free = 50 * 1024 ** 3;

            public function freeBytes(string $path): ?float
            {
                return $this->free;
            }
        };
        $this->app->instance(DiskSpace::class, $this->disk);
        $this->connection = McpFixture::connection();
        $this->user = User::findOrFail($this->connection->user_id);
        config(['mcp.operator_emails' => [$this->user->email], 'operations.ntfy.url' => 'https://ntfy.example.test',
            'operations.ntfy.topic' => 'synthetic-topic-0123456789', 'operations.healthchecks_url' => self::PING]);
    }

    private function fake(array $routes): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake($routes);
    }

    private function record(string $key, string $severity = 'warning', string $source = 'monitoring'): void
    {
        DB::transaction(fn () => app(IncidentRecorder::class)->record(McpConnection::whereKey($this->connection->id)->lockForUpdate()->firstOrFail(), $key, $severity, $source));
    }

    /** What a worker does: process the probe and remove it from the queue. */
    private function work(string $queue, string $connection): void
    {
        (new QueueProbeJob($queue, $connection, ComponentStatus::where('component', 'probe:'.$queue)->first()?->detail['probe_id'] ?? null))->handle();
        DB::table('jobs')->where('queue', $queue)->delete();
    }

    public function test_incident_episodes_resolve_reopen_and_acknowledgement_covers_the_ongoing_episode(): void
    {
        $this->record('health:disk', 'critical', 'operations');
        $this->record('health:disk', 'critical', 'operations');
        $incident = Incident::sole();
        $this->assertSame(['open', 'operations', 1], [$incident->state, $incident->source, Outbox::count()]);
        $incident->update(['state' => 'acknowledged', 'acknowledged_by' => $this->user->id, 'acknowledged_at' => now()]);
        $this->record('health:disk', 'critical', 'operations');
        $this->assertSame('acknowledged', $incident->fresh()->state);
        $this->assertSame(1, Outbox::count());
        DB::transaction(fn () => app(IncidentRecorder::class)->resolve($this->connection->fresh(), 'health:disk'));
        $this->assertSame('resolved', $incident->fresh()->state);
        $this->record('health:disk', 'critical', 'operations');
        $reopened = $incident->fresh();
        $this->assertSame(['open', 1, null, null], [$reopened->state, $reopened->reopen_count, $reopened->acknowledged_by, $reopened->resolved_at]);
        $this->assertSame(['incident:'.$incident->id, 'incident:'.$incident->id.':1'], Outbox::pluck('event_key')->sort()->values()->all());
    }

    public function test_complete_publication_resolves_failures_and_cleared_pause_reasons_only(): void
    {
        $this->gateway->beforeRead = fn () => throw new ReadFailure(ReadError::Timeout);
        app(Monitor::class)->sync($this->connection->id, 'fails');
        $timeout = Incident::where('incident_key', 'timeout')->sole();
        $this->assertSame('critical', $timeout->severity);
        $this->record('research_budget_reduced', 'warning', 'research');
        $this->gateway->beforeRead = null;
        app(Monitor::class)->sync($this->connection->id, 'recovers');
        $this->assertNotNull($timeout->fresh()->resolved_at);
        $this->assertNull(Incident::where('incident_key', 'market_session_unknown')->sole()->resolved_at); // still asserted
        $this->assertNull(Incident::where('incident_key', 'research_budget_reduced')->sole()->resolved_at); // other source
    }

    public function test_urgent_alerts_count_as_delivered_only_with_provider_acknowledgement(): void
    {
        $this->fake([self::NTFY => Http::response(['id' => 'synthetic-message', 'event' => 'message'])]);
        $this->record('authentication_required', 'critical');
        $this->record('unclassified_holdings');
        $this->assertSame(['delivered' => 1, 'failed' => 0, 'deferred' => 1, 'skipped' => 0], app(Notifications::class)->dispatch());
        $delivery = NotificationDelivery::where('status', 'delivered')->sole();
        $this->assertSame(['synthetic-message', 'incident', 'ntfy'], [$delivery->provider_message_id, $delivery->kind, $delivery->channel]);
        $this->assertSame(1, Incident::where('incident_key', 'authentication_required')->sole()->notified_count);
        $this->assertSame('deferred', NotificationDelivery::where('incident_id', Incident::where('incident_key', 'unclassified_holdings')->value('id'))->value('status'));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::NTFY && $request['topic'] === 'synthetic-topic-0123456789' && $request['priority'] === 5
            && str_contains($request['title'], 'authentication_required') && ! str_contains($request->body(), 'synthetic-agentic'));
        $this->assertSame(0, Outbox::whereNull('dispatched_at')->count());
    }

    public function test_failed_deliveries_back_off_and_a_crashed_send_is_never_claimed_delivered(): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fakeSequence('https://ntfy.example.test/*')->push(['event' => 'message'])->push('unavailable', 500)->push(['id' => 'synthetic-late']);
        $this->record('authentication_required', 'critical');
        $notifications = app(Notifications::class);
        $this->assertSame(1, $notifications->dispatch()['failed']);
        $this->assertSame('ntfy_unacknowledged', NotificationDelivery::latest('id')->value('error_code'));
        $this->assertSame(0, array_sum($notifications->dispatch())); // backing off
        $this->travel(61)->seconds();
        $notifications->dispatch();
        $this->assertSame('ntfy_http_500', NotificationDelivery::latest('id')->value('error_code'));
        $this->travel(121)->seconds();
        $this->assertSame(1, $notifications->dispatch()['delivered']);
        $this->assertSame(3, Outbox::sole()->attempts);

        $this->fake([self::NTFY => Http::response(['id' => 'synthetic-retry'])]);
        $notifications->queueTest();
        $test = Outbox::whereNull('dispatched_at')->sole();
        $test->update(['attempts' => 1]);
        NotificationDelivery::create(['outbox_id' => $test->id, 'kind' => 'test', 'channel' => 'ntfy', 'status' => 'sending', 'attempted_at' => now()]);
        $this->assertSame(0, array_sum($notifications->dispatch())); // another sender may still be in flight
        $this->travel(3)->minutes();
        $this->assertSame(1, $notifications->dispatch()['delivered']);
        $this->assertSame(['unknown', 'delivered'], NotificationDelivery::where('outbox_id', $test->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_unacknowledged_urgent_incidents_repeat_every_thirty_minutes_until_acknowledged(): void
    {
        $this->fake([self::NTFY => Http::response(['id' => 'synthetic'])]);
        $notifications = app(Notifications::class);
        $this->record('integration_circuit_open', 'critical');
        $notifications->dispatch();
        $this->travel(29)->minutes();
        $this->assertSame(0, $notifications->escalate());
        $this->travel(2)->minutes();
        $this->assertSame(1, $notifications->escalate());
        $this->assertSame(0, $notifications->escalate());
        $this->assertSame(1, $notifications->dispatch()['delivered']);
        Http::assertSent(fn ($request) => str_contains((string) $request['title'], 'Still unacknowledged: integration_circuit_open'));
        $this->assertSame(0, $notifications->escalate());
        $this->travel(31)->minutes();
        Incident::sole()->update(['state' => 'acknowledged', 'acknowledged_by' => $this->user->id, 'acknowledged_at' => now()]);
        $this->assertSame(0, $notifications->escalate());
        $this->assertSame(2, Incident::sole()->notified_count);
    }

    public function test_unconfigured_destination_keeps_intents_pending_and_digest_is_daily_and_private(): void
    {
        config(['operations.ntfy.topic' => null]);
        $this->record('authentication_required', 'critical');
        $this->assertNull(app(Notifications::class)->dispatch());
        $this->artisan('mcp:notify', ['--test' => true])->assertExitCode(1);
        $this->assertSame(0, NotificationDelivery::count());
        $this->assertSame(2, Outbox::whereNull('dispatched_at')->count());

        config(['operations.ntfy.topic' => 'synthetic-topic-0123456789']);
        $this->fake([self::NTFY => Http::response(['id' => 'synthetic'])]);
        app(Monitor::class)->sync($this->connection->id, 'digest-seed');
        $this->artisan('mcp:notify', ['--digest' => true])->assertExitCode(0);
        $this->artisan('mcp:notify', ['--digest' => true])->assertExitCode(0);
        $digests = Http::recorded(fn ($request) => str_contains((string) $request['title'], 'daily digest'));
        $this->assertCount(1, $digests);
        $body = $digests->first()[0]->body();
        $this->assertStringContainsString('Open incidents', $body);
        foreach (['synthetic-agentic', 'SYNTH', '1000.00', '600.00'] as $private) {
            $this->assertStringNotContainsString($private, $body);
        }
        $this->travel(1)->days();
        $this->artisan('mcp:notify', ['--digest' => true])->assertExitCode(0);
        $this->assertCount(2, Http::recorded(fn ($request) => str_contains((string) $request['title'], 'daily digest')));
        $this->assertSame(1, NotificationDelivery::where('kind', 'test')->where('status', 'delivered')->count());
    }

    public function test_health_checks_report_scheduler_queues_dead_letters_data_disk_and_backups(): void
    {
        $health = app(OperationsHealth::class);
        $checks = $health->evaluate();
        $this->assertSame(['critical', 'ok', 'ok', 'ok', 'ok', 'disabled', 'disabled', 'disabled', 'disabled', 'ok', 'warning', 'ok', 'ok'], array_column($checks, 'status'));
        $this->assertSame('disabled', $checks['automatic_trading']['status']);
        $this->assertSame('disabled', $checks['crypto_data']['status']);
        $this->assertSame('disabled', $checks['crypto_paper']['status']);
        ComponentStatus::record('scheduler', 'ticked');
        $this->assertSame('ok', $health->evaluate()['scheduler']['status']);
        $this->travel(4)->minutes();
        $this->assertSame('critical', $health->evaluate()['scheduler']['status']);

        $health->probeQueues();
        $this->assertSame(3, DB::table('jobs')->count());
        $health->probeQueues(); // a waiting probe is not duplicated
        $this->assertSame(3, DB::table('jobs')->count());
        $this->travel(16)->minutes();
        $this->assertSame('critical', $health->evaluate()['queue_monitoring']['status']);
        $this->work('monitoring', 'mcp_monitoring');
        $checks = $health->evaluate();
        $this->assertSame(['ok', 'critical'], [$checks['queue_monitoring']['status'], $checks['queue_research']['status']]);

        DB::table('failed_jobs')->insert(['uuid' => 'synthetic', 'connection' => 'mcp_monitoring', 'queue' => 'monitoring', 'payload' => '{}', 'exception' => 'synthetic', 'failed_at' => now()]);
        $this->disk->free = 3 * 1024 ** 3;
        $checks = $health->evaluate();
        $this->assertSame(['warning', 'warning'], [$checks['failed_jobs']['status'], $checks['disk']['status']]);
        $this->disk->free = 0.5 * 1024 ** 3;
        $this->assertSame('critical', $health->evaluate()['disk']['status']);

        config(['mcp.monitoring_enabled' => true]);
        $this->assertSame('critical', $health->evaluate()['data_age']['status']);
        app(Monitor::class)->sync($this->connection->id, 'fresh-data');
        $this->assertSame('ok', $health->evaluate()['data_age']['status']);
        $this->travel(3)->hours();
        $this->assertSame('critical', $health->evaluate()['data_age']['status']); // unknown calendar: hourly cadence plus margin
        ComponentStatus::record('backup', 'ok');
        $this->assertSame('ok', $health->evaluate()['backup']['status']);
        $this->travel(27)->hours();
        $this->assertSame('warning', $health->evaluate()['backup']['status']);
    }

    public function test_health_command_records_and_resolves_incidents_and_pings_the_heartbeat(): void
    {
        $this->fake([self::PING.'*' => Http::response('OK')]);
        $this->artisan('mcp:health', ['--scheduled' => true])->assertExitCode(0);
        Http::assertSent(fn ($request) => $request->url() === self::PING && $request->body() === 'ok');
        $this->assertSame('acknowledged', ComponentStatus::where('component', 'heartbeat')->value('status'));
        $this->assertSame('warning', Incident::where('incident_key', 'health:backup:warning')->sole()->severity);

        $this->travel(16)->minutes(); // scheduler silent and probes unprocessed
        $this->artisan('mcp:health')->assertExitCode(1);
        Http::assertSent(fn ($request) => $request->url() === self::PING.'/fail' && str_contains($request->body(), 'scheduler')
            && ! str_contains($request->body(), 'synthetic-topic'));
        $this->assertSame('critical', Incident::where('incident_key', 'health:scheduler')->sole()->severity);
        $this->assertSame(1, Outbox::where('event_key', 'incident:'.Incident::where('incident_key', 'health:scheduler')->value('id'))->count());

        foreach (OperationsHealth::QUEUES as $queue => $connection) {
            $this->work($queue, $connection);
        }
        $this->artisan('mcp:health', ['--scheduled' => true])->assertExitCode(0);
        $this->assertNotNull(Incident::where('incident_key', 'health:scheduler')->sole()->resolved_at);
        $this->assertNotNull(Incident::where('incident_key', 'health:queue_monitoring')->sole()->resolved_at);
    }

    public function test_queue_probe_requires_matching_id_even_when_timestamps_are_equal(): void
    {
        $health = app(OperationsHealth::class);
        $health->probeQueues();
        $firstId = ComponentStatus::where('component', 'probe:monitoring')->sole()->detail['probe_id'];
        // A stale/legacy job at the exact same timestamp cannot acknowledge this probe.
        (new QueueProbeJob('monitoring', 'mcp_monitoring', 'old-probe'))->handle();
        $this->assertNull(ComponentStatus::where('component', 'queue:monitoring')->first());
        $this->work('monitoring', 'mcp_monitoring');
        $this->travel(16)->minutes();
        $this->assertSame('ok', $health->evaluate()['queue_monitoring']['status']);
        $health->probeQueues();
        $secondId = ComponentStatus::where('component', 'probe:monitoring')->sole()->detail['probe_id'];
        $this->assertNotSame($firstId, $secondId);
        (new QueueProbeJob('monitoring', 'mcp_monitoring', $firstId))->handle();
        $this->travel(16)->minutes();
        $this->assertSame('critical', $health->evaluate()['queue_monitoring']['status']);
        $this->work('monitoring', 'mcp_monitoring');
        $this->assertSame('ok', $health->evaluate()['queue_monitoring']['status']);
    }

    public function test_operations_schedule_registers_only_when_enabled(): void
    {
        $this->assertCount(0, Schedule::events());
        config(['operations.enabled' => true]);
        require base_path('routes/console.php');
        $commands = collect(Schedule::events())->map(fn ($event) => $event->command)->implode("\n");
        foreach (['mcp:health --scheduled', 'mcp:notify --once', 'mcp:notify --digest', 'mcp:backup', 'mcp:restore-drill'] as $command) {
            $this->assertStringContainsString($command, $commands);
        }
        $this->assertCount(5, Schedule::events());
        $this->assertStringNotContainsString('mcp:monitor', $commands);
        $this->assertStringNotContainsString('mcp:research', $commands);
    }

    public function test_health_page_shows_checks_and_deliveries_without_destination_secrets(): void
    {
        $this->fake([self::NTFY => Http::response(['id' => 'synthetic-visible'])]);
        $this->record('authentication_required', 'critical');
        app(Notifications::class)->dispatch();
        $this->actingAs($this->user)->get('/mcp/dashboard/health')->assertOk()->assertSee('Operational checks')->assertSee('queue_monitoring')
            ->assertSee('synthetic-visible')->assertSee('ntfy configured')->assertDontSee('synthetic-topic-0123456789')->assertDontSee('hc-ping.example.test');
    }
}
