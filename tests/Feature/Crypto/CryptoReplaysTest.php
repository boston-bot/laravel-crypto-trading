<?php

namespace Tests\Feature\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\User;
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoMarketData;
use App\Services\Crypto\CryptoPolicies;
use App\Services\Crypto\CryptoReplays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CoinbasePublicFake;
use Tests\TestCase;

class CryptoReplaysTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CoinbasePublicFake $venue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22T12:00:10Z'));
        $this->owner = User::factory()->create();
        config(['mcp.operator_emails' => [$this->owner->email], 'crypto.market_data_enabled' => true, 'crypto.coinbase_products' => ['BTC-USD', 'ETH-USD']]);
        $this->venue = CoinbasePublicFake::install(['BTC-USD', 'ETH-USD']);
        app(CryptoMarketCollector::class)->tick();
    }

    private function policy(array $products = ['BTC-USD', 'ETH-USD'], int $base = 0): InstrumentPolicy
    {
        return app(CryptoPolicies::class)->apply($this->owner->id, (string) Str::uuid(), $base, costs: array_map(fn ($p) => ['version' => 'test_fee', 'product_id' => $p,
            'maker_rate' => '0.005', 'taker_rate' => '0.009', 'cost_scope' => 'product_all_in', 'observed_at' => now()->subMinute()->toISOString(),
            'valid_until' => now()->addHours(20)->toISOString(), 'source' => 'Fixture'], $products));
    }

    public function test_replay_is_recorded_separately_from_forward_paper_with_exclusions_and_frozen_retries(): void
    {
        $policy = $this->policy();
        $paths = count($this->venue->paths);
        $request = (string) Str::uuid();
        $run = app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, $request);
        $this->assertSame(['complete', 'retrospective_replay_recorded', 'crypto', null], [$run->status, $run->reason, $run->asset_class, $run->mcp_connection_id]);
        $this->assertSame('retrospective_daily_replay_not_forward_results', $run->payload['label']);
        $this->assertFalse($run->payload['broker_executable']);
        // 400 stored days; replay starts once the 200-day window exists and ends yesterday.
        $this->assertSame(['2026-04-05', '2026-10-21'], [$run->start_date->toDateString(), $run->end_date->toDateString()]);
        $this->assertSame([], $run->payload['excluded']);
        // Fixture volume (12.25 base units a day) is far below the $10M rule, so no day is liquid enough to enter.
        $this->assertSame(0, $run->payload['result']['counts']['entries']);
        $this->assertSame($paths, count($this->venue->paths), 'Replay uses stored data only');
        $this->assertSame($run->id, app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, $request)->id);
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->assertSame('400.000000000000000000', Portfolio::findOrFail($policy->paper_portfolio_id)->cash);
    }

    public function test_revised_gapped_or_unpriced_products_are_excluded_and_the_benchmark_is_required(): void
    {
        $policy = $this->policy(['BTC-USD']);
        $candle = DB::table('mcp_crypto_candles')->where(['product_id' => 'ETH-USD', 'granularity' => 'ONE_DAY'])->first();
        $run = app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid());
        $this->assertSame(['ETH-USD' => 'product_fee_schedule_unavailable_or_expired'], $run->payload['excluded']);
        $policy = $this->policy(base: 1);
        DB::table('mcp_crypto_candle_revisions')->insert(['mcp_crypto_market_read_id' => $candle->mcp_crypto_market_read_id, 'candle_id' => $candle->id, 'venue' => 'coinbase',
            'product_id' => 'ETH-USD', 'granularity' => 'ONE_DAY', 'starts_at' => $candle->starts_at, 'open' => '1', 'high' => '1', 'low' => '1', 'close' => '1',
            'volume' => '1', 'fingerprint' => str_repeat('b', 64), 'retrieved_at' => $candle->retrieved_at]);
        $this->assertSame(['ETH-USD' => 'daily_candle_revision_requires_review'], app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid())->payload['excluded']);
        DB::table('mcp_crypto_candles')->where(['product_id' => 'BTC-USD', 'granularity' => 'ONE_DAY'])->orderBy('starts_at')->skip(100)->limit(1)->delete();
        $run = app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid());
        $this->assertSame(['incomplete', 'benchmark_history_unavailable', 'daily_history_gap'], [$run->status, $run->reason, $run->payload['excluded']['BTC-USD']]);
    }

    public function test_history_backfill_extends_settled_daily_candles_and_does_not_count_as_collection_health(): void
    {
        DB::table('mcp_crypto_market_reads')->update(['finished_at' => CryptoMarketCollector::time(CarbonImmutable::now()->subHour())]);
        $this->artisan('mcp:crypto-history', ['--days' => 500])->assertSuccessful();
        $read = DB::table('mcp_crypto_market_reads')->where('trigger', 'backfill')->sole();
        $this->assertSame(['complete', 200], [$read->status, (int) $read->candles_inserted]);
        $this->assertSame(500, DB::table('mcp_crypto_candles')->where(['product_id' => 'BTC-USD', 'granularity' => 'ONE_DAY'])->count());
        $this->assertSame('2025-06-09', CarbonImmutable::parse(DB::table('mcp_crypto_candles')->where(['product_id' => 'BTC-USD', 'granularity' => 'ONE_DAY'])->min('starts_at'))->toDateString());
        $this->assertSame('critical', app(CryptoMarketData::class)->health()['status']);
        $this->assertSame(0, DB::table('mcp_crypto_product_snapshots')->where('mcp_crypto_market_read_id', $read->id)->count());
        $this->artisan('mcp:crypto-history', ['--days' => 'many'])->assertFailed();
    }

    public function test_replay_command_requires_operator_arguments_and_reports_a_labeled_summary(): void
    {
        $policy = $this->policy();
        $this->artisan('mcp:crypto-replay')->assertFailed();
        $this->artisan('mcp:crypto-replay', ['--actor' => $this->owner->id, '--portfolio' => $policy->paper_portfolio_id, '--request' => (string) Str::uuid()])
            ->expectsOutputToContain('retrospective_daily_replay_not_forward_results')->assertSuccessful();
        $this->assertSame(1, StrategyReplayRun::where('asset_class', 'crypto')->count());
    }
}
