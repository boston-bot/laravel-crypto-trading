<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketReferenceRead;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\IntegrationCleanup;
use Tests\TestCase;

class MarketReferenceConcurrencyTest extends TestCase
{
    private ?int $fixture = null;

    protected function tearDown(): void
    {
        IntegrationCleanup::connection($this->fixture);
        parent::tearDown();
    }

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    private function race(McpConnection $connection, string $request, int $count): array
    {
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            for ($i = 0; $i < $count; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/mcp-market-reference-worker.php'), (string) $connection->id, $request, now()->toIso8601String()],
                    base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
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

    public function test_duplicate_reference_workers_record_one_immutable_row_per_kind_and_states_are_constrained(): void
    {
        [$connection] = E::seed();
        $this->fixture = $connection->id;
        $ids = $this->race($connection, (string) Str::uuid(), 3);
        $this->assertCount(1, array_unique($ids));
        $rows = MarketReferenceRead::where('mcp_connection_id', $connection->id)->orderBy('id')->get();
        $this->assertSame([[$rows[0]->id.','.$rows[1]->id.','.$rows[2]->id]], [array_values(array_unique($ids))]);
        $this->assertSame([['DEMO', 'daily_history', 'complete'], ['DEMO', 'listing', 'complete'], ['SPY', 'daily_history', 'complete']], $rows->map(fn ($r) => [$r->symbol, $r->kind, $r->status])->all());
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_market_reference_reads'")->schemaname);
        $this->expectException(QueryException::class);
        DB::table('mcp_market_reference_reads')->insert(['mcp_connection_id' => $connection->id, 'scope' => $rows[0]->scope, 'request_id' => (string) Str::uuid(),
            'actor_id' => $connection->user_id, 'symbol' => 'DEMO', 'kind' => 'model_opinion', 'provider' => 'x', 'contract' => 'x', 'status' => 'complete',
            'payload' => 'x', 'retrieved_at' => now(), 'recorded_at' => now()]);
    }
}
