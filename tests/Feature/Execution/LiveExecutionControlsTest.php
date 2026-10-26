<?php

namespace Tests\Feature\Execution;

use App\Models\Execution\ExecutionPolicy;
use App\Models\Monitoring\ExternalOrder;
use App\Models\Monitoring\ExternalOrderReview;
use App\Models\Monitoring\Incident;
use App\Models\User;
use App\Services\Execution\BrokerOutcomes;
use App\Services\Execution\ExecutionControls;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\IncidentRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\TestCase;

/** Owner forms for the live boundary. No order is sent and brokerage writes stay disabled throughout. */
class LiveExecutionControlsTest extends TestCase
{
    use RefreshDatabase;

    private LiveBrokerGateway $broker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    private function form(array $live = []): array
    {
        return array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (string) $v, $live + F::POLICY);
    }

    public function test_policy_form_is_authenticated_operator_scoped_csrf_protected_idempotent_and_enables_nothing(): void
    {
        $this->broker = new LiveBrokerGateway;
        [$c] = F::seed($this->broker);
        $scope = AccountScope::key($c);
        $url = route('mcp.live.policy');
        $body = ['account_scope' => $scope, 'base_version' => 1, 'request_id' => (string) Str::uuid(), 'confirm_live_policy' => '1',
            'live' => $this->form(['sleeve_capital' => '800.00', 'max_order_notional' => '150.00'])];
        $this->post($url, $body)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->post($url, $body)->assertForbidden();
        $this->actingAs(User::findOrFail($c->user_id));
        $this->post($url, array_replace($body, ['account_scope' => str_repeat('0', 64)]))->assertConflict();
        $this->postJson($url, array_replace($body, ['confirm_live_policy' => null]))->assertUnprocessable();
        $this->postJson($url, array_replace_recursive($body, ['live' => ['max_position_pct' => '25']]))->assertUnprocessable();
        $this->postJson($url, array_replace_recursive($body, ['live' => ['brokerage_writes_enabled' => '1']]))->assertUnprocessable();
        $this->post($url, $body)->assertRedirect(route('mcp.dashboard', ['page' => 'control']));
        $this->post($url, $body)->assertRedirect();
        $this->assertSame(2, ExecutionPolicy::count());
        $latest = ExecutionPolicy::latest('version')->first();
        $this->assertSame(['800.00', true, 4], [$latest->payload['sleeve_capital'], $latest->payload['allow_fractional'], $latest->payload['max_open_positions']]);
        // An unchecked fractional box is an explicit false, not a missing setting.
        $unchecked = $this->form();
        unset($unchecked['allow_fractional']);
        $this->post($url, ['account_scope' => $scope, 'base_version' => 2, 'request_id' => (string) Str::uuid(), 'confirm_live_policy' => '1', 'live' => $unchecked])->assertRedirect();
        $this->assertFalse(ExecutionPolicy::latest('version')->first()->payload['allow_fractional']);
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Live execution boundary')->assertSee('Version 3')->assertSee('Brokerage writes disabled');
        app()->instance('env', 'local');
        $this->post($url, $body)->assertStatus(419);
    }

    public function test_emergency_disable_and_buy_pause_forms_record_append_only_controls(): void
    {
        $this->broker = new LiveBrokerGateway;
        [$c] = F::seed($this->broker);
        $scope = AccountScope::key($c);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Engage emergency disable');
        $engage = ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'buy_paused' => '0', 'emergency_disabled' => '1', 'reason' => 'Emergency disable from the Control Center.'];
        $this->post(route('mcp.live.controls'), $engage)->assertRedirect();
        $this->post(route('mcp.live.controls'), $engage)->assertRedirect();
        $this->assertSame(['buy_paused' => false, 'emergency_disabled' => true, 'version' => 1], ExecutionControls::state($scope));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Emergency disable engaged')->assertDontSee('Engage emergency disable');
        $this->postJson(route('mcp.live.controls'), ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'buy_paused' => '1', 'emergency_disabled' => '0'])->assertUnprocessable();
        $this->post(route('mcp.live.controls'), ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'buy_paused' => '1', 'emergency_disabled' => '0', 'reason' => 'Drill complete; entries paused.'])->assertRedirect();
        $this->assertSame(['buy_paused' => true, 'emergency_disabled' => false, 'version' => 2], ExecutionControls::state($scope));
        $this->actingAs(User::factory()->create())->post(route('mcp.live.controls'), $engage + ['request_id' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_unknown_outcome_forms_link_the_matching_order_or_refuse_absence_without_complete_history(): void
    {
        $this->broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($this->broker);
        $scope = AccountScope::key($c);
        $intent = F::stage($c, $signal);
        // Simulate the committed claim and a lost response through the permitted transitions (claims need PostgreSQL and enabled writes).
        DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'submitting', 'version' => 2, 'claim_token' => (string) Str::uuid(), 'claimed_at' => now()]);
        DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'unknown', 'version' => 3]);
        $this->travel(1)->seconds();
        $this->broker->orders = [LiveBrokerGateway::order('rh-lost', 'filled', '1', '1', [['exec-lost', '1', '101.70']]), LiveBrokerGateway::order('rh-sell', 'filled', '1', '1', [['exec-sell', '1', '99.00']], 'DEMO', 'sell')];
        F::sync($c, $this->broker);
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Unknown outcome · intent #'.$intent->id)->assertSee('rh-lost')->assertSee('Confirm no broker order');
        $this->post(route('mcp.live.absent', $intent->id), ['account_scope' => $scope, 'confirm_absent' => '1'])->assertConflict();
        $sell = ExternalOrder::where('broker_id_hash', hash('sha256', 'rh-sell'))->sole();
        $lost = ExternalOrder::where('broker_id_hash', hash('sha256', 'rh-lost'))->sole();
        $link = ['account_scope' => $scope, 'request_id' => (string) Str::uuid(), 'note' => 'Matches the lost acknowledgement.', 'confirm_link' => '1'];
        $this->post(route('mcp.live.link', $intent->id), $link + ['external_order_id' => $sell->id])->assertConflict();
        $this->post(route('mcp.live.link', $intent->id), $link + ['external_order_id' => $lost->id])->assertRedirect();
        $this->assertSame('accepted', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
        $this->assertSame($c->user_id, ExternalOrderReview::where('external_order_id', $lost->id)->sole()->actor_id);
        $this->get('/mcp/dashboard/control')->assertOk()->assertDontSee('Unknown outcome · intent #'.$intent->id)->assertSee('accepted');
    }

    public function test_reconciliation_incident_review_form_resolves_and_reports_the_block(): void
    {
        $this->broker = new LiveBrokerGateway;
        [$c] = F::seed($this->broker);
        app(IncidentRecorder::class)->record($c, BrokerOutcomes::MISMATCH, 'critical', 'execution');
        $this->actingAs(User::findOrFail($c->user_id));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Reconciliation review required');
        $body = ['account_scope' => AccountScope::key($c), 'incident_key' => BrokerOutcomes::MISMATCH];
        $this->postJson(route('mcp.live.review'), $body)->assertUnprocessable();
        $this->postJson(route('mcp.live.review'), ['incident_key' => 'external_orders_review_required', 'confirm_reconciliation' => '1'] + $body)->assertUnprocessable();
        $this->post(route('mcp.live.review'), $body + ['confirm_reconciliation' => '1'])->assertRedirect();
        $this->assertSame('resolved', Incident::where('incident_key', BrokerOutcomes::MISMATCH)->value('state'));
        $this->get('/mcp/dashboard/control')->assertOk()->assertDontSee('Reconciliation review required');
    }
}
