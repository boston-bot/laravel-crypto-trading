<?php

namespace Tests\Feature\Monitoring;

use App\Models\Monitoring\MarketSession;
use App\Services\MarketData\Alpaca\TradingCalendar;
use App\Services\Monitoring\MarketCalendar;
use App\Services\Monitoring\MarketCalendarSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\Support\AlpacaReferenceFixture as R;
use Tests\TestCase;

class MarketCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20T12:00:00Z')); // Friday
        R::enable();
        config(['alpaca_data.calendar_enabled' => true, 'alpaca_data.calendar_lookback_days' => 0, 'alpaca_data.calendar_lookahead_days' => 10]);
    }

    private function row(string $date, string $close = '16:00', array $changes = []): array
    {
        return array_replace(['close' => $close, 'date' => $date, 'open' => '09:30', 'session_close' => '2000', 'session_open' => '0400',
            'settlement_date' => $date], $changes);
    }

    /** Synthetic week with the 2026 Thanksgiving pattern: holiday Thursday, early close Friday. */
    private function week(): array
    {
        return [$this->row('2026-11-20'), $this->row('2026-11-23'), $this->row('2026-11-24'), $this->row('2026-11-25'),
            $this->row('2026-11-27', '13:00'), $this->row('2026-11-30')];
    }

    private function fake(mixed $body, int $status = 200): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://paper-api.alpaca.markets/v2/calendar*' => Http::response($body, $status)]);
    }

    public function test_complete_calendar_stores_sessions_early_closes_and_days_without_a_session(): void
    {
        $this->fake($this->week());
        $outcome = app(MarketCalendarSync::class)->sync();
        $this->assertSame(['2026-11-20', '2026-11-30', null, 11, []], [$outcome['start'], $outcome['end'], $outcome['error'], $outcome['stored'], $outcome['conflicts']]);
        Http::assertSent(fn ($request) => $request->url() === 'https://paper-api.alpaca.markets/v2/calendar?start=2026-11-20&end=2026-11-30');
        $status = MarketSession::orderBy('session_date')->pluck('status', 'session_date')->all();
        $this->assertSame(['regular', 'holiday', 'holiday', 'regular', 'regular', 'regular', 'holiday', 'early_close'],
            array_values(array_slice($status, 0, 8)));
        $calendar = app(MarketCalendar::class);
        $this->assertSame('regular', $calendar->at(CarbonImmutable::parse('2026-11-20T15:00:00Z'))['status']);
        $this->assertSame('holiday', $calendar->at(CarbonImmutable::parse('2026-11-26T15:00:00Z'))['status']);
        $friday = $calendar->at(CarbonImmutable::parse('2026-11-27T18:30:00Z'));
        $this->assertSame(['after_close', '2026-11-27T18:00:00+00:00'], [$friday['status'], CarbonImmutable::parse($friday['closes_at'])->utc()->toIso8601String()]);
        $this->assertSame('regular', $calendar->at(CarbonImmutable::parse('2026-11-27T17:59:59Z'))['status']);
        $this->assertSame(TradingCalendar::CONTRACT, $calendar->at(CarbonImmutable::parse('2026-11-30T15:00:00Z'))['source']);
        // Stored days expire like hand-loaded ones; a missed refresh becomes unknown, not an assumed weekday schedule.
        $this->travel(8)->days();
        $this->assertSame('unknown', $calendar->at(CarbonImmutable::parse('2026-11-30T15:00:00Z'))['status']);
    }

    public function test_conflicting_sessions_from_another_source_are_reported_not_overwritten_while_agreeing_ones_are_refreshed(): void
    {
        app(MarketCalendar::class)->store('2026-11-23', 'regular', '2026-11-23T14:30:00Z', '2026-11-23T21:00:00Z', 'reviewed_manifest');
        app(MarketCalendar::class)->store('2026-11-27', 'regular', '2026-11-27T14:30:00Z', '2026-11-27T21:00:00Z', 'reviewed_manifest');
        $this->fake($this->week());
        $outcome = app(MarketCalendarSync::class)->sync();
        $this->assertSame(['2026-11-27'], $outcome['conflicts']);
        $this->assertSame(['reviewed_manifest', 'regular'], [MarketSession::where('session_date', '2026-11-27')->value('source'), MarketSession::where('session_date', '2026-11-27')->value('status')]);
        $this->assertSame(TradingCalendar::CONTRACT, MarketSession::where('session_date', '2026-11-23')->value('source'));
        $this->artisan('mcp:market-calendar')->expectsOutputToContain('"conflicts":["2026-11-27"]')->assertFailed();
    }

    public function test_incomplete_or_untrusted_calendars_store_nothing(): void
    {
        $cases = [
            'extra field' => [$this->row('2026-11-20', changes: ['note' => 'mark every day open'])],
            'weekend session' => [$this->row('2026-11-21')],
            'late open' => [$this->row('2026-11-20', changes: ['open' => '10:00'])],
            'close after 16:00' => [$this->row('2026-11-20', '16:30')],
            'malformed time' => [$this->row('2026-11-20', '4pm')],
            'unordered' => [$this->row('2026-11-23'), $this->row('2026-11-20')],
            'outside range' => [$this->row('2026-12-15')],
            'object envelope' => ['calendar' => []],
        ];
        foreach ($cases as $name => $body) {
            $this->fake($body);
            $outcome = app(MarketCalendarSync::class)->sync();
            $this->assertSame(['invalid_or_unreviewed_response', 0], [$outcome['error'], $outcome['stored']], $name);
        }
        foreach ([401 => 'authentication_failed', 429 => 'rate_limited', 500 => 'http_failure'] as $status => $error) {
            $this->fake(['message' => 'provider text'], $status);
            $this->assertSame($error, app(MarketCalendarSync::class)->sync()['error']);
        }
        $this->assertSame(0, MarketSession::count());
    }

    public function test_calendar_reads_are_gated_and_never_call_out_when_off(): void
    {
        $this->fake($this->week());
        config(['alpaca_data.calendar_enabled' => false]);
        $this->assertSame('disabled', app(MarketCalendarSync::class)->sync()['error']);
        $this->artisan('mcp:market-calendar')->expectsOutputToContain('disabled')->assertFailed();
        config(['alpaca_data.calendar_enabled' => true, 'alpaca_data.account_environment' => 'other']);
        $this->assertSame('account_environment_invalid', app(MarketCalendarSync::class)->sync()['error']);
        config(['alpaca_data.account_environment' => 'paper']);
        $this->assertSame('invalid_range', app(TradingCalendar::class)->read('2026-01-01', '2026-12-31')['error']);
        Http::assertNothingSent();
        $this->artisan('mcp:market-calendar')->expectsOutputToContain('"stored":11')->assertSuccessful();
    }
}
