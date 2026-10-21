<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Services\MarketData\Alpaca\CorporateActions;
use App\Services\MarketData\Alpaca\DailyBars;
use App\Services\MarketData\Alpaca\HistoricalQuotes;
use App\Services\MarketData\Alpaca\TradingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** One completed session, at most two explicit research symbols. No broker/account or paper-ledger calls. */
final class IntradayReplayInputs
{
    public function __construct(private DailyBars $bars, private HistoricalQuotes $quotes, private TradingCalendar $calendar, private CorporateActions $actions) {}

    /** Explicit dates avoid silently substituting a session when the calendar read is incomplete. */
    public function acquireSessions(array $dates, array $symbols): array
    {
        Validator::make(compact('dates'), ['dates' => ['required', 'array', 'min:2', 'max:5'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct']])->validate();
        $ordered = $dates;
        sort($ordered);
        abort_unless(array_is_list($dates) && $ordered === $dates, 422, 'Supply consecutive sessions in ascending order.');
        $result = ['dates' => $dates, 'complete' => false, 'reason' => null, 'sessions' => [], 'requests' => 0];
        foreach ($dates as $index => $date) {
            $data = $this->acquire($date, $symbols, 390);
            $result['sessions'][] = $data;
            $result['requests'] += $data['requests'];
            if (! $data['complete']) {
                return array_replace($result, ['reason' => $data['reason']]);
            }
            if ($index > 0 && $data['previous_session'] !== $dates[$index - 1]) {
                return array_replace($result, ['reason' => 'nonconsecutive_sessions']);
            }
            foreach ($data['action_reads'] as $reads) {
                foreach ($reads as $read) {
                    foreach ($read['events'] as $event) {
                        $exDate = $event['dates']['ex_date'] ?? null;
                        if ($exDate === null || ($exDate >= $dates[0] && $exDate <= $dates[array_key_last($dates)])) {
                            return array_replace($result, ['reason' => 'overnight_action_requires_entitlement_support']);
                        }
                    }
                }
            }
        }

        return array_replace($result, ['complete' => true, 'reason' => 'retrospective_consecutive_sessions_sampled']);
    }

    public function acquire(string $date, array $symbols, int $minutes = 60): array
    {
        Validator::make(compact('date', 'symbols', 'minutes'), ['date' => ['required', 'date_format:Y-m-d'], 'symbols' => ['required', 'array', 'min:1', 'max:2'],
            'symbols.*' => ['required', 'string', 'distinct', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'], 'minutes' => ['integer', 'min:20', 'max:390', 'multiple_of:5']])->validate();
        abort_unless(config('alpaca_data.historical_quote_replay_enabled'), 409, 'Historical quote replay is disabled.');
        $day = CarbonImmutable::parse($date, 'America/New_York')->startOfDay();
        abort_unless($date <= CarbonImmutable::now('America/New_York')->toDateString() && TradingCosts::valid(TradingCosts::reviewed(), $day), 422,
            'Use a completed session covered by the reviewed cost schedule.');
        sort($symbols);
        $data = ['date' => $date, 'symbols' => $symbols, 'minutes' => $minutes, 'complete' => false, 'reason' => null, 'session' => null,
            'previous_session' => null, 'daily_reads' => [], 'action_reads' => [], 'quote_reads' => [], 'frames' => [], 'requests' => 0];
        $deadline = hrtime(true) + 180_000_000_000;
        $calendar = $this->calendar->read($day->subDays(10)->toDateString(), $date);
        $data['calendar_read'] = $calendar;
        $data['requests']++;
        if (! $calendar['complete']) {
            return array_replace($data, ['reason' => 'calendar_'.$calendar['error']]);
        }
        foreach ($calendar['sessions'] as $session) {
            if ($session['date'] === $date) {
                $data['session'] = $session;
            }
            if ($session['date'] < $date && ($data['previous_session'] === null || $session['date'] > $data['previous_session'])) {
                $data['previous_session'] = $session['date'];
            }
        }
        if (! $data['session'] || ! $data['previous_session']) {
            return array_replace($data, ['reason' => 'verified_session_or_previous_session_missing']);
        }
        $open = CarbonImmutable::parse($data['session']['opens_at']);
        $close = CarbonImmutable::parse($data['session']['closes_at']);
        abort_unless($close->lte(CarbonImmutable::now()->subMinutes(20)), 422, 'Use a fully completed historical session.');
        $end = $open->addMinutes($minutes)->min($close);
        $data['window_end'] = $end->toISOString();
        $benchmark = SignalParameters::current()['values']['benchmark_symbol'];
        $data['daily_reads'] = $this->bars->readMany(array_values(array_unique([...$symbols, $benchmark])), $open, 400, 'raw');
        // Every result carries the chunk request count; do not sum it once per symbol.
        $data['requests'] += max(array_column($data['daily_reads'], 'requests') ?: [0]);
        foreach ($data['daily_reads'] as $read) {
            if (! $read['complete'] || $read['bars'] === [] || $read['bars'][array_key_last($read['bars'])][0] !== $data['previous_session']) {
                return array_replace($data, ['reason' => 'raw_daily_history_missing_or_lagging']);
            }
        }
        // Raw quotes require raw profiles. Exclude unsupported price/quantity actions rather than mixing today's
        // split-adjusted history with historical raw quotes. Cash dividends before the flat-start session are not adjusted.
        $start = $day->subDay()->subDays(400);
        foreach (array_keys($data['daily_reads']) as $symbol) {
            for ($from = $start; $from->lte($day); $from = $to->addDay()) {
                $to = $from->addDays(365)->min($day);
                $read = $this->actions->read($symbol, $from->toDateString(), $to->toDateString());
                $data['action_reads'][$symbol][] = $read;
                $data['requests'] += $read['pages'];
                if (! $read['complete']) {
                    return array_replace($data, ['reason' => 'corporate_action_history_unavailable']);
                }
                foreach ($read['events'] as $event) {
                    if ($event['type'] !== 'cash_dividends') {
                        return array_replace($data, ['reason' => 'raw_history_action_requires_adjustment']);
                    }
                }
            }
        }
        $times = [];
        for ($time = $open; $time->lt($end->subSecond()); $time = $time->addMinutes(5)) {
            $times[] = [$time, 'decision'];
        }
        $times[] = [$end->subSecond(), 'mark'];
        foreach ($times as [$time, $kind]) {
            $frame = ['at' => $time->toISOString(), 'kind' => $kind, 'quotes' => []];
            foreach ($symbols as $symbol) {
                if (hrtime(true) >= $deadline) {
                    return array_replace($data, ['reason' => 'acquisition_budget_exhausted']);
                }
                $read = $this->quotes->at($symbol, $time);
                $data['requests']++;
                $data['quote_reads'][] = $read;
                if (! $read['complete']) {
                    return array_replace($data, ['reason' => 'historical_quote_'.$read['error']]);
                }
                $frame['quotes'][$symbol] = $read['quote']; // No record in a complete sample remains explicit unknown.
            }
            $data['frames'][] = $frame;
        }

        return array_replace($data, ['complete' => true, 'reason' => 'retrospective_intraday_inputs_sampled']);
    }
}
