<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\InstrumentClassification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ExecutionFixture as E;
use Tests\TestCase;

class PaperClassificationConcurrencyTest extends TestCase
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

    private function race(McpConnection $connection, string $request, int $count): array
    {
        $workers = [];
        DB::beginTransaction();
        McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
        try {
            for ($i = 0; $i < $count; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/mcp-paper-classification-worker.php'), (string) $connection->id, $request, now()->toIso8601String()],
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

    public function test_duplicate_classification_workers_record_one_immutable_row_and_states_are_constrained(): void
    {
        [$connection] = E::seed();
        $ids = $this->race($connection, (string) Str::uuid(), 3);
        $this->assertCount(1, array_unique($ids));
        $row = InstrumentClassification::sole();
        $this->assertSame([(string) $row->id, 'complete', 'registered_fund_ncen'], [$ids[0], $row->status, $row->basis]);
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_paper_instrument_classifications'")->schemaname);
        $this->expectException(QueryException::class);
        DB::table('mcp_paper_instrument_classifications')->insert(['mcp_connection_id' => $connection->id, 'scope' => $row->scope, 'request_id' => (string) Str::uuid(),
            'actor_id' => $connection->user_id, 'symbol' => 'DEMO', 'provider' => 'sec_edgar', 'status' => 'complete', 'basis' => null, 'payload' => 'x',
            'retrieved_at' => now(), 'recorded_at' => now()]);
    }
}
