<?php

namespace Tests\Feature\Crypto;

use App\Models\PaperTrading\InstrumentPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\StrategyReplayRun;
use App\Models\User;
use App\Services\Crypto\CryptoMarketCollector;
use App\Services\Crypto\CryptoMarketData;
use App\Services\Crypto\CryptoParameters;
use App\Services\Crypto\CryptoPolicies;
use App\Services\Crypto\CryptoReplays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
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

    public function test_variants_run_over_the_full_period_and_two_independent_halves_without_policy_effect(): void
    {
        $policy = $this->policy();
        $variants = [['id' => 'no_fast_sma_exit', 'parameters' => ['exit_on_fast_sma_close' => false]],
            ['id' => 'zero_fees', 'costs' => ['maker_rate' => '0', 'taker_rate' => '0']], ['id' => 'five_positions', 'risk' => ['max_open_positions' => 5]]];
        $run = app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid(), variants: $variants);
        $comparison = $run->payload['comparison'];
        $this->assertSame(['early' => ['2026-04-05', '2026-07-13'], 'late' => ['2026-07-14', '2026-10-21']], $comparison['windows']);
        $this->assertSame(['full', 'early', 'late'], array_keys($comparison['baseline']));
        $this->assertSame(['2026-04-05', '2026-07-13', '400.00'], [$comparison['baseline']['early']['start'], $comparison['baseline']['early']['end'], $comparison['baseline']['early']['final_equity']]);
        $this->assertSame(['no_fast_sma_exit', 'zero_fees', 'five_positions'], array_column($comparison['variants'], 'id'));
        $this->assertSame(['maker_rate' => '0', 'taker_rate' => '0'], $comparison['variants'][1]['costs']);
        $this->assertNotSame($comparison['variants'][0]['parameters_hash'], $comparison['variants'][1]['parameters_hash']);
        $this->assertSame('0.00', $comparison['variants'][1]['result']['full']['fees']);
        $this->assertSame($variants[0]['parameters'], $run->payload['input']['variants'][0]['parameters']);
        $this->assertSame(1, InstrumentPolicy::where('paper_portfolio_id', $policy->paper_portfolio_id)->count());
        $this->assertDatabaseCount('mcp_paper_sim_orders', 0);
        $this->artisan('mcp:crypto-replay', ['--actor' => $this->owner->id, '--portfolio' => $policy->paper_portfolio_id, '--request' => (string) Str::uuid(), '--variants' => '/nonexistent.json'])->assertFailed();
    }

    public function test_variants_outside_reviewed_bounds_or_duplicating_the_baseline_are_refused(): void
    {
        $policy = $this->policy();
        foreach ([[['id' => 'x', 'mode' => 'live']], [['id' => 'Bad Id']], [['id' => 'x', 'costs' => ['maker_rate' => '0.05', 'taker_rate' => '0']]],
            [['id' => 'x', 'costs' => ['taker_rate' => '0.001']]], [['id' => 'x', 'costs' => ['maker_rate' => 0, 'taker_rate' => '0']]],
            [['id' => 'x', 'parameters' => ['initial_stop_atr' => '9']]], [['id' => 'x', 'parameters' => ['slippage_bps' => '0']]], [['id' => 'x', 'risk' => ['max_open_positions' => 20]]],
            [['id' => 'x']], [['id' => 'a', 'risk' => ['max_open_positions' => 4]], ['id' => 'b', 'risk' => ['max_open_positions' => 4]]],
            array_map(fn ($i) => ['id' => 'v'.$i, 'risk' => ['max_open_positions' => $i]], range(1, 7))] as $variants) {
            try {
                app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid(), variants: $variants);
                $this->fail('Invalid research variant accepted: '.json_encode($variants));
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        $this->assertSame(0, StrategyReplayRun::count());
    }

    public function test_research_replay_of_a_regime_playbook_leaves_the_applied_policy_alone(): void
    {
        $policy = $this->policy();
        $run = app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid(),
            variants: [['id' => 'maker_fees', 'costs' => ['maker_rate' => '0.005', 'taker_rate' => '0.005']]], playbook: CryptoParameters::REGIME_TREND);
        $this->assertSame(['complete', 'regime_daily_replay_v1', 'regime_rules_v1', CryptoParameters::REGIME_TREND],
            [$run->status, $run->replay_version, $run->rule_version, $run->payload['input']['playbook']]);
        $this->assertSame(['BTC-USD'], $run->payload['symbols']);
        // The 200-day average needs 200 completed days: the first replay day is the 201st stored day.
        $this->assertSame('2026-04-05', $run->payload['result']['start']);
        $this->assertSame('100', $run->payload['input']['settings']['max_position_pct']);
        $this->assertSame('maker_fees', $run->payload['comparison']['variants'][0]['id']);
        $this->assertSame(CryptoParameters::VERSION, InstrumentPolicy::latest('version')->first()->payload['parameters']['version']);
        try {
            app(CryptoReplays::class)->run($this->owner->id, $policy->paper_portfolio_id, (string) Str::uuid(),
                variants: [['id' => 'no_exit', 'parameters' => ['exit_on_fast_sma_close' => false]]], playbook: CryptoParameters::REGIME_TREND);
            $this->fail('Swing research keys accepted for a regime playbook.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->artisan('mcp:crypto-replay', ['--actor' => $this->owner->id, '--portfolio' => $policy->paper_portfolio_id, '--request' => (string) Str::uuid(),
            '--playbook' => CryptoParameters::REGIME_MOMENTUM])->expectsOutputToContain('crypto_regime_momentum_v1')->assertSuccessful();
    }
}
