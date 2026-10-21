<?php

namespace Tests\Feature\PaperTrading;

use App\Jobs\RunStrategyExperiment;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\PaperTrading\StrategyExperiment;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\Experiments\StrategyExperiments;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\ExperimentFixture as F;
use Tests\TestCase;

class ExperimentInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21T14:00:00Z'));
        F::enable();
        Queue::fake();
    }

    private function form($connection, array $changes = []): array
    {
        return array_replace(['request_id' => (string) Str::uuid(), 'account_scope' => AccountScope::key($connection), 'auth_generation' => $connection->auth_generation,
            'name' => 'Synthetic interface comparison', 'hypothesis' => 'Compare affordability using identical historical data.', 'symbols' => 'DEMO',
            'sessions' => "2026-10-19\n2026-10-20", 'baseline_index' => '0', 'variants' => [
                ['id' => 'baseline', 'equity' => '500', 'parameters' => ['time_exit_sessions' => '20']],
                ['id' => 'capital', 'equity' => '10000', 'parameters' => ['initial_stop_atr' => '3']],
            ]], $changes);
    }

    private function completed(): array
    {
        [$c, $p] = E::seed();
        F::sources();
        $run = app(StrategyExperiments::class)->start($c, $c->user_id, (string) Str::uuid(), F::spec(['name' => '<script>synthetic()</script>']));
        for ($i = 0; $i < 5; $i++) {
            app(StrategyExperiments::class)->work($run->id);
        }
        $this->assertSame('complete', $run->fresh()->status, $run->fresh()->reason ?? '');

        return [$c, $p, $run->fresh()];
    }

    public function test_authentication_csrf_and_unbound_operator_are_enforced(): void
    {
        $this->get('/mcp/experiments')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/mcp/experiments')->assertForbidden();
        [$c] = E::seed();
        $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/experiments')->assertOk()->assertSee('Queue historical experiment')->assertHeader('Cache-Control', 'no-store, private');
        app()->instance('env', 'local');
        $this->post('/mcp/experiments', $this->form($c))->assertStatus(419);
        $this->post('/mcp/experiments/'.Str::uuid().'/resume', $this->form($c))->assertStatus(419);
        app()->instance('env', 'testing');
        $c->update(['bound_account_id' => null]);
        $this->get('/mcp/experiments')->assertOk()->assertSee('Connect an agentic account')->assertDontSee('name="variants[0][equity]"', false);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_guided_form_queues_once_with_server_identity_and_exact_string_values(): void
    {
        [$c, $p] = E::seed();
        $this->actingAs(User::findOrFail($c->user_id));
        $form = $this->form($c, ['actor_id' => 999, 'brokerage_writes_enabled' => true]);
        $response = $this->post('/mcp/experiments', $form);
        $run = StrategyExperiment::sole();
        $response->assertRedirect('/mcp/experiments/'.$run->experiment_id);
        $this->assertSame($c->user_id, $run->actor_id);
        $this->assertSame('500', $run->definition['spec']['variants'][0]['equity']);
        $this->assertSame(20, $run->definition['spec']['variants'][0]['parameters']['time_exit_sessions']);
        $this->post('/mcp/experiments', $form)->assertRedirect('/mcp/experiments/'.$run->experiment_id);
        Queue::assertPushed(RunStrategyExperiment::class, 1);
        $this->assertSame($p->cash, $p->fresh()->cash);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        Http::assertNothingSent();
        $this->get('/mcp/experiments/'.$run->experiment_id)->assertOk()->assertSee('This variant has not completed')->assertSee('Resume unfinished work');
    }

    public function test_bad_scope_invalid_override_and_disabled_gate_create_no_experiments(): void
    {
        [$c] = E::seed();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post('/mcp/experiments', $this->form($c, ['account_scope' => str_repeat('0', 64)]))->assertStatus(409);
        $this->post('/mcp/experiments', $this->form($c, ['auth_generation' => $c->auth_generation + 1]))->assertStatus(409);
        $form = $this->form($c);
        $form['variants'][0]['parameters']['risk_per_trade_pct'] = '50';
        $this->post('/mcp/experiments', $form)->assertSessionHasErrors();
        config(['strategy_experiments.enabled' => false]);
        $this->from('/mcp/experiments')->post('/mcp/experiments', $this->form($c))->assertRedirect('/mcp/experiments')->assertSessionHasErrors('experiment');
        $this->get('/mcp/experiments')->assertSee('Historical runs are disabled');
        $this->assertSame(0, StrategyExperiment::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_verified_variants_render_scoped_trade_prices_stops_and_reasons_without_further_http(): void
    {
        [$c, $p, $run] = $this->completed();
        $this->actingAs(User::findOrFail($c->user_id));
        $count = count(Http::recorded());
        $response = $this->get('/mcp/experiments/'.$run->experiment_id.'?variant=capital');
        $response->assertOk()->assertSee('Simulated trades and open positions')->assertSee('DEMO')->assertSee('Initial stop')->assertSee('Trailing stop')
            ->assertSee('Breakout trigger crossed')->assertSee('Quantity-weighted average')->assertSee('Position still open')->assertSee('There is no fixed take-profit target')
            ->assertSee('&lt;script&gt;synthetic()&lt;/script&gt;', false)->assertDontSee('<script>synthetic()</script>', false);
        if ($directory = getenv('MCP_EXPERIMENT_UI_PREVIEW_DIR')) {
            $this->app->forgetInstance(Vite::class);
            $prefix = url('/');
            $capture = fn ($html) => str_replace([$prefix, '&lt;script&gt;synthetic()&lt;/script&gt;'], ['http://127.0.0.1:8098', 'DEMO · synthetic historical comparison'], $html);
            file_put_contents($directory.'/results.html', $capture($this->get('/mcp/experiments/'.$run->experiment_id.'?variant=capital')->getContent()));
            file_put_contents($directory.'/index.html', $capture($this->get('/mcp/experiments')->getContent()));
        }
        $this->get('/mcp/experiments/'.$run->experiment_id.'?variant=absent')->assertNotFound();
        $this->get('/mcp/experiments/'.$run->experiment_id.'/report')->assertOk()->assertJsonPath('status', 'complete')->assertHeader('Cache-Control', 'no-store, private');
        config(['strategy_experiments.enabled' => false]);
        $this->get('/mcp/experiments/'.$run->experiment_id.'?variant=capital')->assertOk();
        $this->assertCount($count, Http::recorded());
        $this->assertSame($p->cash, $p->fresh()->cash);
        $this->assertSame(0, SimulationOrder::count());
    }

    public function test_another_operator_cannot_read_or_resume_a_foreign_experiment(): void
    {
        [$owner, , $run] = $this->completed();
        [$other] = E::seed();
        config(['mcp.operator_emails' => [User::findOrFail($owner->user_id)->email, User::findOrFail($other->user_id)->email]]);
        $this->actingAs(User::findOrFail($other->user_id));
        $this->get('/mcp/experiments')->assertOk()->assertDontSee($run->experiment_id);
        $this->get('/mcp/experiments/'.$run->experiment_id)->assertNotFound();
        $this->get('/mcp/experiments/'.$run->experiment_id.'/report')->assertNotFound();
        $this->post('/mcp/experiments/'.$run->experiment_id.'/resume', $this->form($other))->assertNotFound();
    }

    public function test_resume_uses_existing_checkpoints_and_rejects_active_leases(): void
    {
        [$c] = E::seed();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post('/mcp/experiments', $this->form($c));
        $run = StrategyExperiment::sole();
        $run->update(['status' => 'failed', 'reason' => 'historical_quote_rate_limited']);
        $this->post('/mcp/experiments/'.$run->experiment_id.'/resume', $this->form($c))->assertRedirect('/mcp/experiments/'.$run->experiment_id);
        $this->assertSame('queued', $run->fresh()->status);
        $run->refresh()->update(['status' => 'running', 'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(5)]);
        $this->from('/mcp/experiments/'.$run->experiment_id)->post('/mcp/experiments/'.$run->experiment_id.'/resume', $this->form($c))->assertSessionHasErrors('experiment');
        $this->get('/mcp/experiments/'.$run->experiment_id)->assertOk()->assertSee('active lease')->assertDontSee('Resume unfinished work');
        Http::assertNothingSent();
    }

    public function test_reuse_prefills_a_new_form_and_submits_through_the_existing_runner(): void
    {
        [$c, , $run] = $this->completed();
        $this->actingAs(User::findOrFail($c->user_id));
        $count = count(Http::recorded());
        $this->get('/mcp/experiments?reuse='.$run->experiment_id)->assertOk()->assertSee('follow-up')->assertSee($run->experiment_id);
        $form = $this->form($c, ['dataset_experiment' => $run->experiment_id]);
        $this->post('/mcp/experiments', $form)->assertRedirect();
        $next = StrategyExperiment::latest('id')->first();
        $this->assertNotSame($run->id, $next->id);
        for ($i = 0; $i < 5; $i++) {
            app(StrategyExperiments::class)->work($next->id);
        }
        $this->get('/mcp/experiments/'.$next->experiment_id)->assertOk()->assertSee('Comparison with the previous experiment');
        $this->assertCount($count, Http::recorded());
    }

    public function test_rule_switches_are_submitted_as_typed_overrides_and_invalid_choices_are_refused(): void
    {
        [$c] = E::seed();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get('/mcp/experiments')->assertOk()->assertSee('Sell on a close below the 50-day average')->assertSee('Market filter average (sessions)');
        $bad = $this->form($c);
        $bad['variants'][1]['parameters']['market_filter_required'] = 'maybe';
        $this->post('/mcp/experiments', $bad)->assertSessionHasErrors('variants.1.parameters.market_filter_required');
        $form = $this->form($c);
        $form['variants'][1]['parameters'] += ['exit_on_fast_sma_close' => 'false', 'market_filter_required' => '', 'market_filter_sma_sessions' => '150'];
        $this->post('/mcp/experiments', $form)->assertRedirect();
        $this->assertSame(['initial_stop_atr' => '3', 'exit_on_fast_sma_close' => false, 'market_filter_sma_sessions' => 150],
            StrategyExperiment::sole()->definition['spec']['variants'][1]['parameters']);
        Http::assertNothingSent();
    }
}
