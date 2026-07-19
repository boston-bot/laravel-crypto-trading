<?php

namespace Tests\Feature\Trading;

use App\Models\BrokerAccount;
use App\Models\PaperLedgerEntry;
use App\Models\PaperSession;
use App\Models\PipelineCycle;
use App\Models\RuntimeControlRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationsConsoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_focused_console_pages_and_read_apis_are_available_locally(): void
    {
        $this->withoutVite();

        foreach (['/dashboard', '/strategies', '/assets', '/activity', '/paper', '/operations', '/research'] as $path) {
            $this->get($path)->assertOk()->assertSee('TRADE / OPS');
        }

        foreach (['overview', 'strategies', 'assets', 'activity', 'paper', 'operations', 'research'] as $resource) {
            $this->getJson('/api/ops/v1/'.$resource)->assertOk()->assertJsonStructure(['data']);
        }
    }

    public function test_console_rejects_non_loopback_requests(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/dashboard')
            ->assertForbidden();
    }

    public function test_virtual_paper_session_start_is_idempotent_and_posts_opening_cash(): void
    {
        $account = $this->account();
        $payload = [
            'broker_account_id' => $account->id,
            'funding_mode' => 'virtual',
            'virtual_capital' => 10_000,
            'idempotency_key' => 'start-session-1',
        ];

        $this->postJson('/operations/actions/paper-sessions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.session.funding_mode', 'virtual');
        $this->postJson('/operations/actions/paper-sessions', $payload)
            ->assertStatus(202)
            ->assertJsonPath('message', 'Paper session was already created.');

        $this->assertDatabaseCount('paper_sessions', 1);
        $this->assertDatabaseCount('paper_ledger_entries', 1);
        $this->assertSame(10_000.0, (float) PaperLedgerEntry::query()->sole()->cash_delta);
    }

    public function test_paper_projection_exposes_research_evidence_state_without_implying_live_eligibility(): void
    {
        $this->account();

        $this->getJson('/api/ops/v1/paper')
            ->assertOk()
            ->assertJsonPath('data.research_finalists', [])
            ->assertJsonPath('data.paper_evidence.status', 'not_eligible')
            ->assertJsonPath('data.paper_evidence.live_eligible', false);
    }

    public function test_mirror_session_requires_fresh_equity_and_copies_no_positions(): void
    {
        $account = $this->account(12_345.67);

        $this->postJson('/operations/actions/paper-sessions', [
            'broker_account_id' => $account->id,
            'funding_mode' => 'mirror',
            'idempotency_key' => 'mirror-session-1',
        ])->assertCreated();

        $session = PaperSession::query()->sole();
        $this->assertSame(12_345.67, (float) $session->opening_cash);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_pipeline_requests_coalesce_while_a_cycle_is_active(): void
    {
        Queue::fake();
        $account = $this->account();

        $first = $this->postJson('/operations/actions/cycles', [
            'broker_account_id' => $account->id,
            'trigger' => 'manual',
            'idempotency_key' => 'cycle-1',
        ])->assertAccepted()->json('data.cycle.id');
        $second = $this->postJson('/operations/actions/cycles', [
            'broker_account_id' => $account->id,
            'trigger' => 'manual',
            'idempotency_key' => 'cycle-2',
        ])->assertAccepted()->json('data.cycle.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('pipeline_cycles', 1);
        $this->assertDatabaseCount('pipeline_cycle_steps', 6);
        $this->assertSame('queued', PipelineCycle::query()->sole()->status);
    }

    public function test_runtime_controls_accept_only_allowlisted_processes_and_actions(): void
    {
        $this->postJson('/operations/actions/runtime', [
            'process_name' => 'shell',
            'action' => 'restart',
            'idempotency_key' => 'runtime-invalid',
        ])->assertUnprocessable();

        $this->postJson('/operations/actions/runtime', [
            'process_name' => 'queue',
            'action' => 'pause',
            'idempotency_key' => 'runtime-valid',
        ])->assertAccepted();

        $request = RuntimeControlRequest::query()->sole();
        $this->assertSame('queue', $request->process_name);
        $this->assertSame('pause', $request->requested_action);
    }

    private function account(float $equity = 25_000): BrokerAccount
    {
        return BrokerAccount::query()->create([
            'broker' => 'coinbase',
            'external_account_id' => uniqid('ops-account-', true),
            'currency' => 'USD',
            'buying_power' => $equity,
            'cash_balance' => $equity,
            'equity' => $equity,
            'status' => 'active',
            'snapshot_at' => now(),
        ]);
    }
}
