<?php

namespace Tests\Integration;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Services\Execution\ExecutionJournal;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionJournalFixture as F;
use Tests\Support\SimulationFixture;
use Tests\TestCase;

class ExecutionJournalConcurrencyTest extends TestCase
{
    private array $fixtures = [];

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-26T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $id) {
            F::cleanup($id);
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        [$c, $s] = F::seed();
        $this->fixtures[] = $c->id;

        return [$c, $s];
    }

    private function journal(): ExecutionJournal
    {
        config(['mcp.brokerage_writes_enabled' => true]);

        return new ExecutionJournal(new class extends SubmissionChecks
        {
            public function reason(McpConnection $connection, OrderIntent $intent): ?string
            {
                return null; // No network calls: synthetic admission solely for journal lifecycle tests.
            }
        });
    }

    public function test_checks_without_live_policy_refuse_even_with_write_flag_and_do_not_consume_the_outbox(): void
    {
        [$c, $s] = $this->fixture();
        $journal = app(ExecutionJournal::class);
        $intent = $journal->stage($c, $c->user_id, $s->id, F::draft());
        foreach ([false, true] as $flag) {
            config(['mcp.brokerage_writes_enabled' => $flag]);
            try {
                $journal->claim($c, $c->user_id, $intent->id);
                $this->fail('Checks without an applied live policy must deny.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
                $this->assertSame($flag ? 'live_policy_missing' : 'Brokerage writes are disabled.', $error->getMessage());
            }
        }
        $this->assertSame('staged', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
        $this->assertSame(1, DB::table('mcp_execution_events')->where('intent_id', $intent->id)->count());
    }

    public function test_concurrent_stage_and_duplicate_claim_produce_one_intent_and_one_owner(): void
    {
        [$c, $s] = $this->fixture();
        $outputs = $this->race($c->id, [['stage', $s->id], ['stage', $s->id], ['stage', $s->id]]);
        $this->assertCount(1, array_unique($outputs));
        $intent = OrderIntent::where('mcp_connection_id', $c->id)->sole();
        $claims = $this->race($c->id, [['claim', $intent->id], ['claim', $intent->id], ['claim', $intent->id]]);
        $this->assertCount(1, array_filter($claims, fn ($claim) => $claim !== 'not_claimed'));
        $this->assertSame(2, DB::table('mcp_execution_events')->where('intent_id', $intent->id)->count());
        $this->assertSame('submitting', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
    }

    public function test_two_different_orders_cannot_claim_the_same_account_concurrently(): void
    {
        [$c, $s] = $this->fixture();
        $journal = $this->journal();
        $a = $journal->stage($c, $c->user_id, $s->id, F::draft());
        $b = $journal->stage($c, $c->user_id, F::signal($c, 'OTHER')->id, F::draft());
        $claims = $this->race($c->id, [['claim', $a->id], ['claim', $b->id]]);
        $this->assertCount(1, array_filter($claims, fn ($claim) => $claim !== 'not_claimed'));
        $this->assertSame(1, DB::table('mcp_execution_dispatches')->whereIn('intent_id', [$a->id, $b->id])->where('status', 'submitting')->count());
    }

    public function test_crash_or_lost_response_stays_unknown_across_restart_and_another_connection(): void
    {
        [$c, $s] = $this->fixture();
        $journal = $this->journal();
        $intent = $journal->stage($c, $c->user_id, $s->id, F::draft());
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $this->assertNotNull($token);
        // Claim committed, then the worker died. No automatic retry, even before the broker request.
        $this->assertNull($this->journal()->claim($c, $c->user_id, $intent->id));
        try {
            $journal->uncertain($c, $c->user_id, $intent->id, 'stale-worker-token');
            $this->fail('Wrong token must be fenced.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $c->update(['auth_generation' => $c->auth_generation + 1, 'status' => 'reconnect_required']);
        $journal->uncertain($c, $c->user_id, $intent->id, $token);
        $journal->uncertain($c, $c->user_id, $intent->id, $token);
        $this->assertSame(['staged', 'submitting', 'unknown'], DB::table('mcp_execution_events')->where('intent_id', $intent->id)->orderBy('version')->pluck('status')->all());
        [$other, $portfolio] = SimulationFixture::seed();
        $this->fixtures[] = $other->id;
        $other->update(['bound_account_id' => $c->bound_account_id]);
        $portfolio->update(['scope' => AccountScope::key($other)]);
        $second = $journal->stage($other, $other->user_id, F::signal($other)->id, F::draft());
        $this->assertSame($intent->execution_account_id, $second->execution_account_id);
        $this->assertNull($this->journal()->claim($other, $other->user_id, $second->id));
        $this->assertSame('unknown', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
    }

    public function test_expired_and_cancelled_intents_never_claim_and_nested_transaction_cannot_release_an_owner(): void
    {
        [$c, $s] = $this->fixture();
        $journal = $this->journal();
        $intent = $journal->stage($c, $c->user_id, $s->id, F::draft());
        DB::beginTransaction();
        try {
            $journal->claim($c, $c->user_id, $intent->id);
            $this->fail('Outer transaction must be refused.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('commit independently', $error->getMessage());
        } finally {
            DB::rollBack();
        }
        $second = $journal->stage($c, $c->user_id, F::signal($c, 'OTHER')->id, F::draft());
        $journal->cancel($c, $c->user_id, $second->id);
        $this->assertNull($journal->claim($c, $c->user_id, $second->id));
        $this->travel(4)->minutes();
        $this->assertNull($journal->claim($c, $c->user_id, $intent->id));
        $this->assertSame('expired', DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->value('status'));
    }

    public function test_database_enforces_immutable_history_and_refuses_replay_transitions(): void
    {
        [$c, $s] = $this->fixture();
        $journal = $this->journal();
        $intent = $journal->stage($c, $c->user_id, $s->id, F::draft());
        $token = $journal->claim($c, $c->user_id, $intent->id);
        $journal->uncertain($c, $c->user_id, $intent->id, $token);
        $this->sqlRefused(fn () => DB::table('mcp_execution_intents')->where('id', $intent->id)->update(['fingerprint' => str_repeat('0', 64)]));
        $this->sqlRefused(fn () => DB::table('mcp_execution_intents')->where('id', $intent->id)->delete());
        $this->sqlRefused(fn () => DB::table('mcp_execution_events')->where('intent_id', $intent->id)->delete());
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->delete());
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'staged', 'version' => 1, 'claim_token' => null, 'claimed_at' => null]));
        $this->sqlRefused(fn () => DB::table('mcp_execution_dispatches')->where('intent_id', $intent->id)->update(['status' => 'filled', 'version' => 4]));
        try {
            $journal->cancel($c, $c->user_id, $intent->id);
            $this->fail('Unknown outcome cannot be locally cancelled.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame('mcp_trading', DB::selectOne('SELECT current_schema() AS name')->name);
        $this->assertSame('mcp_trading_test', DB::selectOne('SELECT current_database() AS name')->name);
    }

    private function sqlRefused(callable $work): void
    {
        try {
            DB::transaction($work);
            $this->fail('Expected database constraint/trigger refusal.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    private function race(int $connection, array $tasks): array
    {
        $workers = [];
        DB::beginTransaction();
        DB::table('mcp_connections')->where('id', $connection)->lockForUpdate()->first();
        try {
            foreach ($tasks as [$operation, $id]) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-execution-journal-worker.php'), $operation,
                    (string) $connection, (string) $id, now()->toISOString()], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null',
                        'MCP_EXECUTION_RECOVERY_DIR' => config('mcp.execution_recovery_dir')]);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $outputs = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
                $outputs[] = $worker->getOutput();
            }

            return $outputs;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
