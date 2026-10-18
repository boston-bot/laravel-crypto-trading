<?php

namespace Tests\Feature\PaperTrading;

use App\Models\McpConnection;
use App\Models\Monitoring\PolicyVersion;
use App\Models\PaperTrading\MandateVersion;
use App\Models\Research\Event;
use App\Models\Research\Run;
use App\Models\Research\Source;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\PaperMandates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\McpFixture;
use Tests\TestCase;

class ControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private McpConnection $connection;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = McpFixture::connection();
        $this->user = User::findOrFail($this->connection->user_id);
        config(['mcp.operator_emails' => [$this->user->email]]);
        Queue::fake();
        Http::fake();
    }

    private function form(array $changes = []): array
    {
        return array_replace(['base_version' => 0, 'account_scope' => $this->connection->bound_account_id ? AccountScope::key($this->connection) : str_repeat('0', 64), 'request_id' => (string) Str::uuid(), 'reason' => 'Synthetic paper mandate.',
            'initial_cash' => '10000.00', 'long_term_pct' => '40.1', 'active_pct' => '49.9', 'cash_pct' => '10',
            'max_position_pct' => '20', 'daily_loss_pause_pct' => '2', 'monthly_budget' => '100.00',
            'scan_seconds' => '60', 'decision_minutes' => '5', 'news_minutes' => '5', 'ai_guidance' => 'Synthetic multi-day evidence preferences.'], $changes);
    }

    public function test_operator_authentication_csrf_and_unbound_access(): void
    {
        $this->get('/mcp/dashboard/control')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/mcp/dashboard/control')->assertForbidden();
        $this->post('/mcp/control/mandate', $this->form())->assertForbidden();
        $this->actingAs($this->user)->get('/mcp/dashboard/control')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Paper settings are drafts only')->assertSee('Current research instructions')->assertDontSee('synthetic-agentic');
        app()->instance('env', 'local');
        foreach (['/mcp/control/mandate', '/mcp/control/proposal', '/mcp/control/versions/1/restore'] as $path) {
            $this->post($path, $this->form())->assertStatus(419);
        }
        app()->instance('env', 'testing');
        $this->connection->update(['bound_account_id' => null]);
        $this->get('/mcp/dashboard/control')->assertOk()->assertSee('Bind an account before saving')->assertDontSee('name="initial_cash"', false);
        $this->post('/mcp/control/mandate', $this->form())->assertNotFound();
        $this->assertSame(0, MandateVersion::count());
    }

    public function test_saves_encrypted_append_only_drafts_without_runtime_side_effects(): void
    {
        $this->actingAs($this->user)->post('/mcp/control/mandate', $this->form())->assertRedirect('/mcp/dashboard/control');
        $version = MandateVersion::sole();
        $this->assertSame($this->user->id, $version->actor_id);
        $this->assertSame('10000.00', $version->payload['initial_cash']);
        $this->assertSame(60, $version->payload['scan_seconds']);
        $raw = DB::table('mcp_paper_mandate_versions')->first();
        $this->assertStringNotContainsString('Synthetic', $raw->payload.$raw->reason);
        $this->assertSame(0, PolicyVersion::count());
        $this->assertFalse(config('research.enabled'));
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->expectException(\LogicException::class);
        $version->update(['reason' => 'overwrite']);
    }

    public function test_idempotent_retries_changed_payload_and_stale_tabs(): void
    {
        $form = $this->form();
        $this->actingAs($this->user)->post('/mcp/control/mandate', $form)->assertRedirect();
        $this->post('/mcp/control/mandate', $form)->assertRedirect();
        $this->post('/mcp/control/mandate', ['decision_minutes' => '10'] + $form)->assertConflict();
        $this->post('/mcp/control/mandate', $this->form())->assertConflict();
        $this->assertSame(1, MandateVersion::count());
        $this->post('/mcp/control/mandate', $this->form(['base_version' => 1, 'decision_minutes' => '10']))->assertRedirect();
        $this->assertSame(2, MandateVersion::count());
    }

    public function test_invalid_values_and_protected_fields_are_refused(): void
    {
        $this->actingAs($this->user);
        foreach ([['initial_cash' => 100.25], ['initial_cash' => '1e3'], ['max_position_pct' => '100.00000001'],
            ['long_term_pct' => '40.10000001'], ['active_pct' => null], ['daily_loss_pause_pct' => '0'],
            ['scan_seconds' => '1'], ['decision_minutes' => '0'], ['news_minutes' => '1.5'], ['monthly_budget' => '150.00000001'],
            ['brokerage_writes_enabled' => true], ['mode' => 'bounded_auto'], ['actor_id' => 999], ['research_enabled' => true]] as $bad) {
            $this->postJson('/mcp/control/mandate', $this->form($bad))->assertUnprocessable();
            $this->travel(61)->seconds();
        }
        $this->assertSame(0, MandateVersion::count());
        $this->postJson('/mcp/control/mandate', $this->form(['initial_cash' => null, 'long_term_pct' => null, 'active_pct' => null, 'cash_pct' => null]))->assertRedirect();
        $this->assertNull(MandateVersion::sole()->payload['initial_cash']);
    }

    public function test_restore_retains_history_and_rejects_foreign_or_rebound_versions(): void
    {
        $this->actingAs($this->user)->post('/mcp/control/mandate', $this->form())->assertRedirect();
        $first = MandateVersion::sole();
        $this->post('/mcp/control/mandate', $this->form(['base_version' => 1, 'decision_minutes' => '10']))->assertRedirect();
        $restore = ['base_version' => 2, 'account_scope' => AccountScope::key($this->connection), 'request_id' => (string) Str::uuid()];
        $this->post('/mcp/control/versions/'.$first->id.'/restore', $restore)->assertRedirect();
        $this->post('/mcp/control/versions/'.$first->id.'/restore', $restore)->assertRedirect();
        $latest = MandateVersion::latest('version')->first();
        $this->assertSame(3, $latest->version);
        $this->assertSame($first->payload, $latest->payload);
        $this->assertSame($first->id, $latest->restored_from_id);
        $this->assertSame(3, MandateVersion::count());
        $this->post('/mcp/control/versions/'.$first->id.'/restore', ['request_id' => (string) Str::uuid()] + $restore)->assertConflict();
        $foreign = McpFixture::connection();
        $record = app(PaperMandates::class)->append($foreign, $foreign->user_id, 0, (string) Str::uuid(), [], 'Foreign fixture.');
        $this->post('/mcp/control/versions/'.$record->id.'/restore', ['base_version' => 3, 'account_scope' => AccountScope::key($this->connection), 'request_id' => (string) Str::uuid()])->assertNotFound();
        $this->connection->update(['bound_account_id' => 'synthetic-rebound']);
        $this->get('/mcp/dashboard/control')->assertDontSee('Synthetic paper mandate.');
        $this->post('/mcp/control/versions/'.$first->id.'/restore', ['base_version' => 0, 'account_scope' => AccountScope::key($this->connection), 'request_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_stale_connection_object_cannot_save_into_a_changed_binding(): void
    {
        $stale = $this->connection->fresh();
        $this->connection->update(['bound_account_id' => 'different-fixture-binding']);
        $this->expectException(HttpException::class);
        app(PaperMandates::class)->append($stale, $this->user->id, 0, (string) Str::uuid(), [], 'Stale fixture.');
    }

    public function test_open_tab_for_old_binding_cannot_save_or_preview_into_a_new_binding(): void
    {
        $form = $this->form();
        $this->connection->update(['bound_account_id' => 'rebound-fixture']);
        $this->actingAs($this->user)->post('/mcp/control/mandate', $form)->assertConflict();
        $this->post('/mcp/control/proposal', ['base_version' => 0, 'account_scope' => $form['account_scope'], 'proposal_json' => '{}'])->assertConflict();
        $this->assertSame(0, MandateVersion::count());
    }

    public function test_proposal_preview_does_not_save_and_rejects_protected_keys(): void
    {
        $this->actingAs($this->user)->post('/mcp/control/mandate', $this->form())->assertRedirect();
        $this->post('/mcp/control/proposal', ['base_version' => 1, 'account_scope' => AccountScope::key($this->connection), 'proposal_json' => '{"decision_minutes":10}'])->assertRedirect()
            ->assertSessionHasInput('decision_minutes', 10)->assertSessionHasInput('initial_cash', '10000.00');
        $this->assertSame(1, MandateVersion::count());
        $this->get('/mcp/dashboard/control')->assertSee('value="10"', false)->assertSee('Proposal loaded into the editor');
        foreach (['{"mode":"bounded_auto"}', '{"api_key":"synthetic-untrusted"}', '[]', '{broken', '{"monthly_budget":100.1}'] as $json) {
            $this->postJson('/mcp/control/proposal', ['base_version' => 1, 'account_scope' => AccountScope::key($this->connection), 'proposal_json' => $json])->assertUnprocessable();
        }
        $this->postJson('/mcp/control/proposal', ['base_version' => 0, 'account_scope' => AccountScope::key($this->connection), 'proposal_json' => '{}'])->assertConflict();
        $this->assertSame(1, MandateVersion::count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_evidence_is_paginated_scoped_and_escaped(): void
    {
        $foreign = McpFixture::connection();
        foreach ([$this->connection, $foreign] as $connection) {
            for ($i = 0; $i < 11; $i++) {
                Source::create(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection),
                    'symbol' => 'SYNTH', 'provider' => 'fixture', 'kind' => 'note', 'content_hash' => hash('sha256', $connection->id.':'.$i),
                    'payload' => ['excerpt' => ($connection->id === $foreign->id ? 'Foreign-only' : 'Own-'.$i).' <script>alert(1)</script>'],
                    'source_as_of' => now(), 'retrieved_at' => now()]);
                $event = Event::create(['mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection),
                    'event_key' => hash('sha256', 'event-'.$i), 'subject' => 'SYNTH', 'kind' => 'operator_request', 'priority' => 'routine',
                    'status' => 'succeeded', 'first_seen_at' => now(), 'last_seen_at' => now()]);
                Run::unguarded(fn () => Run::create(['id' => (string) Str::uuid(), 'mcp_connection_id' => $connection->id, 'scope' => AccountScope::key($connection),
                    'event_id' => $event->id, 'subject' => 'SYNTH', 'cache_key' => hash('sha256', 'run-'.$i), 'status' => 'succeeded',
                    'provider' => 'synthetic', 'model' => 'synthetic', 'prompt_version' => 'synthetic-v1', 'prompt_hash' => str_repeat('a', 64),
                    'budget_period' => now()->format('Y-m'), 'started_at' => now(), 'finished_at' => now(),
                    'result' => ['summary' => ($connection->id === $foreign->id ? 'Foreign-run' : 'Own-run').' <script>alert(1)</script>']]));
            }
        }
        $this->actingAs($this->user)->get('/mcp/dashboard/control')->assertOk()->assertSee('Own-10')->assertDontSee('Own-0 ')->assertDontSee('Foreign-only')
            ->assertSee('Own-run')->assertDontSee('Foreign-run')->assertSee('runs_page=2', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)->assertSee('sources_page=2', false);
        $this->get('/mcp/dashboard/control?sources_page=2')->assertOk()->assertSee('Own-0 ')->assertDontSee('Foreign-only');
    }
}
