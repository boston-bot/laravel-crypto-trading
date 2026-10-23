<?php

namespace Tests\Integration;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CryptoStrategyConcurrencyTest extends TestCase
{
    private ?int $actor = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T23:44:29.123456Z'));
        $this->actor = User::factory()->create()->id;
    }

    protected function tearDown(): void
    {
        if ($this->actor !== null) {
            $ids = DB::table('mcp_paper_portfolios')->where('actor_id', $this->actor)->pluck('id');
            foreach (['mcp_strategy_signals', 'mcp_strategy_cycles', 'mcp_symbol_profiles', 'mcp_paper_instrument_policies', 'mcp_paper_cash_entries'] as $table) {
                DB::table($table)->whereIn('paper_portfolio_id', $ids)->delete();
            }
            DB::table('mcp_paper_portfolios')->whereIn('id', $ids)->delete();
            DB::table('users')->where('id', $this->actor)->delete();
        }
        parent::tearDown();
    }

    private function race(string $mode, string $argument, string $lockTable, int $lockId): array
    {
        $workers = [];
        DB::beginTransaction();
        DB::table($lockTable)->where('id', $lockId)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 3; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-crypto-strategy-worker.php'), $mode, (string) $this->actor,
                    $argument, now()->toISOString()], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                        'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(20);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $output = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $output[] = $worker->getOutput();
            }

            return $output;
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

    public function test_parallel_policy_and_evaluation_retries_commit_one_opening_balance_and_one_cycle(): void
    {
        $policies = $this->race('policy', (string) Str::uuid(), 'users', $this->actor);
        $this->assertCount(1, array_unique($policies));
        $portfolio = Portfolio::where('actor_id', $this->actor)->sole();
        $this->assertSame(1, InstrumentPolicy::where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame(1, DB::table('mcp_paper_cash_entries')->where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame('400.000000000000000000', $portfolio->cash);
        $cycles = $this->race('evaluate', (string) $portfolio->id, 'mcp_paper_portfolios', $portfolio->id);
        $this->assertCount(1, array_unique($cycles));
        $this->assertSame(1, DB::table('mcp_strategy_cycles')->where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame(1, DB::table('mcp_strategy_signals')->where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_paper_instrument_policies'")->schemaname);
        $raw = (array) DB::table('mcp_paper_portfolios')->find($portfolio->id);
        unset($raw['id']);
        foreach ([array_replace($raw, ['scope' => 'second']), array_replace($raw, ['asset_class' => 'equity'])] as $row) {
            try {
                DB::transaction(fn () => DB::table('mcp_paper_portfolios')->insert($row));
                $this->fail('Invalid context/duplicate crypto sleeve was accepted.');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[0], ['23505', '23514']);
            }
        }
    }
}
