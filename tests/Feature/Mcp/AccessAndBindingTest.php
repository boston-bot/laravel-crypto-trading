<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Services\Broker\Agentic\AccountBinding;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Broker\Agentic\BrokerRecord;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\ReadResult;
use App\Services\Broker\Agentic\TrustedHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\Support\McpFixture;
use Tests\TestCase;

class AccessAndBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mcp_route_requires_operator_session_and_mutations_require_csrf(): void
    {
        $user = User::factory()->create();
        foreach (['GET' => ['/mcp/robinhood', '/mcp/robinhood/callback', '/mcp/robinhood/reads/positions'],
            'POST' => ['/mcp/robinhood/connect', '/mcp/robinhood/renew', '/mcp/robinhood/discover', '/mcp/robinhood/accounts', '/mcp/robinhood/bind']] as $method => $routes) {
            foreach ($routes as $uri) {
                $this->json($method, $uri)->assertUnauthorized();
                $this->actingAs($user)->json($method, $uri)->assertForbidden();
                auth()->forgetGuards();
            }
        }
        config(['mcp.operator_emails' => [$user->email]]);
        app()->instance('env', 'local');
        $this->actingAs($user)->postJson('/mcp/robinhood/connect')->assertStatus(419);
    }

    public function test_operator_cannot_access_another_operators_connection_or_mutation_tools(): void
    {
        $user = User::factory()->create();
        McpFixture::connection();
        config(['mcp.operator_emails' => [$user->email]]);
        $this->actingAs($user)->get('/mcp/robinhood')->assertOk()->assertSee('not connected');
        $this->getJson('/mcp/robinhood/reads/positions')->assertNotFound();
        $this->getJson('/mcp/robinhood/reads/submit')->assertNotFound();
    }

    public function test_binding_revalidates_complete_accounts_and_server_actor(): void
    {
        $user = User::factory()->create();
        $connection = McpFixture::connection($user);
        config(['mcp.operator_emails' => [$user->email]]);
        $gateway = Mockery::mock(BrokerGateway::class);
        $gateway->shouldReceive('read')->once()->andReturn(new ReadResult(TrustedHttp::RESOURCE, new \DateTimeImmutable, [BrokerRecord::from(Operation::Accounts,
            ['id' => 'synthetic-personal', 'account_kind' => 'personal'], null)], true));
        $this->app->instance(BrokerGateway::class, $gateway);
        $this->actingAs($user)->post('/mcp/robinhood/bind', ['selection' => Crypt::encryptString('synthetic-personal'), 'role' => 'personal_advisory', 'actor' => 'someone-else'])->assertRedirect('/mcp/robinhood');
        $this->assertSame('personal_advisory', $connection->fresh()->account_role);
        $this->assertSame($user->id, $connection->fresh()->bound_by);
    }

    public function test_personal_account_cannot_be_bound_as_agentic_and_partial_accounts_cannot_bind(): void
    {
        $connection = McpFixture::connection();
        foreach ([true, false] as $complete) {
            $gateway = Mockery::mock(BrokerGateway::class);
            $gateway->shouldReceive('read')->andReturn(new ReadResult(TrustedHttp::RESOURCE, new \DateTimeImmutable,
                [BrokerRecord::from(Operation::Accounts, ['id' => 'synthetic-personal', 'account_kind' => 'personal'], null)], $complete));
            try {
                (new AccountBinding($gateway))->bind($connection, 'synthetic-personal', 'agentic_monitoring', $connection->user_id);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame($complete ? ReadError::AccountMismatch : ReadError::Incomplete, $failure->reason);
            }
        }
    }
}
