<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\SymbolProfile;
use App\Services\PaperTrading\PaperMarketReferences;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\IntegrationCleanup;
use Tests\TestCase;

class SymbolProfileConcurrencyTest extends TestCase
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
                $process = new Process([PHP_BINARY, base_path('tests/Support/mcp-symbol-profile-worker.php'), (string) $connection->id, $request, now()->toIso8601String()],
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

    public function test_duplicate_profile_workers_record_one_immutable_profile_and_states_are_constrained(): void
    {
        [$connection] = E::seed();
        $this->fixture = $connection->id;
        R::enable();
        R::fake(fn () => R::body(['DEMO' => R::bars(260), 'SPY' => R::bars(260)]), ['DEMO' => R::asset('DEMO')]);
        app(PaperMarketReferences::class)->collect($connection, $connection->user_id, [], (string) Str::uuid());
        $ids = $this->race($connection, (string) Str::uuid(), 3);
        $this->assertCount(1, array_unique($ids));
        $profile = SymbolProfile::where('mcp_connection_id', $connection->id)->sole();
        $this->assertSame([(string) $profile->id, 'DEMO', 'complete', 'none'], [$ids[0], $profile->symbol, $profile->status, $profile->setup]);
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_symbol_profiles'")->schemaname);
        $this->expectException(QueryException::class);
        DB::table('mcp_symbol_profiles')->insert(['mcp_connection_id' => $connection->id, 'scope' => $profile->scope, 'request_id' => (string) Str::uuid(),
            'actor_id' => $connection->user_id, 'symbol' => 'DEMO', 'profile_version' => 'x', 'parameters_version' => 'x', 'status' => 'unavailable',
            'setup' => 'breakout', 'reason' => 'x', 'input_fingerprint' => 'x', 'payload' => 'x', 'cutoff' => now(), 'recorded_at' => now()]);
    }
}
