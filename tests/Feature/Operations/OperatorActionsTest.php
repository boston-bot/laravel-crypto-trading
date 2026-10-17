<?php

namespace Tests\Feature\Operations;

use App\Models\McpConnection;
use App\Models\Monitoring\Incident;
use App\Models\Monitoring\Outbox;
use App\Models\Operations\ComponentStatus;
use App\Models\Operations\NotificationDelivery;
use App\Models\Research\Summary;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use App\Services\Operations\DiskSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\TestCase;

class OperatorActionsTest extends TestCase
{
    use RefreshDatabase;

    private const NTFY = 'https://ntfy.example.test/';

    private McpConnection $connection;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->app->instance(BrokerGateway::class, new MonitoringGateway);
        $this->app->instance(DiskSpace::class, new class extends DiskSpace
        {
            public function freeBytes(string $path): ?float
            {
                return 50 * 1024 ** 3;
            }
        });
        $this->connection = McpFixture::connection();
        $this->user = User::findOrFail($this->connection->user_id);
        config(['mcp.operator_emails' => [$this->user->email], 'operations.ntfy.url' => 'https://ntfy.example.test',
            'operations.ntfy.topic' => 'synthetic-topic-0123456789', 'operations.healthchecks_url' => 'https://hc-ping.example.test/synthetic-check']);
        app(Monitor::class)->sync($this->connection->id, 'seed');
    }

    private function fake(array $routes = []): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake($routes);
    }

    public function test_actions_require_an_operator_session_and_csrf(): void
    {
        $routes = ['/mcp/operations/health-check', '/mcp/operations/test-alert', '/mcp/operations/summary', '/mcp/operations/database-check'];
        foreach ($routes as $route) {
            $this->post($route, ['kind' => 'daily'])->assertRedirect('/login');
            $this->actingAs(User::factory()->create())->post($route, ['kind' => 'daily'])->assertForbidden();
            auth()->logout();
        }
        $this->assertSame(0, Summary::count());
        $this->assertSame(0, Outbox::where('kind', 'notification')->get()->filter(fn ($e) => isset($e->payload['test']))->count());
        app()->instance('env', 'local');
        foreach ($routes as $route) {
            $this->actingAs($this->user)->post($route, ['kind' => 'daily'])->assertStatus(419);
        }
    }

    public function test_health_check_updates_incidents_without_pinging_the_heartbeat(): void
    {
        $this->fake();
        $this->actingAs($this->user)->post('/mcp/operations/health-check')
            ->assertRedirect(route('mcp.dashboard', ['page' => 'health']).'#operator-actions')
            ->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'bad' && str_contains($r['message'], 'heartbeat was not pinged') && in_array('scheduler: critical', $r['lines'], true));
        Http::assertNothingSent();
        $this->assertNull(ComponentStatus::where('component', 'heartbeat')->first());
        $this->assertSame('critical', ComponentStatus::where('component', 'health')->value('status'));
        $this->assertTrue(Incident::where('mcp_connection_id', $this->connection->id)->where('incident_key', 'health:scheduler')->where('state', 'open')->exists());
        $this->get('/mcp/dashboard/health')->assertSee('Health checks')->assertSee('scheduler: critical');
    }

    public function test_test_alert_requires_provider_acknowledgement_and_refuses_without_a_destination(): void
    {
        $this->fake([self::NTFY => Http::response(['id' => 'synthetic-ack'])]);
        $this->actingAs($this->user)->post('/mcp/operations/test-alert')
            ->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'ok' && str_contains($r['message'], 'does not prove phone delivery'));
        $this->assertSame('delivered', NotificationDelivery::where('kind', 'test')->sole()->status);

        $this->fake([self::NTFY => Http::response('unavailable', 503)]);
        $this->post('/mcp/operations/test-alert')->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'warn' && str_contains($r['message'], 'not acknowledged'));

        config(['operations.ntfy.topic' => '']);
        $before = Outbox::count();
        $this->post('/mcp/operations/test-alert')->assertSessionHas('operator_result', fn ($r) => $r['message'] === 'No alert destination is configured; nothing was queued.');
        $this->assertSame($before, Outbox::count());
    }

    public function test_summary_is_scoped_to_the_operators_own_account_and_idempotent(): void
    {
        $other = McpFixture::connection();
        config(['mcp.operator_emails' => [$this->user->email, User::find($other->user_id)->email]]);
        app(Monitor::class)->sync($other->id, 'other');
        $this->actingAs($this->user)->post('/mcp/operations/summary', ['kind' => 'daily'])
            ->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'ok' && str_contains($r['message'], 'same immutable record'));
        $this->post('/mcp/operations/summary', ['kind' => 'daily']);
        $this->post('/mcp/operations/summary', ['kind' => 'weekly'])->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'ok');
        $this->assertSame(2, Summary::count());
        $this->assertSame(0, Summary::where('mcp_connection_id', $other->id)->count());
        $this->post('/mcp/operations/summary', ['kind' => 'hourly'])->assertSessionHasErrors('kind');
        $this->assertSame(2, Summary::count());

        $this->connection->update(['bound_account_id' => null]);
        $this->post('/mcp/operations/summary', ['kind' => 'daily'])->assertSessionHas('operator_result', fn ($r) => str_contains($r['message'], 'Bind an account'));
    }

    public function test_database_check_reports_identity_or_a_redacted_failure(): void
    {
        $response = $this->actingAs($this->user)->post('/mcp/operations/database-check');
        if (DB::connection()->getDriverName() === 'pgsql') {
            $response->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'ok' && in_array('Database: mcp_trading_test', $r['lines'], true) && in_array('Schema: mcp_trading', $r['lines'], true));
        } else {
            $response->assertSessionHas('operator_result', fn ($r) => $r['tone'] === 'bad' && $r['lines'] === [] && str_contains($r['message'], 'Database unavailable'));
        }
    }

    public function test_a_running_action_is_not_started_twice(): void
    {
        $this->fake();
        $lock = Cache::lock('operator-action:health', 120);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($this->user)->post('/mcp/operations/health-check')
                ->assertSessionHas('operator_result', fn ($r) => $r['message'] === 'Already running; try again shortly.');
            $this->assertNull(ComponentStatus::where('component', 'health')->first());
        } finally {
            $lock->release();
        }
    }

    public function test_health_page_offers_safe_actions_and_keeps_privileged_commands_terminal_only(): void
    {
        $page = $this->actingAs($this->user)->get('/mcp/dashboard/health')->assertOk()->assertSee('Operator actions')
            ->assertSee(route('mcp.operations.health'))->assertSee(route('mcp.operations.test-alert'))->assertSee(route('mcp.operations.summary'))
            ->assertSee(route('mcp.operations.database'))->assertSee('Terminal only')->assertSee('php artisan mcp:backup')
            ->assertDontSee('synthetic-topic-0123456789')->assertDontSee('hc-ping.example.test');
        $this->assertStringNotContainsString('/mcp/operations/backup', $page->getContent());
    }
}
