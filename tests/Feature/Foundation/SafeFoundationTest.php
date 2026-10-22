<?php

namespace Tests\Feature\Foundation;

use App\Jobs\SubmitBrokerOrderJob;
use App\Models\Asset;
use App\Models\BrokerCredential;
use App\Models\TradeDecision;
use App\Models\User;
use App\Services\Broker\Coinbase\CoinbaseClient;
use App\Services\Broker\Robinhood\RobinhoodClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class SafeFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_financial_routes_require_authentication_and_operator_permission(): void
    {
        $user = User::factory()->create();
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $uri = preg_replace('/\{[^}]+\}/', '999', $route->uri());
            $method = $route->methods()[0];
            $this->json($method, '/'.$uri)->assertUnauthorized();
            $this->actingAs($user)->json($method, '/'.$uri)->assertForbidden();
            auth()->forgetGuards();
        }
    }

    public function test_forged_actor_cannot_impersonate_approver(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email], 'mcp.approver_emails' => [$user->email]]);
        $decision = TradeDecision::query()->create([
            'asset_id' => Asset::query()->create(['broker' => 'coinbase', 'symbol' => 'BTC', 'asset_type' => 'crypto'])->id,
            'decision' => 'hold', 'status' => 'awaiting_human_approval',
            'requires_human_approval' => true, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->actingAs($user)->postJson("/api/trade-decisions/{$decision->id}/approve", ['actor' => 'someone-else'])
            ->assertOk();
        $this->assertSame((string) $user->id, $decision->fresh()->approved_by);
        Bus::assertDispatched(SubmitBrokerOrderJob::class);
    }

    public function test_operator_without_approval_permission_cannot_approve(): void
    {
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email], 'mcp.approver_emails' => []]);
        $this->actingAs($user)->postJson('/api/trade-decisions/999/approve', ['actor' => 'admin'])->assertForbidden();
    }

    public function test_browser_mutations_require_csrf_even_with_session(): void
    {
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email]]);
        // Laravel skips CSRF during unit tests; use local environment for this request.
        app()->instance('env', 'local');
        $this->actingAs($user)->postJson('/api/broker/sync')->assertStatus(419);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
    }

    public function test_local_login_and_logout_manage_session(): void
    {
        $user = User::factory()->create();
        $this->get('/login')->assertOk()->assertSee('_token');
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_fresh_scheduler_has_no_jobs_and_integrations_are_disabled(): void
    {
        $this->assertCount(0, Schedule::events());
        $this->assertFalse(config('mcp.monitoring_enabled'));
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        $this->assertFalse(config('broker.coinbase.writes_enabled'));
        $this->assertFalse(config('broker.robinhood.writes_enabled'));
    }

    public function test_broker_clients_refuse_writes_before_http_or_signing(): void
    {
        foreach ([
            CoinbaseClient::class => 'Coinbase writes are disabled.',
            RobinhoodClient::class => 'Robinhood Crypto writes are disabled.',
        ] as $client => $message) {
            try {
                app($client)->placeOrder(new BrokerCredential, []);
                $this->fail('Client accepted disabled write');
            } catch (\RuntimeException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_readiness_failure_does_not_disclose_connection_or_exception(): void
    {
        $original = config('database.default');
        try {
            config(['database.default' => 'sqlite']);
            $this->getJson('/ready')->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        } finally {
            config(['database.default' => $original]);
        }
    }
}
