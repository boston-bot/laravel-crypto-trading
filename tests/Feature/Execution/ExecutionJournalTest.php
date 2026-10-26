<?php

namespace Tests\Feature\Execution;

use App\Models\Execution\OrderIntent;
use App\Models\User;
use App\Services\Execution\ExecutionJournal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ExecutionJournalFixture as F;
use Tests\TestCase;

class ExecutionJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-26T14:00:00Z'));
    }

    public function test_intent_and_outbox_are_atomic_idempotent_encrypted_and_separate_from_paper_cash(): void
    {
        [$c, $signal] = F::seed();
        $before = DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first();
        $journal = app(ExecutionJournal::class);
        $draft = F::draft();
        $intent = $journal->stage($c, $c->user_id, $signal->id, $draft);
        $this->assertSame($intent->id, $journal->stage($c, $c->user_id, $signal->id, array_reverse($draft, true))->id);
        $this->assertSame('0.100001', $intent->payload['quantity']);
        $this->assertSame('live_intent_unapproved', $intent->payload['mode']);
        $this->assertSame($signal->input_fingerprint, $intent->payload['signal_input_fingerprint']);
        $this->assertStringNotContainsString('quantity', DB::table('mcp_execution_intents')->value('payload'));
        $this->assertArrayNotHasKey('payload', $intent->toArray());
        $this->assertDatabaseCount('mcp_execution_intents', 1);
        $this->assertDatabaseCount('mcp_execution_dispatches', 1);
        $this->assertDatabaseCount('mcp_execution_events', 1);
        $this->assertEquals($before, DB::table('mcp_paper_portfolios')->where('mcp_connection_id', $c->id)->first());
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertFalse(config('mcp.brokerage_writes_enabled'));
        $this->expectException(\LogicException::class);
        $intent->update(['fingerprint' => str_repeat('a', 64)]);
    }

    public function test_same_signal_cannot_be_resized_or_replaced_with_another_policy(): void
    {
        [$c, $signal] = F::seed();
        $journal = app(ExecutionJournal::class);
        $journal->stage($c, $c->user_id, $signal->id, F::draft());
        foreach ([['quantity' => '0.2'], ['policy_fingerprint' => str_repeat('a', 64)], ['type' => 'limit', 'quantity' => '1']] as $patch) {
            $this->refused(fn () => $journal->stage($c, $c->user_id, $signal->id, F::draft($patch)), 409);
        }
        $this->assertDatabaseCount('mcp_execution_intents', 1);
    }

    public function test_untrusted_decimals_fields_fractional_limits_and_expired_signals_are_refused(): void
    {
        [$c, $signal] = F::seed();
        $journal = app(ExecutionJournal::class);
        foreach ([['quantity' => 0.1], ['quantity' => '1e-2'], ['quantity' => '0'], ['quantity' => '0.0000001'],
            ['price_bound' => '-1'], ['type' => 'limit'], ['mode' => 'live'], ['expires_at' => '2026-10-26 14:04:00'],
            ['policy_fingerprint' => 'approved']] as $patch) {
            $this->refused(fn () => $journal->stage($c, $c->user_id, $signal->id, F::draft($patch)), 422);
        }
        $this->refused(fn () => $journal->stage($c, $c->user_id, $signal->id, F::draft(['expires_at' => now()->addMinutes(6)->toISOString()])), 409);
        $this->travel(5)->minutes();
        $this->refused(fn () => $journal->stage($c, $c->user_id, $signal->id, F::draft()), 409);
        $this->assertDatabaseCount('mcp_execution_intents', 0);
        $this->assertDatabaseCount('mcp_execution_dispatches', 0);
        $this->assertDatabaseCount('mcp_execution_accounts', 0);
    }

    public function test_actor_scope_and_changed_authorization_are_enforced(): void
    {
        [$c, $signal] = F::seed();
        $journal = app(ExecutionJournal::class);
        $this->refused(fn () => $journal->stage($c, User::factory()->create()->id, $signal->id, F::draft()), 403);
        $c->update(['auth_generation' => $c->auth_generation + 1]);
        $this->refused(fn () => $journal->stage($c, $c->user_id, $signal->id, F::draft()), 409);
        $this->assertDatabaseCount('mcp_execution_intents', 0);
    }

    public function test_local_cancellation_is_idempotent_and_retains_intent_and_events(): void
    {
        [$c, $signal] = F::seed();
        $journal = app(ExecutionJournal::class);
        $intent = $journal->stage($c, $c->user_id, $signal->id, F::draft());
        $journal->cancel($c, $c->user_id, $intent->id);
        $journal->cancel($c, $c->user_id, $intent->id);
        $this->assertSame(['staged', 'cancelled'], DB::table('mcp_execution_events')->orderBy('version')->pluck('status')->all());
        $this->assertSame($intent->fingerprint, OrderIntent::findOrFail($intent->id)->fingerprint);
        $this->assertDatabaseCount('mcp_execution_intents', 1);
    }

    public function test_staging_rolls_back_outbox_and_events_on_failure(): void
    {
        [$c, $signal] = F::seed();
        try {
            DB::transaction(function () use ($c, $signal) {
                app(ExecutionJournal::class)->stage($c, $c->user_id, $signal->id, F::draft());
                throw new \RuntimeException('synthetic crash before commit');
            });
        } catch (\RuntimeException $error) {
            $this->assertSame('synthetic crash before commit', $error->getMessage());
        }
        $this->assertDatabaseCount('mcp_execution_intents', 0);
        $this->assertDatabaseCount('mcp_execution_dispatches', 0);
        $this->assertDatabaseCount('mcp_execution_events', 0);
    }

    private function refused(callable $work, int $status): void
    {
        try {
            $work();
            $this->fail('Expected refusal.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode(), $e->getMessage());
        }
    }
}
