<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SessionValuation;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\SimulationPosition;
use App\Models\PaperTrading\StrategySignal;
use App\Models\User;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\PaperSessionValuations;
use App\Services\PaperTrading\RealDataPaperFills;
use App\Services\PaperTrading\SignalPaperHandoff;
use App\Services\PaperTrading\StrategySignals;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\SignalExecutionFixture as F;
use Tests\TestCase;

class PaperDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    public function test_authentication_operator_and_current_binding_scope_are_required(): void
    {
        $this->get('/mcp/paper')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/mcp/paper')->assertForbidden();
        [$c, $p] = E::seed();
        $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/paper')->assertOk()->assertSee('Paper Trading')->assertHeader('Cache-Control', 'no-store, private');
        $c->update(['bound_account_id' => 'different-binding']);
        $this->get('/mcp/paper?account='.$c->id.'&portfolio='.$p->id)->assertOk()->assertSee('No paper portfolio')->assertDontSee('Current paper equity');
        $c->update(['bound_account_id' => null]);
        $this->get('/mcp/paper')->assertOk()->assertSee('No paper portfolio');
        Http::assertNothingSent();
    }

    public function test_cash_only_portfolio_is_exact_and_get_has_no_collection_evaluation_or_ledger_effect(): void
    {
        [$c, $p] = E::seed();
        $before = $p->getAttributes();
        $rows = SimulationOrder::count();
        $signals = StrategySignal::count();
        $response = $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/paper');
        $response->assertOk()->assertSee('No verified opening valuations yet')->assertSee('No paper orders accepted');
        $data = $response->viewData('data');
        $this->assertSame($p->cash, $data['current']['cash']);
        $this->assertSame($p->cash, $data['current']['equity']);
        $this->assertSame('0', $data['current']['unrealized']);
        $this->assertSame($before, $p->fresh()->getAttributes());
        $this->assertSame($rows, SimulationOrder::count());
        $this->assertSame($signals, StrategySignal::count());
        Http::assertNothingSent();
        $this->preview('empty', '/mcp/paper');
    }

    private function filled(): array
    {
        [$c, $p] = F::seed();
        $signal = app(StrategySignals::class)->record($c, $c->user_id)->keyBy('symbol')['DEMO'];
        $handoff = app(SignalPaperHandoff::class)->process($c, $c->user_id, $signal->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:01Z'));
        F::quote($c, '101.50', '101.60', '2');
        app(RealDataPaperFills::class)->process($c, $c->user_id, $handoff->sim_order_id);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:02Z'));
        F::quote($c, '101.51', '101.61', '100');
        app(RealDataPaperFills::class)->process($c, $c->user_id, $handoff->sim_order_id);

        return [$c, $p->fresh(), SimulationOrder::findOrFail($handoff->sim_order_id)];
    }

    public function test_filled_prices_positions_blockers_and_scoped_readiness_render_without_network(): void
    {
        [$c, $p, $order] = $this->filled();
        $sent = count(Http::recorded());
        $orders = SimulationOrder::count();
        $events = SimulationEvent::count();
        $response = $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/paper?symbol=DEMO');
        $response->assertOk()->assertSee('DEMO')->assertSee('Forward paper')->assertSee('Deterministic')->assertSee('2 recorded fills');
        $data = $response->viewData('data');
        $row = $data['orders']->first();
        $this->assertSame($order->id, $row['order']->id);
        $this->assertSame('19.000000000000000000', $row['quantity']);
        $this->assertSame('101.619108263157894737', $row['average']);
        $this->assertSame($p->cash, $data['current']['cash']);
        $this->assertNotNull($data['current']['equity']);
        $this->assertSame('101.51', $data['positions']->first()['mark']);
        $this->assertSame(1, $data['nominees']);
        $this->assertCount($sent, Http::recorded());
        $this->assertSame($orders, SimulationOrder::count());
        $this->assertSame($events, SimulationEvent::count());
        $this->preview('filled', '/mcp/paper?symbol=DEMO');
        // Untrusted rationale must be escaped even if old encrypted data contains markup.
        $intent = $order->intent;
        $intent['rationale'] = '<script>synthetic()</script>';
        DB::table('mcp_paper_sim_orders')->where('id', $order->id)->update(['intent' => Crypt::encryptString(json_encode($intent))]);
        $this->get('/mcp/paper')->assertOk()->assertSee('<script>synthetic()</script>')->assertDontSee('<script>synthetic()</script>', false);
    }

    public function test_stale_or_blocked_positions_leave_current_equity_unknown_without_cost_basis_fallback(): void
    {
        [$c] = $this->filled();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:01:03Z'));
        $response = $this->get('/mcp/paper');
        $this->assertSame('2026-10-19T14:01:03.000000Z', $response->viewData('asOf')->toISOString());
        $this->assertNull($response->viewData('data')['positions']->first()['mark'], json_encode($response->viewData('data')['positions']));
        $response->assertOk()->assertSee('Current valuation is incomplete');
        $this->assertNull($response->viewData('data')['current']['equity']);
        $this->assertNull($response->viewData('data')['positions']->first()['value']);
        F::quote($c);
        SimulationPosition::sole()->update(['blocked_reason' => 'action_requires_review']);
        $blocked = $this->get('/mcp/paper');
        $this->assertNull($blocked->viewData('data')['current']['equity']);
        $blocked->assertSee('Action requires review');
    }

    private function valuation($c, $p, string $date, string $equity): void
    {
        $payload = ['cash' => $equity, 'session_date' => $date, 'mode' => 'synthetic_ui_fixture'];
        SessionValuation::create(['paper_portfolio_id' => $p->id, 'auth_generation' => $c->auth_generation, 'session_date' => $date,
            'actor_id' => $c->user_id, 'rule_version' => PaperSessionValuations::VERSION, 'equity' => $equity,
            'input_fingerprint' => hash('sha256', json_encode($payload)), 'payload' => $payload,
            'cutoff' => $date.'T13:30:30Z', 'recorded_at' => $date.'T13:30:30Z']);
    }

    public function test_chart_uses_only_verified_stored_openings_and_preserves_missing_sessions(): void
    {
        [$c, $p] = F::seed();
        $this->valuation($c, $p, '2026-10-19', '10000');
        foreach (['2026-10-20', '2026-10-21'] as $date) {
            app(MarketCalendar::class)->store($date, 'regular', $date.'T13:30:00Z', $date.'T20:00:00Z', 'synthetic_ui_fixture');
        }
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        $this->valuation($c, $p, '2026-10-21', '10020.125000000000000000');
        $response = $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/paper?date=2026-10-21');
        $response->assertOk()->assertSee('2 known')->assertSee('1 missing');
        $chart = $response->viewData('data')['chart'];
        $this->assertCount(2, $chart['segments']);
        $this->assertNull($chart['series'][1]['equity']);
        $this->assertSame('10020.125000000000000000', $chart['max']);
        $this->preview('chart', '/mcp/paper?date=2026-10-21');
        $c->increment('auth_generation');
        $this->get('/mcp/paper?date=2026-10-21')->assertOk()->assertSee('No verified opening valuations yet');
    }

    public function test_a_second_authorized_operator_cannot_select_another_operators_portfolio(): void
    {
        [$first] = $this->filled();
        $firstEmail = User::findOrFail($first->user_id)->email;
        [$second] = E::seed();
        config(['mcp.operator_emails' => [$firstEmail, User::findOrFail($second->user_id)->email]]);
        $response = $this->actingAs(User::findOrFail($second->user_id))->get('/mcp/paper?account='.$first->id);
        $response->assertOk();
        $data = $response->viewData('data');
        $this->assertSame($second->id, $data['portfolio']->mcp_connection_id);
        $this->assertSame(0, $data['orders']->total());
        $this->assertSame(0, $data['evaluations']);
        $this->assertCount(0, $data['positions']);
    }

    public function test_evaluation_pagination_preserves_filters_and_does_not_run_another_cycle(): void
    {
        [$c] = F::seed();
        for ($i = 0; $i < 22; $i++) {
            $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z')->addMinutes($i * 5));
            app(StrategySignals::class)->record($c, $c->user_id);
        }
        $count = StrategySignal::count();
        $response = $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/paper?symbol=SYNTH&date=2026-10-19&signals_page=1');
        $response->assertOk()->assertSee('signals_page=2');
        $this->assertCount(20, $response->viewData('data')['signals']);
        $this->assertSame(22, $response->viewData('data')['signals']->total());
        $second = $this->get('/mcp/paper?symbol=SYNTH&date=2026-10-19&signals_page=2');
        $this->assertCount(2, $second->viewData('data')['signals']);
        $this->assertSame($count, StrategySignal::count());
    }

    public function test_filters_validate_and_history_is_paginated_and_current_authorization_scoped(): void
    {
        [$c] = F::seed();
        app(StrategySignals::class)->record($c, $c->user_id);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get('/mcp/paper?symbol=NOPE')->assertOk()->assertSee('No evaluations in this session for this ticker filter');
        $this->get('/mcp/paper?state=filled')->assertOk();
        foreach (['date' => 'tomorrow', 'symbol' => '<script>', 'state' => 'bogus', 'signals_page' => '999999999999999999', 'orders_page' => '-1', 'readiness_page' => 'x'] as $key => $value) {
            $this->getJson('/mcp/paper?'.http_build_query([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $c->increment('auth_generation');
        $response = $this->get('/mcp/paper');
        $this->assertSame(0, $response->viewData('data')['evaluations']);
    }

    private function preview(string $name, string $url): void
    {
        if ($directory = getenv('MCP_PAPER_DASHBOARD_PREVIEW_DIR')) {
            // Optional static visual fixture export. Only synthetic test data; never a runtime session.
            $this->app->forgetInstance(Vite::class);
            $html = $this->get($url)->assertOk()->getContent();
            $html = str_replace(config('app.url'), 'http://127.0.0.1:8099', $html);
            file_put_contents($directory.'/'.$name.'.html', $html);
        }
    }
}
