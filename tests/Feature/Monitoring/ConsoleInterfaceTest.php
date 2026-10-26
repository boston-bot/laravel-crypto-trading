<?php

namespace Tests\Feature\Monitoring;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\Broker\Agentic\BrokerGateway;
use App\Services\Monitoring\Monitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\McpFixture;
use Tests\Support\MonitoringGateway;
use Tests\TestCase;

class ConsoleInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/mcp/dashboard/overview', '/mcp/dashboard/holdings', '/mcp/dashboard/research', '/mcp/dashboard/orders',
        '/mcp/dashboard/performance', '/mcp/dashboard/control', '/mcp/dashboard/health', '/mcp/robinhood'];

    /** Existing operator actions only. A new browser mutation must be reviewed and added here deliberately. */
    private const ALLOWED_ACTIONS = ['POST' => ['logout', 'mcp.dashboard.sync', 'mcp.incident.ack', 'mcp.research.evidence', 'mcp.research.request', 'mcp.research.watch',
        'mcp.research.unwatch', 'mcp.research.adopt', 'mcp.thesis.store', 'mcp.operations.phone', 'mcp.operations.health',
        'mcp.operations.test-alert', 'mcp.operations.summary', 'mcp.operations.database', 'mcp.control.store', 'mcp.control.watchlist', 'mcp.control.eligibility.apply', 'mcp.control.eligibility.assess', 'mcp.control.external-order.review', 'mcp.control.proposal', 'mcp.control.restore', 'mcp.control.apply',
        'mcp.live.policy', 'mcp.live.controls', 'mcp.live.link', 'mcp.live.absent', 'mcp.live.review',
        'mcp.live.automatic.start', 'mcp.live.automatic.stop', 'mcp.live.buys.pause',
        'mcp.paper.execution.store', 'mcp.paper.execution.apply', 'mcp.paper.market.collect', 'mcp.paper.handoff', 'mcp.connect', 'mcp.renew', 'mcp.discover', 'mcp.accounts', 'mcp.bind'],
        'GET' => ['mcp.read', 'mcp.watchlist.read']];

    public function test_every_console_page_renders_without_a_connection_and_offers_no_account_actions(): void
    {
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email]]);
        foreach (self::PAGES as $page) {
            $this->actingAs($user)->get($page)->assertOk()->assertSee('Account unbound')->assertSee('Brokerage writes are disabled in configuration')
                ->assertDontSee(route('mcp.dashboard.sync'))->assertDontSee('/acknowledge');
        }
        $this->get('/mcp/dashboard/research')->assertSee('Bind an account before recording research');
    }

    public function test_populated_pages_protect_every_form_and_expose_only_reviewed_actions(): void
    {
        [$user, $connection] = $this->populated();
        $seen = [];
        foreach (self::PAGES as $page) {
            $html = $this->actingAs($user)->get($page)->assertOk()->assertDontSee('synthetic-agentic')->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            foreach ($dom->getElementsByTagName('form') as $form) {
                $method = strtoupper($form->getAttribute('method') ?: 'GET');
                $action = parse_url($form->getAttribute('action'), PHP_URL_PATH);
                $route = Route::getRoutes()->match(Request::create($action, $method));
                $name = $route->getName() ?? $route->uri();
                $this->assertContains($name, self::ALLOWED_ACTIONS[$method], "$page exposes unreviewed $method $action");
                $seen[$name] = true;
                if ($method === 'POST') {
                    $token = (new \DOMXPath($dom))->query('.//input[@name="_token"]', $form);
                    $this->assertSame(1, $token->length, "$page form $action lacks a CSRF token");
                }
            }
        }
        // The redesign keeps the core controls reachable.
        foreach (['mcp.dashboard.sync', 'mcp.incident.ack', 'mcp.research.watch', 'mcp.research.request', 'mcp.thesis.store', 'mcp.connect', 'mcp.read', 'logout', 'mcp.live.controls', 'mcp.live.policy'] as $expected) {
            $this->assertArrayHasKey($expected, $seen);
        }
        $this->assertNotEmpty($connection->bound_account_id);
    }

    public function test_header_reflects_configuration_and_incidents_on_every_page(): void
    {
        [$user] = $this->populated();
        $this->actingAs($user)->get('/mcp/robinhood')->assertSee('Agentic monitoring')->assertSee('…ntic')
            ->assertSee('Monitoring off')->assertSee('nav-badge', false);
        config(['mcp.brokerage_writes_enabled' => true, 'mcp.monitoring_enabled' => true]);
        // The header badge follows configuration; the observed execution_disabled pause reason is separate evidence.
        $this->get('/mcp/dashboard')->assertSee('Brokerage writes enabled')->assertDontSee('Brokerage writes are disabled in configuration')->assertSee('Monitoring on');
    }

    public function test_money_is_rounded_for_display_with_the_exact_value_kept(): void
    {
        [$user] = $this->populated();
        $this->actingAs($user)->get('/mcp/dashboard')->assertSee('$1,000.00')->assertSee('title="Exact: 1000.00"', false);
        $this->get('/mcp/dashboard/holdings')->assertSee('2.000000000000000001')->assertSee('$400.00');
    }

    public function test_sign_in_page_is_styled_but_minimal(): void
    {
        $this->get('/login')->assertOk()->assertSee('Local operator sign in')->assertSee('_token')->assertDontSee('Sync account');
    }

    /** @return array{User, McpConnection} */
    private function populated(): array
    {
        $this->app->instance(BrokerGateway::class, new MonitoringGateway);
        $connection = McpFixture::connection();
        $user = User::findOrFail($connection->user_id);
        config(['mcp.operator_emails' => [$user->email]]);
        app(Monitor::class)->sync($connection->id, 'console');

        return [$user, $connection];
    }
}
