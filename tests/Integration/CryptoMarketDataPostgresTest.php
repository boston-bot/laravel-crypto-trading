<?php

namespace Tests\Integration;

use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoMarketData;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CryptoMarketDataPostgresTest extends TestCase
{
    // Not RefreshDatabase: an early migrate:fresh would reorder the suite's fixture wipe (see SchemaPlacementTest).
    use DatabaseTransactions;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T23:44:29.123456Z'));
        config(['crypto.market_data_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD']]);
    }

    public function test_tables_land_in_the_application_schema(): void
    {
        foreach (['mcp_crypto_market_reads', 'mcp_crypto_product_snapshots', 'mcp_crypto_quotes', 'mcp_crypto_candles', 'mcp_crypto_candle_revisions'] as $table) {
            $this->assertNotNull(DB::selectOne("SELECT tablename FROM pg_tables WHERE schemaname = 'mcp_trading' AND tablename = ?", [$table]), $table);
        }
    }

    public function test_exact_decimals_and_microsecond_times_round_trip_and_repeat_reads_compare_exactly(): void
    {
        CoinbasePublicFake::install();
        app(CryptoMarketCollector::class)->tick();
        $snapshot = DB::table('mcp_crypto_product_snapshots')->first();
        $this->assertSame(['0.00000001', '150000000'], [CryptoMarketCollector::plain($snapshot->base_increment), CryptoMarketCollector::plain($snapshot->quote_max_size)]);
        $this->assertSame('2026-10-22 23:44:27.123456', CarbonImmutable::parse(DB::table('mcp_crypto_quotes')->value('source_at'))->utc()->format('Y-m-d H:i:s.u'));

        // numeric(38,18) returns trailing zeros; an identical second observation is not a conflict.
        $this->travel(1)->minutes();
        $second = app(CryptoMarketCollector::class)->tick();
        $this->assertSame(0, (int) DB::table('mcp_crypto_market_reads')->find($second['read_id'])->candle_conflicts);
        $this->assertSame('100.5', app(CryptoMarketData::class)->candles('BTC-USD', 'ONE_DAY', CarbonImmutable::parse('2026-10-21'), CarbonImmutable::parse('2026-10-22'))[0]['close']);
    }

    public function test_constraints_reject_impossible_rows_and_duplicate_candle_identity(): void
    {
        CoinbasePublicFake::install();
        $readId = app(CryptoMarketCollector::class)->tick()['read_id'];
        $candle = (array) DB::table('mcp_crypto_candles')->first();
        unset($candle['id']);
        $this->assertSame(0, DB::table('mcp_crypto_candles')->insertOrIgnore($candle));

        $now = CryptoMarketCollector::time(CarbonImmutable::now());
        $rejected = [
            fn () => DB::table('mcp_crypto_candles')->insert(array_replace($candle, ['starts_at' => '2020-01-01 00:00:00+00', 'ends_at' => '2020-01-01 00:05:00+00', 'low' => '101'])),
            fn () => DB::table('mcp_crypto_candles')->insert(array_replace($candle, ['starts_at' => '2020-01-01 00:00:00+00', 'ends_at' => '2020-01-01 00:05:00+00', 'granularity' => 'ONE_HOUR'])),
            fn () => DB::table('mcp_crypto_quotes')->insert(['mcp_crypto_market_read_id' => $readId, 'venue' => 'coinbase', 'product_id' => 'ETH-USD',
                'bid' => '2', 'bid_size' => '1', 'ask' => '1', 'ask_size' => '1', 'source_at' => $now, 'retrieved_at' => $now]),
            fn () => DB::table('mcp_crypto_market_reads')->where('id', $readId)->update(['venue' => 'robinhood']),
        ];
        foreach ($rejected as $index => $write) {
            try {
                DB::transaction($write);
                $this->fail("Constraint {$index} accepted an impossible row");
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0] ?? null, "Constraint {$index}");
            }
        }
    }
}
