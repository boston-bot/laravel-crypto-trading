<?php

namespace Tests\Feature\PaperTrading;

use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\StrategyCycle;
use App\Models\PaperTrading\StrategySignal;
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

    private function input(string $symbol, string $recorded, ?string $source, string $retrieved): MarketInput
    {
        return MarketInput::create(['mcp_connection_id' => $this->connection->id, 'scope' => AccountScope::key($this->connection), 'request_id' => (string) Str::uuid(),
            'symbol' => $symbol, 'origin' => 'broker_read', 'status' => 'recorded', 'payload' => ['synthetic' => true],
            'source_at' => $source, 'retrieved_at' => $retrieved, 'recorded_at' => $recorded]);
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
}
