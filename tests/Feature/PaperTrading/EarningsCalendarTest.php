<?php

namespace Tests\Feature\PaperTrading;

use App\Models\McpOauthCredential;
use App\Models\PaperTrading\EarningsCalendarRead;
use App\Services\Broker\Agentic\Capabilities;
use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RobinhoodGateway;
use App\Services\Monitoring\MarketCalendar;
use App\Services\PaperTrading\EarningsCalendar;
use App\Services\PaperTrading\PaperMarketReferences;
use App\Services\PaperTrading\SymbolProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\McpFixture;
use Tests\TestCase;

class EarningsCalendarTest extends TestCase
{
    use RefreshDatabase;

    private $connection;

    private array $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z')); // Monday
        [$this->connection] = E::seed(['allowed_symbols' => ['ALFA', 'DEMO']]);
        config(['robinhood_mcp.enabled' => true, 'robinhood_mcp.earnings_enabled' => true,
            // The synthetic server lists only this tool; other reviewed tools would correctly read as schema changes.
            'robinhood_mcp.operations' => ['earnings_calendar' => config('robinhood_mcp.operations.earnings_calendar')]]);
        $this->tool = json_decode(file_get_contents(base_path('tests/Fixtures/Mcp/robinhood-earnings-calendar-schema.json')), true)['tool'];
        $manifest = $this->connection->capability_manifest ?? [];
        $manifest[$this->tool['name']] = $this->tool + ['fingerprint' => Capabilities::fingerprint($this->tool)];
        $this->connection->update(['capability_manifest' => $manifest]);
        // Two weeks of sessions: weekdays regular, weekends without a session.
        for ($day = CarbonImmutable::parse('2026-10-19'); $day->lte(CarbonImmutable::parse('2026-11-01')); $day = $day->addDay()) {
            $date = $day->toDateString();
            $day->isWeekend() ? app(MarketCalendar::class)->store($date, 'holiday', null, null, 'synthetic_fixture')
                : app(MarketCalendar::class)->store($date, 'regular', $date.'T13:30:00Z', $date.'T20:00:00Z', 'synthetic_fixture');
        }
    }

    private function event(string $symbol, ?string $date, ?string $timing = 'pm', bool $verified = true, ?string $actual = null, int $quarter = 3): array
    {
        return ['symbol' => $symbol, 'year' => 2026, 'quarter' => $quarter, 'eps' => ['estimate' => '1.250000', 'actual' => $actual],
            'report' => $date === null ? null : ['date' => $date, 'timing' => $timing, 'verified' => $verified]];
    }

    private function fake(array $events, ?callable $assert = null): void
    {
        McpFixture::rpcFake([$this->tool], function ($params) use ($events, $assert) {
            $assert && $assert($params);

            return ['content' => [], 'structuredContent' => ['data' => ['results' => $events], 'guide' => 'Synthetic guide text is never stored as policy.']];
        });
    }

    private function collect(): EarningsCalendarRead
    {
        return app(EarningsCalendar::class)->collect($this->connection, $this->connection->user_id, (string) Str::uuid());
    }

    private function blackout(string $symbol): array
    {
        return app(EarningsCalendar::class)->status($this->connection, $symbol, CarbonImmutable::now(), 5);
    }

    public function test_pinned_contract_matches_the_reviewed_schema_and_reads_a_market_wide_31_day_window(): void
    {
        $this->assertSame(config('robinhood_mcp.operations.earnings_calendar.fingerprint'), Capabilities::fingerprint($this->tool));
        $this->fake([$this->event('ALFA', '2026-10-21', 'am', false, null), $this->event('BETA', '2026-10-19', 'am', true, '0.280000')],
            fn ($params) => $this->assertSame(['days' => 31, 'start_date' => '2026-10-19'], $params['arguments']));
        $read = $this->collect();
        $this->assertSame(['complete', 'earnings_calendar_recorded', 2, '2026-10-19', 31], [$read->status, $read->reason, $read->event_count, $read->start_date->toDateString(), $read->days]);
        $this->assertSame(['ALFA', 2026, 3, '2026-10-21', 'am', false, '1.250000', null, 1], $read->payload['events'][0]);
        $this->assertSame('0.280000', $read->payload['events'][1][7]);
        $this->assertArrayNotHasKey('guide', $read->payload);
        $this->assertStringNotContainsString('ALFA', DB::table('mcp_earnings_calendar_reads')->value('payload'));
        Http::assertNotSent(fn ($request) => in_array($request['params']['name'] ?? null, ['get_earnings_results', 'place_equity_order'], true));
        $this->expectException(\LogicException::class);
        $read->update(['event_count' => 0]);
    }

    public function test_ambiguous_or_malformed_rows_refuse_the_whole_calendar(): void
    {
        $cases = [
            'null row' => [$this->event('ALFA', '2026-10-21'), null],
            'unreviewed timing' => [$this->event('ALFA', '2026-10-21', 'midday')],
            'malformed date' => [$this->event('ALFA', '21/10/2026')],
            'impossible quarter' => [$this->event('ALFA', '2026-10-21', quarter: 5)],
        ];
        foreach ($cases as $name => $events) {
            $this->fake($events);
            $read = $this->collect();
            $this->assertSame('incomplete', $read->status, $name);
            $this->assertSame([], $read->payload['events'], $name);
            $this->assertNull($read->event_count, $name);
        }
        // Two quarters, or two dates for one quarter, are distinct events; the earliest upcoming date governs.
        $this->fake([$this->event('ALFA', '2026-11-10', quarter: 4), $this->event('ALFA', '2026-10-30'), $this->event('ALFA', '2026-10-22', verified: false)]);
        $this->assertSame('complete', $this->collect()->status);
        $this->assertSame(['blocked', '2026-10-22'], [$this->blackout('ALFA')['status'], $this->blackout('ALFA')['next_report']['date']]);
    }

    public function test_repeated_events_merge_to_the_cautious_side_instead_of_refusing_the_calendar(): void
    {
        $confirmed = $this->event('HONX', '2026-10-22', 'am');
        $tentative = array_replace_recursive($this->event('HONX', '2026-10-22', null, false), ['eps' => ['estimate' => '2.710000']]);
        $copy = $this->event('COPY', '2026-11-02');
        $reported = $this->event('MIXD', '2026-10-19', 'am', true, '0.500000');
        $this->fake([$confirmed, $tentative, $copy, $copy, $reported, $this->event('MIXD', '2026-10-19', 'am')]);
        $read = $this->collect();
        $this->assertSame(['complete', 3, 6, 3, 2], [$read->status, $read->event_count, $read->payload['rows'], $read->payload['repeated_events'], $read->payload['conflicting_events']]);
        $events = array_column($read->payload['events'], null, 0);
        // Timing and estimate disagree: unknown. One copy is tentative: not confirmed.
        $this->assertSame(['HONX', 2026, 3, '2026-10-22', null, false, null, null, 2], $events['HONX']);
        $this->assertSame(['COPY', 2026, 3, '2026-11-02', 'pm', true, '1.250000', null, 2], $events['COPY']);
        // Only one copy has an actual: the event is still treated as not yet reported, so it blocks entries today.
        $this->assertNull($events['MIXD'][7]);
        $this->assertSame('blocked', $this->blackout('MIXD')['status']);
        $this->assertSame(['blocked', null], [$this->blackout('HONX')['status'], $this->blackout('HONX')['next_report']['timing']]);
    }

    public function test_blackout_counts_trading_sessions_and_treats_tentative_or_untimed_reports_as_real(): void
    {
        $this->fake([
            $this->event('ALFA', '2026-10-23', null, false),      // fifth session (Friday), tentative, untimed
            $this->event('BETA', '2026-10-26', 'am'),             // sixth session, after the weekend
            $this->event('GAMA', '2026-10-19', 'am', true, '1.10'), // already reported this morning
            $this->event('DELT', '2026-10-19', 'am'),             // today, not yet updated as reported
            $this->event('UNSC', null),                            // no scheduled event
        ]);
        $this->collect();
        $alfa = $this->blackout('ALFA');
        $this->assertSame(['blocked', 'earnings_within_blackout', '2026-10-23'], [$alfa['status'], $alfa['reason'], $alfa['horizon_end']]);
        $this->assertSame(['date' => '2026-10-23', 'timing' => null, 'verified' => false, 'fiscal_year' => 2026, 'fiscal_quarter' => 3], $alfa['next_report']);
        $this->assertSame(['pass', '2026-10-26'], [$this->blackout('BETA')['status'], $this->blackout('BETA')['next_report']['date']]);
        $this->assertSame(['pass', null], [$this->blackout('GAMA')['status'], $this->blackout('GAMA')['next_report']]);
        $this->assertSame('blocked', $this->blackout('DELT')['status']);
        $this->assertSame(['pass', 'no_report_within_blackout', '2026-11-18'], [$this->blackout('ZZZZ')['status'], $this->blackout('ZZZZ')['reason'], $this->blackout('ZZZZ')['window_end']]);
        $this->assertSame('pass', $this->blackout('UNSC')['status']);
    }

    public function test_missing_stale_short_or_calendarless_evidence_is_unknown_and_point_in_time(): void
    {
        $before = CarbonImmutable::now();
        $this->assertSame('earnings_calendar_missing', $this->blackout('ALFA')['reason']);
        $this->travel(1)->seconds();
        $this->fake([]);
        $this->collect();
        $this->assertSame('earnings_calendar_missing', app(EarningsCalendar::class)->status($this->connection, 'ALFA', $before, 5)['reason']);
        $this->assertSame('pass', $this->blackout('ALFA')['status']);
        // A horizon session the stored calendar does not know is unknown, not assumed open or closed.
        $this->assertSame('session_calendar_unknown', app(EarningsCalendar::class)->status($this->connection, 'ALFA', CarbonImmutable::now(), 12)['reason']);
        $this->travel(config('alpaca_data.history_max_age_hours') + 1)->hours();
        $this->assertSame('earnings_calendar_stale', $this->blackout('ALFA')['reason']);
        // A failed refresh never hides the last complete calendar, but cannot extend its freshness either.
        McpOauthCredential::where('mcp_connection_id', $this->connection->id)->update(['expires_at' => now()->addHour()]); // keep the synthetic token valid
        McpFixture::rpcFake([$this->tool], fn () => Http::response('', 503));
        $this->assertSame('incomplete', $this->collect()->status);
        $this->assertSame('earnings_calendar_stale', $this->blackout('ALFA')['reason']);
    }

    public function test_reads_are_gated_operator_scoped_idempotent_and_never_call_out_when_off(): void
    {
        $this->fake([]);
        $request = (string) Str::uuid();
        $first = app(EarningsCalendar::class)->collect($this->connection, $this->connection->user_id, $request);
        $sent = count(Http::recorded());
        $this->assertSame($first->id, app(EarningsCalendar::class)->collect($this->connection, $this->connection->user_id, $request)->id);
        $this->assertCount($sent, Http::recorded());
        try {
            app(EarningsCalendar::class)->collect($this->connection, $this->connection->user_id + 1, (string) Str::uuid());
            $this->fail();
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        config(['robinhood_mcp.earnings_enabled' => false]);
        try {
            app(RobinhoodGateway::class)->read($this->connection, Operation::EarningsCalendar, ['start_date' => '2026-10-19']);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Disabled, $failure->reason);
        }
        $this->assertSame(0, app(EarningsCalendar::class)->tick());
        $this->artisan('mcp:earnings-calendar', ['--account' => $this->connection->id])->expectsOutputToContain('disabled')->assertFailed();
        $this->assertCount($sent, Http::recorded());
        config(['robinhood_mcp.earnings_enabled' => true]);
        $this->assertSame(1, app(EarningsCalendar::class)->tick());
        $this->assertSame(1, app(EarningsCalendar::class)->tick());
        $this->artisan('mcp:earnings-calendar', ['--account' => $this->connection->id])->expectsOutputToContain('"status":"complete"')->assertSuccessful();
    }

    public function test_profiles_replace_the_unknown_earnings_blocker_with_the_calendar_result(): void
    {
        R::enable();
        $rising = fn (int $n, int $from, int $step) => array_map(fn ($i) => $from + $i * $step, range(0, $n - 1));
        $bodies = ['ALFA' => R::series([...$rising(230, 5000, 22), ...array_fill(0, 20, 10060)]), 'DEMO' => R::series([...$rising(230, 5000, 22), ...array_fill(0, 20, 10060)]),
            'SPY' => R::series($rising(250, 30000, 10))];
        R::fake(fn ($query) => R::body(array_intersect_key($bodies, array_flip(explode(',', $query['symbols'])))), ['ALFA' => R::asset('ALFA'), 'DEMO' => R::asset('DEMO')]);
        app(PaperMarketReferences::class)->collect($this->connection, $this->connection->user_id, [], (string) Str::uuid());
        $this->fake([$this->event('DEMO', '2026-10-22')]);
        $this->collect();
        config(['paper.symbol_profiles_enabled' => true]);
        $rows = app(SymbolProfiles::class)->record($this->connection, $this->connection->user_id, [], (string) Str::uuid())->keyBy('symbol');
        $this->assertSame(['breakout', [], 'pass'], [$rows['ALFA']->setup, $rows['ALFA']->payload['entry_blockers'], $rows['ALFA']->payload['earnings']['status']]);
        $this->assertSame(['breakout', ['earnings_blackout'], '2026-10-22'], [$rows['DEMO']->setup, $rows['DEMO']->payload['entry_blockers'], $rows['DEMO']->payload['earnings']['next_report']['date']]);
        $this->assertNotNull($rows['ALFA']->payload['evidence']['earnings_read_id']);
    }
}
