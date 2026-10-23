<?php

namespace Tests\Feature\Crypto;

use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoMarketData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CryptoMarketCollectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T23:44:29Z'));
        config(['crypto.market_data_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD', 'ETH-USD']]);
    }

    public function test_disabled_or_invalid_configuration_makes_no_request_and_no_record(): void
    {
        Http::fake();
        $cases = [
            'disabled' => ['crypto.market_data_enabled' => false],
            'no_products' => ['crypto.coinbase_products' => []],
            'invalid_configuration' => ['crypto.coinbase_products' => ['BTC-USD', '../orders']],
        ];
        foreach ($cases as $status => $settings) {
            config($settings + ['crypto.market_data_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD']]);
            $this->assertSame(['status' => $status], app(CryptoMarketCollector::class)->tick());
        }
        config(['crypto.coinbase_products' => ['BTC-USD', 'BTC-USD']]);
        $this->assertSame('invalid_configuration', app(CryptoMarketCollector::class)->tick()['status']);
        config(['crypto.coinbase_products' => array_map(fn ($i) => "C{$i}-USD", range(1, 26))]);
        $this->assertSame('invalid_configuration', app(CryptoMarketCollector::class)->tick()['status']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('mcp_crypto_market_reads', 0);
    }

    public function test_complete_read_stores_metadata_quotes_and_closed_candles_with_provenance(): void
    {
        $fake = CoinbasePublicFake::install();
        $result = app(CryptoMarketCollector::class)->tick(scheduled: true);

        $this->assertSame(['status' => 'complete', 'products' => 2, 'complete' => 2, 'error' => null], array_diff_key($result, ['read_id' => 1]));
        $read = DB::table('mcp_crypto_market_reads')->first();
        $this->assertSame(['coinbase', 'coinbase_advanced_public_market_v1', 'scheduled', 'complete', '["BTC-USD","ETH-USD"]'],
            [$read->venue, $read->contract, $read->trigger, $read->status, $read->requested_products]);
        // One products batch, then per product: book, five-minute window, two daily chunks.
        $this->assertSame(9, (int) $read->requests);
        $this->assertSame([1, 2, 6], [count(array_keys($fake->paths, '/api/v3/brokerage/market/products', true)), $fake->count('product_book'), $fake->count('/candles')]);
        $this->assertSame(2, DB::table('mcp_crypto_product_snapshots')->where('mcp_crypto_market_read_id', $read->id)->count());
        $this->assertSame(2, DB::table('mcp_crypto_quotes')->where('mcp_crypto_market_read_id', $read->id)->count());
        // 120-minute window from 21:44:29 floors to 21:40; closed through 23:40 => 24 candles; 400 closed UTC days.
        $this->assertSame(24, DB::table('mcp_crypto_candles')->where(['product_id' => 'BTC-USD', 'granularity' => 'FIVE_MINUTE'])->count());
        $this->assertSame(400, DB::table('mcp_crypto_candles')->where(['product_id' => 'BTC-USD', 'granularity' => 'ONE_DAY'])->count());
        $this->assertSame(848, (int) $read->candles_inserted);
        $this->assertSame(0, DB::table('mcp_crypto_candles')->where('ends_at', '>', CryptoMarketCollector::time(CarbonImmutable::now()))->count(), 'No open candle is stored');
    }

    public function test_repeated_reads_do_not_duplicate_candles_or_reread_current_daily_history(): void
    {
        $fake = CoinbasePublicFake::install();
        app(CryptoMarketCollector::class)->tick();
        $this->travel(5)->minutes();
        $result = app(CryptoMarketCollector::class)->tick();

        $this->assertSame('complete', $result['status']);
        $second = DB::table('mcp_crypto_market_reads')->find($result['read_id']);
        $this->assertSame(1 + 2 * 2, (int) $second->requests, 'Daily candles are current, so only book and five-minute reads');
        $this->assertSame(2, (int) $second->candles_inserted, 'Only the newly closed 23:40 candle per product');
        $this->assertSame(0, (int) $second->candle_conflicts);
        $this->assertSame(2 * (25 + 400), DB::table('mcp_crypto_candles')->count());
    }

    public function test_a_revised_candle_is_counted_but_never_overwrites_the_first_observation(): void
    {
        $fake = CoinbasePublicFake::install();
        app(CryptoMarketCollector::class)->tick();
        $fake->closeSuffix = '1';
        $result = app(CryptoMarketCollector::class)->tick();

        $this->assertSame(2 * 24, (int) DB::table('mcp_crypto_market_reads')->find($result['read_id'])->candle_conflicts);
        $this->assertSame(0, DB::table('mcp_crypto_candles')->where('close', '100.51')->count());
    }

    public function test_unlisted_product_makes_the_read_partial_and_stores_nothing_for_it(): void
    {
        CoinbasePublicFake::install(['BTC-USD']);
        $result = app(CryptoMarketCollector::class)->tick();

        $this->assertSame(['partial', 1, 'product_unavailable'], [$result['status'], $result['complete'], $result['error']]);
        $this->assertSame(0, DB::table('mcp_crypto_quotes')->where('product_id', 'ETH-USD')->count());
        $this->assertSame(1, DB::table('mcp_crypto_quotes')->where('product_id', 'BTC-USD')->count());
    }

    public function test_a_product_failure_stores_none_of_that_products_data(): void
    {
        $fake = CoinbasePublicFake::install();
        $fake->bookFailures['ETH-USD'] = 429;
        $result = app(CryptoMarketCollector::class)->tick();

        $this->assertSame(['partial', 'rate_limited'], [$result['status'], $result['error']]);
        foreach (['mcp_crypto_product_snapshots', 'mcp_crypto_quotes', 'mcp_crypto_candles'] as $table) {
            $this->assertSame(0, DB::table($table)->where('product_id', 'ETH-USD')->count(), $table);
        }
    }

    public function test_metadata_failure_fails_the_whole_read_without_provider_detail(): void
    {
        $fake = CoinbasePublicFake::install();
        $fake->productsFailure = 503;
        $result = app(CryptoMarketCollector::class)->tick();

        $this->assertSame(['failed', 0, 'http_failure'], [$result['status'], $result['complete'], $result['error']]);
        $this->assertSame('failed', DB::table('mcp_crypto_market_reads')->value('status'));
        $this->assertDatabaseCount('mcp_crypto_quotes', 0);
        $this->assertStringNotContainsString('secret', json_encode(DB::table('mcp_crypto_market_reads')->first()));
    }

    public function test_a_held_lock_skips_the_cycle_without_requests(): void
    {
        Http::fake();
        $lock = Cache::lock('crypto-market-collector', 600);
        $lock->get();
        try {
            $this->assertSame(['status' => 'busy'], app(CryptoMarketCollector::class)->tick());
        } finally {
            $lock->release();
        }
        Http::assertNothingSent();
    }

    public function test_stored_quotes_become_unknown_once_stale(): void
    {
        CoinbasePublicFake::install();
        app(CryptoMarketCollector::class)->tick();
        $data = app(CryptoMarketData::class);

        $quote = $data->freshQuote('BTC-USD');
        $this->assertSame(['100.25', '0.5', '100.26', '0.75', 2], [$quote['bid'], $quote['bid_size'], $quote['ask'], $quote['ask_size'], $quote['age_seconds']]);
        $this->assertNull($data->freshQuote('SOL-USD'));
        $this->travel(118)->seconds();
        $this->assertNotNull($data->freshQuote('BTC-USD'), 'Exactly the maximum age (source was 2 seconds old) is still fresh');
        $this->travel(1)->seconds();
        $this->assertNull($data->freshQuote('BTC-USD'), 'Older than the configured maximum age is unknown');

        $candles = $data->candles('BTC-USD', 'FIVE_MINUTE', CarbonImmutable::parse('2026-10-22T23:30:00Z'), CarbonImmutable::parse('2026-10-22T23:45:00Z'));
        $this->assertSame(['23:30', '23:35'], array_map(fn ($c) => $c['starts_at']->format('H:i'), $candles));
        $this->assertSame('100.5', $candles[0]['close']);
    }

    public function test_health_reflects_collection_age_and_latest_outcome(): void
    {
        $data = app(CryptoMarketData::class);
        config(['crypto.market_data_enabled' => false]);
        $this->assertSame('disabled', $data->health()['status']);
        config(['crypto.market_data_enabled' => true]);
        $this->assertSame('critical', $data->health()['status'], 'Enabled without any complete read');

        $fake = CoinbasePublicFake::install();
        app(CryptoMarketCollector::class)->tick();
        $this->assertSame('ok', $data->health()['status']);
        $fake->bookFailures['ETH-USD'] = 503;
        app(CryptoMarketCollector::class)->tick();
        $this->assertSame(['warning', 'partial', 'http_failure'], [$data->health()['status'], $data->health()['detail']['latest_status'], $data->health()['detail']['latest_error']]);
        $this->travel(16)->minutes();
        $this->assertSame('critical', $data->health()['status']);
    }

    public function test_command_reports_outcome_and_requires_once(): void
    {
        CoinbasePublicFake::install();
        $this->artisan('mcp:crypto-market')->assertFailed();
        $this->artisan('mcp:crypto-market --once')->expectsOutputToContain('"status":"complete"')->assertSuccessful();
        config(['crypto.market_data_enabled' => false]);
        $this->artisan('mcp:crypto-market --once')->expectsOutput('{"status":"disabled"}')->assertSuccessful();
    }
}
