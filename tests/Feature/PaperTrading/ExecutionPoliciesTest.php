<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\User;
use App\Services\Broker\Agentic\RobinhoodOrderContract;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\ExecutionPolicies;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class ExecutionPoliciesTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection, $this->portfolio] = F::seed();
        $this->user = User::findOrFail($this->connection->user_id);
        Queue::fake();
    }

    private function form(array $changes = []): array
    {
        return array_replace(['account_scope' => AccountScope::key($this->connection), 'base_version' => 0,
            'request_id' => (string) Str::uuid(), 'execution_json' => json_encode(E::payload()), 'reason' => 'Synthetic owner settings.'], $changes);
    }

    public function test_browser_save_apply_is_explicit_encrypted_idempotent_and_does_not_dispatch(): void
    {
        $form = $this->form();
        $this->actingAs($this->user)->post('/mcp/paper/execution', $form)->assertRedirect();
        $this->post('/mcp/paper/execution', $form)->assertRedirect();
        $this->assertSame(1, ExecutionVersion::count());
        $this->assertNull(ExecutionPolicies::current($this->portfolio));
        $draft = ExecutionVersion::sole();
        $apply = ['confirm_paper' => '1', 'base_version' => 1, 'request_id' => (string) Str::uuid(), 'account_scope' => AccountScope::key($this->connection)];
        $this->post('/mcp/paper/execution/'.$draft->id.'/apply', $apply)->assertRedirect();
        $this->post('/mcp/paper/execution/'.$draft->id.'/apply', $apply)->assertRedirect();
        $current = ExecutionPolicies::current($this->portfolio);
        $this->assertSame(2, $current->version);
        $this->assertSame($this->user->id, $current->actor_id);
        $this->assertSame($draft->payload, $current->payload);
        $this->assertStringNotContainsString('allowed_symbols', DB::table('mcp_paper_execution_versions')->where('id', $draft->id)->value('payload'));
        $this->assertSame('10000.000000000000000000', $this->portfolio->fresh()->cash);
        $this->assertSame(0, SimulationOrder::count());
        $this->assertFalse(config('paper.handoff_enabled'));
        $this->assertFalse(config('research.enabled'));
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Paper execution settings')->assertSee('Applied execution version: 2');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_incomplete_drafts_can_be_saved_but_cannot_be_applied_and_protected_fields_are_rejected(): void
    {
        $this->actingAs($this->user)->post('/mcp/paper/execution', $this->form(['execution_json' => '{}']))->assertRedirect();
        $draft = ExecutionVersion::sole();
        $this->postJson('/mcp/paper/execution/'.$draft->id.'/apply', ['base_version' => 1, 'confirm_paper' => '1',
            'request_id' => (string) Str::uuid(), 'account_scope' => AccountScope::key($this->connection)])->assertUnprocessable();
        $this->assertNull(ExecutionPolicies::current($this->portfolio));
        $this->postJson('/mcp/paper/execution', $this->form(['base_version' => 1, 'execution_json' => json_encode(E::payload() + ['brokerage_writes_enabled' => true])]))->assertUnprocessable();
        $this->postJson('/mcp/paper/execution', $this->form(['base_version' => 1, 'execution_json' => json_encode(E::payload(['fee_per_share' => 0.01]))]))->assertUnprocessable();
        $this->postJson('/mcp/paper/execution', $this->form(['base_version' => 1, 'execution_json' => json_encode(E::payload(['allow_entries' => 'true']))]))->assertUnprocessable();
    }

    public function test_authentication_csrf_account_scope_and_server_actor_cover_all_new_mutations(): void
    {
        $this->post('/mcp/paper/execution', $this->form())->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->post('/mcp/paper/execution', $this->form())->assertForbidden();
        $this->actingAs($this->user)->post('/mcp/paper/execution', $this->form(['actor_id' => 999]))->assertRedirect();
        $this->assertSame($this->user->id, ExecutionVersion::sole()->actor_id);
        $this->post('/mcp/paper/execution', $this->form(['base_version' => 0]))->assertConflict();
        $this->post('/mcp/paper/execution', $this->form(['account_scope' => str_repeat('0', 64)]))->assertConflict();
        app()->instance('env', 'local');
        $this->post('/mcp/paper/execution', $this->form())->assertStatus(419);
        $this->post('/mcp/paper/market-input', ['symbol' => 'DEMO'] + $this->form())->assertStatus(419);
        $this->post('/mcp/paper/decisions/'.Str::uuid().'/orders', ['confirm_paper' => '1'] + $this->form())->assertStatus(419);
    }

    public function test_foreign_drafts_and_changed_allocation_policies_cannot_be_applied(): void
    {
        $form = $this->form();
        $this->actingAs($this->user)->post('/mcp/paper/execution', $form)->assertRedirect();
        $draft = ExecutionVersion::sole();
        $this->portfolio->increment('policy_revision');
        $this->post('/mcp/paper/execution/'.$draft->id.'/apply', ['confirm_paper' => '1', 'base_version' => 1,
            'request_id' => (string) Str::uuid(), 'account_scope' => AccountScope::key($this->connection)])->assertConflict();
    }

    public function test_labeled_browser_fields_preserve_explicit_false_zero_and_unset_choices(): void
    {
        $payload = E::payload(['allow_entries' => false, 'fee_per_share' => '0', 'slippage_bps' => null]);
        unset($payload['allowed_symbols']);
        foreach ($payload as &$value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
        }
        $form = $this->form();
        unset($form['execution_json']);
        $this->actingAs($this->user)->post('/mcp/paper/execution', $form + ['execution' => $payload, 'execution_symbols' => 'DEMO, SECOND'])->assertRedirect();
        $saved = ExecutionVersion::sole()->payload;
        $this->assertFalse($saved['allow_entries']);
        $this->assertSame('0', $saved['fee_per_share']);
        $this->assertNull($saved['slippage_bps']);
        $this->assertSame(['DEMO', 'SECOND'], $saved['allowed_symbols']);
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Allow paper entries')->assertSee('Allowed stock / ETF symbols');
    }

    public function test_percentage_fee_is_explicit_decimal_bounded_and_legacy_per_share_contract_is_preserved(): void
    {
        $policies = app(ExecutionPolicies::class);
        $this->assertSame('0', $policies->validate(E::payload(), true)['fee_bps']);
        $this->assertSame('100', $policies->validate(E::payload(['fee_bps' => '100', 'fee_per_share' => '0']), true)['fee_bps']);
        $this->actingAs($this->user);
        foreach ([100, 0.1, '-1', '10000.00000001'] as $fee) {
            $this->postJson('/mcp/paper/execution', $this->form(['execution_json' => json_encode(E::payload(['fee_bps' => $fee]))]))->assertUnprocessable();
        }
        $this->post('/mcp/paper/execution', $this->form(['execution_json' => json_encode(E::payload(['fee_bps' => '100', 'fee_per_share' => '0']))]))->assertRedirect();
        $this->assertSame('100', ExecutionVersion::sole()->payload['fee_bps']);
        $this->assertStringNotContainsString('fee_bps', DB::table('mcp_paper_execution_versions')->sole()->payload);
    }

    public function test_new_settings_cannot_request_fractional_limits_the_reviewed_broker_contract_does_not_support(): void
    {
        $policies = app(ExecutionPolicies::class);
        $this->assertTrue(RobinhoodOrderContract::allowsFractional('market'));
        $this->assertFalse(RobinhoodOrderContract::allowsFractional('limit'));
        $this->assertFalse(RobinhoodOrderContract::allowsFractional('market', 'extended_hours'));
        $this->assertTrue($policies->validate(E::payload(['allow_fractional' => true, 'allow_fractional_limit' => false]), true)['allow_fractional']);
        try {
            $policies->validate(E::payload(['allow_fractional' => true, 'allow_fractional_limit' => true]), true);
            $this->fail();
        } catch (ValidationException $error) {
            $this->assertStringContainsString('fractional limit orders are unsupported', $error->errors()['allow_fractional_limit'][0]);
        }
        $this->actingAs($this->user);
        $this->postJson('/mcp/paper/execution', $this->form(['execution_json' => json_encode(E::payload(['allow_fractional' => true, 'allow_fractional_limit' => true]))]))->assertUnprocessable();
        $this->assertSame(0, ExecutionVersion::count());
    }
}
