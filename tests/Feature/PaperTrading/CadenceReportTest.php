<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\CadenceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ExecutionFixture as E;
use Tests\TestCase;

class CadenceReportTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private $portfolio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T21:00:00Z'));
        [$this->connection, $this->portfolio] = E::seed();
        app(MarketCalendar::class)->store('2026-10-20', 'regular', '2026-10-20T13:30:00Z', '2026-10-20T20:00:00Z', 'synthetic_fixture');
    }

    private function input(string $symbol, string $recorded, ?string $source, string $retrieved, array $payload = [], array $identity = []): MarketInput
    {
        return MarketInput::create(array_replace(['mcp_connection_id' => $this->connection->id, 'scope' => AccountScope::key($this->connection), 'request_id' => (string) Str::uuid(),
            'symbol' => $symbol, 'origin' => 'broker_read', 'status' => 'recorded', 'payload' => $payload ?: ['synthetic' => true],
            'source_at' => $source, 'retrieved_at' => $retrieved, 'recorded_at' => $recorded], $identity));
    }

    public function test_session_cadence_is_measured_from_stored_collection_cycles_and_signals(): void
    {
        // Two five-minute slots: three symbols in 4 s, then two symbols in 2 s; one quote has no source time.
        $this->input('AAA', '2026-10-20T13:35:01Z', '2026-10-20T13:34:58Z', '2026-10-20T13:35:00Z');
        $this->input('BBB', '2026-10-20T13:35:03Z', '2026-10-20T13:35:00Z', '2026-10-20T13:35:02Z');
        $this->input('CCC', '2026-10-20T13:35:05Z', null, '2026-10-20T13:35:04Z');
        $this->input('AAA', '2026-10-20T13:40:01Z', '2026-10-20T13:39:30Z', '2026-10-20T13:40:00Z');
        $this->input('BBB', '2026-10-20T13:40:03Z', '2026-10-20T13:40:01Z', '2026-10-20T13:40:02Z');
        $this->input('ZZZ', '2026-10-20T21:00:00Z', null, '2026-10-20T21:00:00Z'); // after the close: excluded
        $cycle = StrategyCycle::create(['mcp_connection_id' => $this->connection->id, 'paper_portfolio_id' => $this->portfolio->id, 'scope' => AccountScope::key($this->connection),
            'auth_generation' => $this->connection->auth_generation, 'actor_id' => $this->connection->user_id, 'rule_version' => 'synthetic', 'cycle_at' => '2026-10-20T13:40:00Z',
            'cutoff' => '2026-10-20T13:40:10Z', 'recorded_at' => '2026-10-20T13:40:12Z', 'payload' => []]);
        foreach ([['AAA', '2026-10-20T13:39:30Z', 'unavailable', 'quote_stale'], ['BBB', '2026-10-20T13:40:01Z', 'wait', 'trigger_not_crossed']] as [$symbol, $source, $status, $reason]) {
            StrategySignal::create(['mcp_connection_id' => $this->connection->id, 'paper_portfolio_id' => $this->portfolio->id, 'cycle_id' => $cycle->id, 'symbol' => $symbol,
                'status' => $status, 'reason' => $reason, 'rule_version' => 'synthetic', 'parameters_version' => 'synthetic', 'input_fingerprint' => str_repeat('0', 64),
                'payload' => ['evidence' => ['market_input_times' => ['source_at' => $source]]], 'cutoff' => '2026-10-20T13:40:10Z', 'recorded_at' => '2026-10-20T13:40:12Z']);
        }
        $report = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $this->assertSame(['measured', 78], [$report['status'], $report['session']['expected_slots']]);
        $c = $report['collection'];
        $this->assertSame([5, 3, 2], [$c['observations'], $c['symbols'], $c['slots_with_reads']]);
        $this->assertSame(['count' => 2, 'p50' => 2000, 'p95' => 4000, 'max' => 4000], $c['slot_span_ms']);
        $this->assertSame(['count' => 4, 'p50' => 2000, 'p95' => 30000, 'max' => 30000], $c['quote_age_at_retrieval_ms']);
        $this->assertSame(['count' => 2, 'p50' => 300000, 'p95' => 300000, 'max' => 300000], $c['refresh_interval_ms']);
        $s = $report['strategy'];
        $this->assertSame([1, 2, ['unavailable' => 1, 'wait' => 1]], [$s['cycles'], $s['signals'], $s['signal_status']]);
        $this->assertSame(['count' => 1, 'p50' => 12000, 'p95' => 12000, 'max' => 12000], $s['cycle_lag_ms']);
        // Quote ages at evaluation: 40 s (stale) and 9 s; half within the 20-second limit.
        $this->assertSame([9000, 40000, 50.0], [$s['quote_age_at_evaluation_ms']['p50'], $s['quote_age_at_evaluation_ms']['max'], $s['within_quote_max_age_pct']]);
        $this->artisan('mcp:cadence-report', ['--account' => $this->connection->id])->expectsOutputToContain('"status": "measured"')->assertSuccessful();
        $this->assertSame('session_unknown', app(CadenceReport::class)->session($this->connection, '2026-10-21')['reason']);
        $this->assertSame(0, DB::table('mcp_strategy_replays')->count());
    }

    private function quote(string $recorded, array $patch = [], array $identity = []): MarketInput
    {
        $source = CarbonImmutable::parse($recorded)->subSecond()->toIso8601String();
        $payload = array_replace_recursive(['provider' => TrustedHttp::RESOURCE, 'auth_generation' => $this->connection->auth_generation, 'complete' => true,
            'quote' => ['symbol' => 'DEMO', 'bid' => '100', 'ask' => '101', 'retrieved_at' => $recorded, 'bid_as_of' => $source, 'ask_as_of' => $source]], $patch);

        return $this->input('DEMO', $recorded, $source, $recorded, $payload, $identity);
    }

    private function cycle(string $at, string $recorded): StrategyCycle
    {
        return StrategyCycle::create(['mcp_connection_id' => $this->connection->id, 'paper_portfolio_id' => $this->portfolio->id,
            'scope' => AccountScope::key($this->connection), 'auth_generation' => $this->connection->auth_generation,
            'actor_id' => $this->connection->user_id, 'rule_version' => 'synthetic', 'cycle_at' => $at, 'cutoff' => $at, 'recorded_at' => $recorded, 'payload' => []]);
    }

    private function signal(StrategyCycle $cycle, string $symbol, mixed $source, string $recorded): void
    {
        StrategySignal::create(['mcp_connection_id' => $this->connection->id, 'paper_portfolio_id' => $this->portfolio->id,
            'cycle_id' => $cycle->id, 'symbol' => $symbol, 'status' => 'wait', 'reason' => 'synthetic', 'rule_version' => 'synthetic',
            'parameters_version' => 'synthetic', 'input_fingerprint' => str_repeat('0', 64), 'cutoff' => $cycle->cutoff,
            'payload' => ['evidence' => ['market_input_times' => ['source_at' => $source]]], 'recorded_at' => $recorded]);
    }

    public function test_live_progress_uses_only_elapsed_windows_and_excludes_future_local_evidence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20T13:40:30Z'));
        foreach (['13:30:01', '13:35:01', '13:40:01', '13:45:01'] as $time) {
            $this->quote('2026-10-20T'.$time.'Z');
        }
        $this->input('FUTURE', '2026-10-20T13:39:00Z', '2026-10-20T13:38:00Z', '2026-10-20T13:41:00Z');
        $cycle = $this->cycle('2026-10-20T13:40:00Z', '2026-10-20T13:40:10Z');
        $this->signal($cycle, 'DEMO', '2026-10-20T13:39:59Z', '2026-10-20T13:40:10Z');
        $this->signal($cycle, 'LATER', '2026-10-20T13:39:59Z', '2026-10-20T13:41:00Z');
        $laterCycle = $this->cycle('2026-10-20T13:35:00Z', '2026-10-20T13:41:00Z');
        $this->signal($laterCycle, 'LATER_CYCLE', '2026-10-20T13:34:59Z', '2026-10-20T13:35:10Z');
        $r = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $this->assertSame(['in_progress', 78, 2, 76], array_values(array_intersect_key($r['session'], array_flip(['phase', 'expected_slots', 'elapsed_slots', 'remaining_slots']))));
        $this->assertSame([3, 3, 2, 100.0], [$r['collection']['observations'], $r['collection']['slots_with_reads'],
            $r['collection']['elapsed_slots_with_reads'], $r['collection']['elapsed_slot_coverage_pct']]);
        $this->assertSame([1, 0, 1, 2], [$r['strategy']['cycles'], $r['strategy']['elapsed_cycles'], $r['strategy']['signals'], $r['strategy']['elapsed_expected_cycles']]);
        $this->assertSame(3, $r['collection']['signal_quote_quality']['usable_observations']);
        $this->assertSame(100.0, $r['collection']['signal_quote_quality']['elapsed_slot_coverage_pct']);
    }

    public function test_pending_and_just_opened_sessions_have_no_completed_window_denominator(): void
    {
        $this->quote('2026-10-20T13:30:00Z');
        $this->travelTo(CarbonImmutable::parse('2026-10-20T13:29:59Z'));
        $pending = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $this->assertSame(['pending', 0, 0, null], [$pending['session']['phase'], $pending['session']['elapsed_slots'],
            $pending['collection']['observations'], $pending['collection']['elapsed_slot_coverage_pct']]);
        $this->travelTo(CarbonImmutable::parse('2026-10-20T13:30:00Z'));
        $open = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $this->assertSame(['in_progress', 0, 1, null], [$open['session']['phase'], $open['session']['elapsed_slots'],
            $open['collection']['observations'], $open['collection']['signal_quote_quality']['elapsed_slot_coverage_pct']]);
    }

    public function test_early_close_uses_calendar_boundaries_and_excludes_the_exact_close(): void
    {
        app(MarketCalendar::class)->store('2026-11-27', 'early_close', '2026-11-27T14:30:00Z', '2026-11-27T18:00:00Z', 'synthetic_fixture');
        $this->travelTo(CarbonImmutable::parse('2026-11-27T18:00:00Z'));
        $this->quote('2026-11-27T17:59:59Z');
        $this->quote('2026-11-27T18:00:00Z');
        $this->cycle('2026-11-27T18:00:00Z', '2026-11-27T18:00:00Z');
        $r = app(CadenceReport::class)->session($this->connection, '2026-11-27');
        $this->assertSame(['complete', 42, 42, 0], [$r['session']['phase'], $r['session']['expected_slots'], $r['session']['elapsed_slots'], $r['session']['remaining_slots']]);
        $this->assertSame([1, 1, 0], [$r['collection']['observations'], $r['collection']['slots_with_reads'], $r['strategy']['cycles']]);
    }

    public function test_quote_quality_uses_signal_rules_at_recording_and_distinct_symbols_per_slot(): void
    {
        $this->quote('2026-10-20T13:35:01Z');
        $patches = [['complete' => false], ['quote' => ['bid_as_of' => '2026-10-20T13:30:00Z']],
            ['quote' => ['ask_as_of' => '2026-10-20T13:35:30Z']], ['quote' => ['retrieved_at' => 'tomorrow']],
            ['quote' => ['bid' => 100]], ['quote' => ['symbol' => 'OTHER']], ['provider' => 'untrusted'],
            ['auth_generation' => $this->connection->auth_generation + 1], ['quote' => ['ask' => '99.999999999999999999']], ['quote' => ['bid' => null]]];
        foreach ($patches as $patch) {
            $this->quote('2026-10-20T13:35:05Z', $patch);
        }
        $this->quote('2026-10-20T13:40:01Z');
        $r = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $c = $r['collection'];
        $this->assertSame(12, $c['observations']);
        $this->assertSame(['count' => 2, 'p50' => 1, 'p95' => 1, 'max' => 1], $c['symbols_per_slot']);
        $this->assertSame(11, $c['observations_per_slot']['max']);
        $quality = $c['signal_quote_quality'];
        $this->assertSame([2, 10, 1, 2], [$quality['usable_observations'], $quality['unusable_observations'], $quality['symbols_with_usable_quotes'], $quality['slots_with_usable_quotes']]);
        $this->assertSame(['count' => 1, 'p50' => 300000, 'p95' => 300000, 'max' => 300000], $quality['refresh_interval_ms']);
    }

    public function test_invalid_or_missing_times_never_count_as_fresh_or_negative_ages(): void
    {
        $this->input('FUTURE', '2026-10-20T13:35:02Z', '2026-10-20T13:35:03Z', '2026-10-20T13:35:01Z');
        $this->input('UNKNOWN', '2026-10-20T13:35:02Z', null, '2026-10-20T13:35:01Z');
        $cycle = $this->cycle('2026-10-20T13:40:00Z', '2026-10-20T13:40:10Z');
        foreach (['BAD' => 'tomorrow', 'FUTURE' => '2026-10-20T13:40:01Z', 'UNKNOWN' => null, 'VALID' => '2026-10-20T13:39:55Z'] as $symbol => $source) {
            $this->signal($cycle, $symbol, $source, '2026-10-20T13:40:10Z');
        }
        $r = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $this->assertSame([0, 1, 1], [$r['collection']['quote_age_at_retrieval_ms']['count'], $r['collection']['unknown_source_time_observations'], $r['collection']['invalid_time_observations']]);
        $this->assertSame([1, 2, 1, 5000], [$r['strategy']['unknown_quote_time_signals'], $r['strategy']['invalid_quote_time_signals'],
            $r['strategy']['quote_age_at_evaluation_ms']['count'], $r['strategy']['quote_age_at_evaluation_ms']['p50']]);
    }

    public function test_only_current_account_scope_and_broker_observations_are_measured(): void
    {
        $this->quote('2026-10-20T13:35:01Z');
        $this->quote('2026-10-20T13:35:01Z', identity: ['scope' => str_repeat('a', 64)]);
        $this->quote('2026-10-20T13:35:01Z', identity: ['origin' => 'synthetic']);
        [$other] = E::seed();
        $this->quote('2026-10-20T13:35:01Z', identity: ['mcp_connection_id' => $other->id]);
        $r = app(CadenceReport::class)->session($this->connection, '2026-10-20');
        $this->assertSame([1, 1], [$r['collection']['observations'], $r['collection']['signal_quote_quality']['usable_observations']]);
        $this->assertSame(0, DB::table('mcp_strategy_replays')->count());
        $this->assertSame(0, DB::table('mcp_strategy_cycles')->count());
    }
}
