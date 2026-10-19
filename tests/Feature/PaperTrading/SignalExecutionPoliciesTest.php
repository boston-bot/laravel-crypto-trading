<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\SignalExecutionPolicy;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\SignalExecutionPolicies;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\StrategySignalFixture as F;
use Tests\TestCase;

class SignalExecutionPoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    public function test_application_is_explicit_scoped_versioned_immutable_and_does_not_change_balances_or_gates(): void
    {
        [$c, $p, , $execution] = F::seed();
        $url = '/mcp/paper/signal-execution/apply';
        $body = ['account_scope' => AccountScope::key($c), 'base_version' => 0, 'request_id' => (string) Str::uuid(),
            'execution_version_id' => $execution->id, 'confirm_signal_paper' => '1', 'signal_execution' => SignalExecutionPolicies::DEFAULTS];
        $before = $p->fresh()->getAttributes();
        $this->postJson($url, $body)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, $body)->assertForbidden();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->postJson($url, array_replace($body, ['account_scope' => str_repeat('0', 64)]))->assertConflict();
        $this->postJson($url, array_replace($body, ['signal_execution' => ['allow_fractional' => true] + SignalExecutionPolicies::DEFAULTS]))->assertUnprocessable();
        $this->post($url, $body)->assertRedirect();
        $this->post($url, $body)->assertRedirect();
        $this->assertDatabaseCount('mcp_signal_execution_policies', 1);
        $this->assertSame($before, $p->fresh()->getAttributes());
        $this->assertFalse(config('paper.signal_handoff_enabled'));
        $this->assertFalse(config('paper.real_data_fills_enabled'));
        $this->assertStringNotContainsString('sec_per_million', DB::table('mcp_signal_execution_policies')->value('payload'));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Signal paper risk and costs');
        app()->instance('env', 'local');
        $this->post($url, $body)->assertStatus(419);
        app()->instance('env', 'testing');
        $this->expectException(\LogicException::class);
        SignalExecutionPolicy::sole()->update(['version' => 99]);
    }

    public function test_unsafe_risk_values_unquoted_money_and_unreviewed_fractional_permission_are_refused(): void
    {
        [$c] = F::seed();
        foreach ([['risk_per_trade_pct' => '1.00000001'], ['risk_per_trade_pct' => 1.0], ['max_position_pct' => '21'], ['max_open_positions' => 9],
            ['allow_fractional' => true], ['fee_bps' => '0']] as $patch) {
            try {
                app(SignalExecutionPolicies::class)->apply($c, $c->user_id, 0, (string) Str::uuid(), $patch + SignalExecutionPolicies::DEFAULTS);
                $this->fail('Unsafe settings accepted');
            } catch (HttpException|\Illuminate\Validation\ValidationException) {
                $this->assertDatabaseCount('mcp_signal_execution_policies', 0);
            }
        }
    }
}
