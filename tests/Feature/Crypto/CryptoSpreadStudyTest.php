<?php

namespace Tests\Feature\Crypto;

use App\Models\PaperTrading\CryptoFeeObservation;
use App\Models\User;
use App\Services\Broker\Robinhood\RobinhoodSigner;
use App\Services\Crypto\CryptoSpreadStudy;
use App\Services\Crypto\RobinhoodCryptoQuotes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Mockery;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CryptoSpreadStudyTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    private array $robinhood;

    private mixed $robinhoodResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-27T03:00:00Z'));
        $this->path = sys_get_temp_dir().'/crypto-spread-study-'.uniqid().'.jsonl';
        config(['crypto.spread_study_enabled' => true, 'crypto.spread_study_symbols' => ['BTC-USD', 'ETH-USD'], 'crypto.spread_study_path' => $this->path,
            'broker.robinhood.x_api_key' => 'fake-x-api-key', 'broker.robinhood.private_api_key' => 'fake-private-key']);
        $signer = Mockery::mock(RobinhoodSigner::class);
        $signer->shouldReceive('buildHeaders')->with('GET', '/api/v1/crypto/marketdata/best_bid_ask/?symbol=BTC-USD&symbol=ETH-USD', '', 'fake-x-api-key', 'fake-private-key')
            ->andReturn(['x-api-key' => 'fake-x-api-key', 'x-signature' => 'fake-signature', 'x-timestamp' => '1']);
        $this->app->instance(RobinhoodSigner::class, $signer);
        $row = fn ($symbol) => ['symbol' => $symbol, 'price' => '100.25', 'bid_inclusive_of_sell_spread' => '99.9', 'sell_spread' => '0.0035',
            'ask_inclusive_of_buy_spread' => '100.6', 'buy_spread' => '0.0035', 'timestamp' => '2026-10-26T22:59:58.123456-04:00'];
        $this->robinhood = ['results' => [$row('BTC-USD'), $row('ETH-USD')]];
        $venue = new CoinbasePublicFake(['BTC-USD', 'ETH-USD']);
        // One fake for the whole test; cases swap the Robinhood response instead of stacking fakes.
        Http::fake(fn (Request $request) => str_starts_with($request->url(), 'https://trading.robinhood.com/')
            ? ($this->robinhoodResponse ?? Http::response($this->robinhood)) : $venue->respond($request));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_sample_pairs_robinhood_spread_inclusive_quotes_with_the_coinbase_book_in_basis_points(): void
    {
        CryptoFeeObservation::create(['venue' => 'coinbase', 'actor_id' => User::factory()->create()->id, 'trigger' => 'manual', 'status' => 'complete',
            'pricing_tier' => 'Intro', 'maker_rate' => '0.005', 'taker_rate' => '0.009', 'reasons' => [], 'response_sha256' => str_repeat('a', 64),
            'request_started_at' => now(), 'retrieved_at' => now(), 'recorded_at' => now()]);
        $result = app(CryptoSpreadStudy::class)->sample();
        $btc = $result['rows'][0];
        // Coinbase fake book 100.25 / 100.26: mid 100.255, half-spread 0.005.
        $this->assertSame(['complete', '34.41', '35.41', '90.50', '49.50'], [$btc['status'], $btc['robinhood_buy_side_bps'], $btc['robinhood_sell_side_bps'],
            $btc['coinbase_taker_side_bps'], $btc['coinbase_maker_side_bps']]);
        $this->assertSame('2026-10-27T02:59:58.123456Z', $btc['robinhood_source_at']);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === RobinhoodCryptoQuotes::SOURCE.'?symbol=BTC-USD&symbol=ETH-USD'
            && $r->hasHeader('x-signature', 'fake-signature'));
        $written = file_get_contents($this->path);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
        $this->assertSame(1, substr_count($written, "\n"));
        foreach (['fake-x-api-key', 'fake-private-key', 'fake-signature'] as $secret) {
            $this->assertStringNotContainsString($secret, $written);
        }
        $this->assertDatabaseCount('broker_orders', 0);
    }

    public function test_robinhood_failures_and_unreviewed_responses_record_only_a_generic_status(): void
    {
        foreach ([Http::response(['detail' => 'PRIVATE_PROVIDER_BODY'], 401), ['results' => [['symbol' => 'DOGE-USD']]],
            ['results' => [array_replace($this->robinhood['results'][0], ['ask_inclusive_of_buy_spread' => '99'])]],
            ['results' => [array_replace($this->robinhood['results'][0], ['bid_inclusive_of_sell_spread' => '-1'])]]] as $response) {
            $this->robinhoodResponse = is_array($response) ? Http::response($response) : $response;
            $this->assertSame(['status' => 'robinhood_unavailable'], app(CryptoSpreadStudy::class)->sample());
        }
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_BODY', file_get_contents($this->path));
        $this->artisan('mcp:crypto-spreads', ['--once' => true])->assertFailed();
        $this->robinhoodResponse = null;
        $this->artisan('mcp:crypto-spreads', ['--once' => true])->assertSuccessful();
    }

    public function test_the_study_is_default_off_and_scheduled_only_when_enabled(): void
    {
        config(['crypto.spread_study_enabled' => false]);
        Http::fake();
        $this->assertSame(['status' => 'disabled'], app(CryptoSpreadStudy::class)->sample());
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->path);
        $this->assertCount(0, Schedule::events());
        config(['crypto.spread_study_enabled' => true]);
        require base_path('routes/console.php');
        $this->assertStringContainsString('mcp:crypto-spreads --once', collect(Schedule::events())->map(fn ($e) => $e->command)->implode("\n"));
    }
}
