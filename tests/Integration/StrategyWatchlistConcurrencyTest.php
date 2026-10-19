<?php

namespace Tests\Integration;

use App\Models\McpConnection;
use App\Models\PaperTrading\WatchlistRead;
use App\Models\PaperTrading\WatchlistRevision;
use App\Models\User;
use App\Services\PaperTrading\StrategyWatchlists;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\McpFixture;
use Tests\TestCase;

class StrategyWatchlistConcurrencyTest extends TestCase
{
    private ?McpConnection $fixture = null;

    protected function tearDown(): void
    {
        if ($this->fixture) {
            $settings = DB::table('mcp_strategy_watchlist_settings')->where('mcp_connection_id', $this->fixture->id)->pluck('id');
            DB::table('mcp_strategy_watchlist_reads')->whereIn('setting_id', $settings)->delete();
            DB::table('mcp_strategy_watchlist_revisions')->whereIn('setting_id', $settings)->delete();
            DB::table('mcp_strategy_watchlist_settings')->whereIn('id', $settings)->delete();
            McpConnection::whereKey($this->fixture->id)->delete();
            User::whereKey($this->fixture->user_id)->delete();
        }
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
                $process = new Process([PHP_BINARY, base_path('tests/Support/mcp-strategy-watchlist-worker.php'), (string) $connection->id, $request, now()->toIso8601String()],
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

    public function test_duplicate_watchlist_workers_commit_one_revision_and_read_and_schema_constraints_hold(): void
    {
        $connection = McpFixture::connection()->fresh();
        $this->fixture = $connection;
        config(['mcp.operator_emails' => [User::findOrFail($connection->user_id)->email]]);
        app(StrategyWatchlists::class)->configure($connection, $connection->user_id, 0, (string) Str::uuid(), 'Strategy Candidates');
        $ids = $this->race($connection, (string) Str::uuid(), 3);
        $this->assertCount(1, array_unique($ids));
        $row = WatchlistRevision::sole();
        $this->assertSame([(string) WatchlistRead::sole()->id, $row->id], [$ids[0], WatchlistRead::sole()->revision_id]);
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_strategy_watchlist_revisions'")->schemaname);
        $this->expectException(QueryException::class);
        DB::table('mcp_strategy_watchlist_reads')->insert(['setting_id' => $row->setting_id, 'request_id' => (string) Str::uuid(),
            'revision_id' => null, 'reason' => null, 'recorded_at' => now()]);
    }
}
