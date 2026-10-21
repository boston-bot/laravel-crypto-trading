<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\StrategyReplayRun;
use App\Services\PaperTrading\StrategyReplay;
use App\Services\PaperTrading\StrategyReplays;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\ExecutionFixture as E;
use Tests\TestCase;

class StrategyReplaysTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$this->connection] = E::seed();
        R::enable();
    }

    private function fake(bool $benchmark = true): array
    {
        $queries = [];
        $rising = fn (int $n, int $from, int $step) => array_map(fn ($i) => $from + $i * $step, range(0, $n - 1));
        $bodies = ['ALFA' => R::series([...$rising(230, 5000, 22), ...array_fill(0, 20, 10060), ...$rising(10, 10140, 30)])];
        if ($benchmark) {
            $bodies['SPY'] = R::series($rising(260, 30000, 10));
        }
        R::fake(function ($query) use ($bodies, &$queries) {
            $queries[] = $query;

            return R::body(array_intersect_key($bodies, array_flip(explode(',', $query['symbols']))));
        });

        return $queries;
    }

    private function replay(?string $request = null, int $actor = 0)
    {
        return app(StrategyReplays::class)->run($this->connection, $actor ?: $this->connection->user_id, $request ?? (string) Str::uuid(), 3, '10000', ['ALFA']);
    }

    public function test_replays_are_labeled_immutable_encrypted_and_bound_to_their_inputs(): void
    {
        $this->fake();
        $run = $this->replay();
        $this->assertSame(['complete', 'retrospective_replay_recorded', StrategyReplay::VERSION], [$run->status, $run->reason, $run->replay_version]);
        $this->assertSame('retrospective_daily_replay_not_forward_results', $run->payload['label']);
        $this->assertSame(['ALFA'], $run->payload['symbols']);
        $this->assertSame('signal_execution_defaults', $run->payload['settings_source']);
        $this->assertSame(['start' => '2023-10-19', 'end' => '2026-10-18'], $run->payload['input']['window']);
        $this->assertNotNull($run->start_date);
        $this->assertGreaterThan(0, $run->payload['result']['sessions']);
        $this->assertContains('earnings_blackout_not_reconstructed', $run->payload['result']['limitations']);
        $this->assertFalse($run->payload['broker_executable']);
        $this->assertStringNotContainsString('retrospective', DB::table('mcp_strategy_replays')->value('payload'));
        // One read covers the symbols and benchmark; the window is the replay span plus profile warm-up.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'symbols=ALFA%2CSPY') && str_contains($request->url(), 'start=2022-09-14'));
        // Same request, same record, no further reads; same inputs give the same fingerprint.
        $sent = count(Http::recorded());
        $this->assertSame($run->id, app(StrategyReplays::class)->run($this->connection, $this->connection->user_id, $run->request_id, 3, '10000', ['ALFA'])->id);
        $this->assertCount($sent, Http::recorded());
        $this->assertSame($run->input_fingerprint, $this->replay()->input_fingerprint);
        $this->expectException(\LogicException::class);
        $run->update(['reason' => 'changed']);
    }

    public function test_requested_years_exclude_warmup_from_returns_trades_and_benchmarks(): void
    {
        $series = R::series(range(5000, 5569));
        R::fake(function ($query) use ($series) {
            $withinRequest = array_values(array_filter($series, function ($raw) use ($query) {
                $date = substr(json_decode($raw, true)['t'], 0, 10);

                return $date >= $query['start'] && $date <= $query['end'];
            }));

            return R::body(['ALFA' => $withinRequest, 'SPY' => $withinRequest]);
        });
        $run = app(StrategyReplays::class)->run($this->connection, $this->connection->user_id, (string) Str::uuid(), 1, '10000', ['ALFA']);
        $this->assertSame('complete', $run->status);
        $this->assertSame(['start' => '2025-10-19', 'end' => '2026-10-18'], $run->payload['input']['window']);
        $this->assertSame('2025-10-20', $run->start_date->toDateString()); // First session after Sunday.
        $this->assertSame('2026-10-16', $run->end_date->toDateString());
        $this->assertCount(260, $run->payload['result']['equity_curve']);
        $this->assertSame('4.8776', $run->payload['result']['benchmarks']['benchmark_buy_and_hold_pct']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'start=2024-09-14'));
    }

    public function test_window_uses_new_york_dates_and_keeps_all_five_years_of_warmup(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T00:30:00Z')); // Still October 18 in New York.
        $this->fake();
        $run = app(StrategyReplays::class)->run($this->connection, $this->connection->user_id, (string) Str::uuid(), 5, '10000', ['ALFA']);
        $this->assertSame(['start' => '2021-10-18', 'end' => '2026-10-17'], $run->payload['input']['window']);
        $this->assertSame('2026-10-18', $run->payload['input']['as_of']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'start=2020-09-13') && str_contains($request->url(), 'end=2026-10-17'));
    }

    public function test_a_window_without_replay_sessions_is_incomplete_and_retries_do_not_write_again(): void
    {
        $old = R::series(range(5000, 5259), asOf: CarbonImmutable::parse('2025-10-01T14:00:00Z'));
        R::fake(fn ($query) => R::body(['ALFA' => $old, 'SPY' => $old]));
        $request = (string) Str::uuid();
        $service = app(StrategyReplays::class);
        $run = $service->run($this->connection, $this->connection->user_id, $request, 1, '10000', ['ALFA']);
        $this->assertSame(['incomplete', 'no_sessions_with_sufficient_warmup_in_requested_window', null, null],
            [$run->status, $run->reason, $run->start_date, $run->end_date]);
        $this->assertSame([], $run->payload['result']['equity_curve']);
        $sent = count(Http::recorded());
        $this->assertSame($run->id, $service->run($this->connection, $this->connection->user_id, $request, 1, '10000', ['ALFA'])->id);
        $this->assertSame(1, StrategyReplayRun::count());
        $this->assertCount($sent, Http::recorded());
    }

    public function test_missing_benchmark_history_is_recorded_incomplete_never_a_result(): void
    {
        $this->fake(false);
        $run = $this->replay();
        $this->assertSame(['incomplete', 'benchmark_history_unavailable', null, null], [$run->status, $run->reason, $run->start_date, $run->payload['result']]);
        $this->assertSame(['SPY' => 'no_bars_returned'], $run->payload['excluded']);
    }

    public function test_replays_are_gated_operator_scoped_and_available_from_the_command(): void
    {
        $this->fake();
        try {
            $this->replay(actor: $this->connection->user_id + 1);
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        config(['alpaca_data.reference_enabled' => false]);
        try {
            $this->replay();
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        Http::assertNothingSent();
        R::enable();
        $this->artisan('mcp:strategy-replay', ['--account' => $this->connection->id, '--symbol' => ['ALFA'], '--equity' => '10000'])
            ->expectsOutputToContain('"status":"complete"')->assertSuccessful();
        $this->assertSame(1, StrategyReplayRun::count());
    }

    public function test_research_variants_replay_the_same_history_beside_the_live_baseline(): void
    {
        $this->fake();
        $run = app(StrategyReplays::class)->run($this->connection, $this->connection->user_id, (string) Str::uuid(), 3, '500', ['ALFA'], [
            ['id' => 'fractional', 'execution' => ['order_mode' => 'fractional_market']],
            ['id' => 'loose', 'parameters' => ['market_filter_sma_sessions' => 100, 'exit_on_fast_sma_close' => false]],
        ]);
        $this->assertCount(1, Http::recorded()); // one history read serves every variant
        [$fractional, $loose] = $run->payload['variants'];
        $this->assertSame(['fractional', ['order_mode' => 'fractional_market', 'market_slippage_bps' => '5']], [$fractional['id'], $fractional['execution']]);
        $this->assertSame(['fractional_market', 6, '5'], array_values($fractional['result']['execution']));
        $this->assertSame(['exit_on_fast_sma_close' => false, 'market_filter_sma_sessions' => 100], $loose['parameters']); // sorted, typed
        $this->assertSame('whole_share_limit', $run->payload['result']['execution']['order_mode']);
        $this->assertSame(array_map(fn ($v) => array_intersect_key($v, array_flip(['id', 'parameters', 'execution'])), $run->payload['variants']), $run->payload['input']['variants']);
        $this->assertNotSame($fractional['parameters_hash'], $loose['parameters_hash']);
        foreach ([
            'baseline id' => [['id' => 'baseline']],
            'owner risk limit' => [['id' => 'risky', 'parameters' => ['risk_per_trade_pct' => '5']]],
            'fractional limit' => [['id' => 'limit', 'execution' => ['order_mode' => 'fractional_limit']]],
            'slippage without market' => [['id' => 'slip', 'execution' => ['market_slippage_bps' => '5']]],
            'restated default' => [['id' => 'same', 'parameters' => ['initial_stop_atr' => '2', 'market_filter_required' => true]]],
            'untyped switch' => [['id' => 'text', 'parameters' => ['exit_on_fast_sma_close' => 'false']]],
            'out of bounds' => [['id' => 'short', 'parameters' => ['market_filter_sma_sessions' => 20]]],
            'duplicates' => [['id' => 'a', 'parameters' => ['cooldown_sessions' => 3]], ['id' => 'b', 'parameters' => ['cooldown_sessions' => 3]]],
            'too many' => array_map(fn ($i) => ['id' => 'v'.$i, 'parameters' => ['cooldown_sessions' => $i]], range(1, 7)),
        ] as $case => $variants) {
            try {
                app(StrategyReplays::class)->run($this->connection, $this->connection->user_id, (string) Str::uuid(), 3, '500', ['ALFA'], $variants);
                $this->fail('Accepted: '.$case);
            } catch (HttpException $error) {
                $this->assertSame(422, $error->getStatusCode(), $case);
            }
        }
        $this->assertSame(1, StrategyReplayRun::count());
        $this->artisan('mcp:strategy-replay', ['--account' => $this->connection->id, '--symbol' => ['ALFA'],
            '--variant' => ['fractional:order_mode=fractional_market,market_slippage_bps=10', 'loose:exit_on_fast_sma_close=false,market_filter_sma_sessions=100']])
            ->expectsOutputToContain('"id":"loose"')->assertSuccessful();
        $variants = StrategyReplayRun::latest('id')->first()->payload['variants'];
        $this->assertSame([['order_mode' => 'fractional_market', 'market_slippage_bps' => '10'], ['exit_on_fast_sma_close' => false, 'market_filter_sma_sessions' => 100]],
            [$variants[0]['execution'], $variants[1]['parameters']]);
        $this->artisan('mcp:strategy-replay', ['--account' => $this->connection->id, '--symbol' => ['ALFA'], '--variant' => ['no colon']])->assertFailed();
        $this->assertSame(2, StrategyReplayRun::count());
    }
}
