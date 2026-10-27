<?php

namespace Tests\Integration;

use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationEvent;
use App\Models\PaperTrading\SimulationOrder;
use App\Models\User;
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoPolicies;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CryptoPaperPostgresTest extends TestCase
{
    private ?int $actor = null;

    private int $readFloor = 0;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:10.250000Z'));
        $this->actor = User::factory()->create()->id;
        $this->readFloor = (int) DB::table('mcp_crypto_market_reads')->max('id');
        config(['mcp.operator_emails' => [User::find($this->actor)->email], 'crypto.market_data_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD']]);
    }

    protected function tearDown(): void
    {
        if ($this->actor !== null) {
            $ids = DB::table('mcp_paper_portfolios')->where('actor_id', $this->actor)->pluck('id');
            $orders = DB::table('mcp_paper_sim_orders')->whereIn('paper_portfolio_id', $ids)->pluck('id');
            DB::table('mcp_signal_order_handoffs')->whereIn('paper_portfolio_id', $ids)->delete();
            DB::table('mcp_paper_sim_events')->whereIn('sim_order_id', $orders)->delete();
            DB::table('mcp_paper_sim_observations')->whereIn('paper_portfolio_id', $ids)->delete();
            DB::table('mcp_paper_sim_orders')->whereIn('id', $orders)->delete();
            foreach (['mcp_paper_sim_positions', 'mcp_strategy_signals', 'mcp_strategy_cycles', 'mcp_symbol_profiles', 'mcp_paper_instrument_policies', 'mcp_paper_cash_entries'] as $table) {
                DB::table($table)->whereIn('paper_portfolio_id', $ids)->delete();
            }
            DB::table('mcp_paper_portfolios')->whereIn('id', $ids)->delete();
            DB::table('mcp_crypto_fee_observations')->where('actor_id', $this->actor)->delete();
            foreach (['mcp_crypto_candles', 'mcp_crypto_quotes', 'mcp_crypto_product_snapshots'] as $table) {
                DB::table($table)->where('mcp_crypto_market_read_id', '>', $this->readFloor)->delete();
            }
            DB::table('mcp_crypto_market_reads')->where('id', '>', $this->readFloor)->delete();
            DB::table('users')->where('id', $this->actor)->delete();
        }
        parent::tearDown();
    }

    private function market(): void
    {
        CoinbasePublicFake::install(['BTC-USD']);
        $read = app(CryptoMarketCollector::class)->tick()['read_id'];
        DB::table('mcp_crypto_product_snapshots')->where('mcp_crypto_market_read_id', $read)->update(['quote_volume_24h' => '50000000']);
        DB::table('mcp_crypto_quotes')->where('mcp_crypto_market_read_id', $read)->update(['bid' => '99.69', 'ask' => '99.70', 'bid_size' => '50', 'ask_size' => '50']);
        $end = CarbonImmutable::now()->utc()->startOfDay();
        foreach (DB::table('mcp_crypto_candles')->where(['granularity' => 'ONE_DAY', 'product_id' => 'BTC-USD'])->where('mcp_crypto_market_read_id', $read)
            ->where('starts_at', '>=', CryptoMarketCollector::time($end->subDays(200)))->orderBy('starts_at')->get() as $i => $row) {
            $close = $i < 180 ? Decimal::from('60')->plus(Decimal::from((string) $i)->multipliedBy(Decimal::from('0.2'))) :
                Decimal::from('99')->plus(Decimal::from((string) ($i - 180))->multipliedBy(Decimal::from('0.01')));
            DB::table('mcp_crypto_candles')->where('id', $row->id)->update(['open' => (string) $close, 'close' => (string) $close,
                'low' => (string) $close->minus(Decimal::from('0.5')), 'high' => (string) $close->plus(Decimal::from('0.5'))]);
        }
    }

    public function test_parallel_paper_cycles_commit_one_order_and_an_exact_decimal_ledger(): void
    {
        $this->market();
        $policy = app(CryptoPolicies::class)->apply($this->actor, (string) Str::uuid(), costs: [['version' => 'test_fee', 'product_id' => 'BTC-USD',
            'maker_rate' => '0.004', 'taker_rate' => '0.006', 'cost_scope' => 'product_all_in', 'observed_at' => now()->subMinute()->toISOString(),
            'valid_until' => now()->addHour()->toISOString(), 'source' => 'Integration fixture']]);
        $portfolio = Portfolio::findOrFail($policy->paper_portfolio_id);
        $workers = [];
        DB::beginTransaction();
        DB::table('mcp_paper_portfolios')->where('id', $portfolio->id)->lockForUpdate()->first();
        try {
            for ($i = 0; $i < 3; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/mcp-crypto-strategy-worker.php'), 'paper', (string) $this->actor,
                    (string) $portfolio->id, now()->toISOString()], base_path(), ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
                        'DB_CONNECTION' => 'pgsql_test', 'DB_URL' => '', 'LOG_CHANNEL' => 'null']);
                $worker->setTimeout(30);
                $worker->start();
                $workers[] = $worker;
            }
            DB::commit();
            $cycles = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $cycles[] = $worker->getOutput();
            }
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
        }
        $this->assertCount(1, array_unique($cycles));
        $order = SimulationOrder::where('paper_portfolio_id', $portfolio->id)->sole();
        $fill = SimulationEvent::where('sim_order_id', $order->id)->where('kind', 'fill')->sole();
        $this->assertSame(['filled', '99.799700000000000000'], [$order->status, $fill->price]);
        $notional = Decimal::from($order->quantity)->multipliedBy(Decimal::from('99.7997'));
        $fee = $notional->multipliedBy(Decimal::from('0.006'))->rounded(18, RoundingMode::CEILING);
        $expected = Decimal::from('400')->minus($notional)->minus($fee)->rounded(18, RoundingMode::UNNECESSARY);
        $this->assertSame((string) $expected, (string) Decimal::from($portfolio->fresh()->cash)->rounded(18, RoundingMode::UNNECESSARY));
        $this->assertSame(1, DB::table('mcp_signal_order_handoffs')->where('paper_portfolio_id', $portfolio->id)->count());
        $this->assertSame(0, DB::table('broker_orders')->count());
    }

    public function test_constraints_require_exactly_one_policy_source_and_complete_fee_observations(): void
    {
        $policy = app(CryptoPolicies::class)->apply($this->actor, (string) Str::uuid());
        $order = ['paper_portfolio_id' => $policy->paper_portfolio_id, 'actor_id' => $this->actor, 'request_id' => (string) Str::uuid(),
            'intent_hash' => str_repeat('a', 64), 'intent' => 'x', 'assumptions' => 'x', 'auth_generation' => 0, 'symbol' => 'BTC-USD', 'sleeve' => 'active',
            'side' => 'BUY', 'type' => 'limit', 'status' => 'open', 'quantity' => '1', 'price_bound' => '1', 'accepted_at' => now(), 'expires_at' => now()];
        $observation = ['venue' => 'coinbase', 'actor_id' => $this->actor, 'trigger' => 'manual', 'status' => 'complete', 'reasons' => '[]',
            'request_started_at' => now(), 'recorded_at' => now()];
        $replay = ['mcp_connection_id' => null, 'paper_portfolio_id' => null, 'asset_class' => 'crypto', 'venue' => 'coinbase', 'scope' => 'x',
            'request_id' => (string) Str::uuid(), 'actor_id' => $this->actor, 'replay_version' => 'v', 'profile_version' => 'v', 'rule_version' => 'v',
            'parameters_hash' => 'h', 'status' => 'incomplete', 'reason' => 'r', 'input_fingerprint' => 'f', 'payload' => 'x', 'recorded_at' => now()];
        $read = ['request_id' => (string) Str::uuid(), 'venue' => 'coinbase', 'contract' => 'c', 'trigger' => 'unreviewed', 'status' => 'running',
            'requested_products' => '[]', 'started_at' => now()];
        foreach ([['mcp_paper_sim_orders', $order], ['mcp_crypto_fee_observations', $observation], ['mcp_strategy_replays', $replay], ['mcp_crypto_market_reads', $read]] as [$table, $row]) {
            try {
                DB::transaction(fn () => DB::table($table)->insert($row));
                $this->fail("$table accepted an invalid row.");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
        $this->assertSame('mcp_trading', DB::selectOne("SELECT schemaname FROM pg_tables WHERE tablename = 'mcp_crypto_fee_observations'")->schemaname);
        // Backfill reads are an accepted trigger.
        DB::table('mcp_crypto_market_reads')->insert(array_replace($read, ['trigger' => 'backfill']));
        $this->assertSame(1, DB::table('mcp_crypto_market_reads')->where('request_id', $read['request_id'])->delete());
        $this->assertSame('24', (string) DB::selectOne("SELECT character_maximum_length FROM information_schema.columns WHERE table_schema = 'mcp_trading' AND table_name = 'mcp_paper_sim_orders' AND column_name = 'symbol'")->character_maximum_length);
    }
}
