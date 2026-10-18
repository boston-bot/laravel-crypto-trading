<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\OrderHandoff;
use App\Models\PaperTrading\SimulationOrder;
use App\Services\PaperTrading\DecisionRunner;
use App\Services\PaperTrading\DecisionScheduler;
use App\Services\Research\ResearchModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\FakeDecisionModel;
use Tests\Support\ResearchFixture;
use Tests\Support\SimulationFixture as F;
use Tests\TestCase;

class PaperHandoffConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    private function race(McpConnection $connection, array $requests): array
    {
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($requests as $request) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/mcp-paper-handoff-worker.php'), (string) $connection->id,
                    base64_encode(json_encode($request, JSON_THROW_ON_ERROR)), now()->toIso8601String()], base_path(),
                    ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $process->setTimeout(20);
                $process->start();
                $workers[] = $process;
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $results[] = $worker->getOutput();
            }
            sort($results);

            return $results;
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

    public function test_competing_execution_drafts_keep_one_revision_and_owner_policy_reference(): void
    {
        [$connection, $portfolio] = F::seed();
        $this->assertSame(['conflict', 'saved'], $this->race($connection, [
            ['operation' => 'draft', 'request_id' => (string) Str::uuid(), 'payload' => E::payload()],
            ['operation' => 'draft', 'request_id' => (string) Str::uuid(), 'payload' => E::payload(['slippage_bps' => '20'])],
        ]));
        $this->assertSame(1, ExecutionVersion::where('paper_portfolio_id', $portfolio->id)->count());
    }

    public function test_duplicate_handoff_workers_create_one_intent_and_one_cash_reservation(): void
    {
        [$connection, $portfolio] = E::seed();
        ResearchFixture::enable();
        config(['paper.decisions_enabled' => true]);
        Queue::fake();
        $model = new FakeDecisionModel;
        $model->respond = fn ($request) => FakeDecisionModel::answer($request, function ($data) {
            $data['decisions'][0]['action'] = 'BUY';
            $data['decisions'][0]['target_weight_pct'] = '10';

            return $data;
        });
        $this->app->instance(ResearchModel::class, $model);
        app(DecisionScheduler::class)->tick();
        $run = DecisionRun::where('mcp_connection_id', $connection->id)->sole();
        $this->assertSame('proposed', app(DecisionRunner::class)->process($run->id)->status);
        $request = ['operation' => 'handoff', 'run_id' => $run->id];
        $this->assertSame(['order_created', 'order_created'], $this->race($connection, [$request, $request]));
        $this->assertSame(1, SimulationOrder::where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame(1, OrderHandoff::where('decision_run_id', $run->id)->count());
        $this->assertSame('901.890000000000000000', $portfolio->fresh()->reserved_cash);
        $this->assertCount(1, $model->requests);
        $this->assertSame('mcp_trading_test', DB::selectOne('SELECT current_database() AS name')->name);
        foreach (['mcp_paper_execution_versions', 'mcp_paper_market_inputs', 'mcp_paper_order_handoffs'] as $table) {
            $this->assertSame('mcp_trading', DB::selectOne('SELECT schemaname FROM pg_tables WHERE tablename = ?', [$table])->schemaname);
        }
    }
}
