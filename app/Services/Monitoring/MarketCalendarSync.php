<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Monitoring\MarketSession;
use App\Services\MarketData\Alpaca\TradingCalendar;
use Carbon\CarbonImmutable;

/**
 * Refreshes stored XNYS sessions from a complete provider range. Days without a provider session, including
 * weekends, are stored as `holiday` (no regular session). A stored session from another source that disagrees is
 * never overwritten; it is reported and left to expire, so the calendar fails to unknown rather than guessing.
 */
final class MarketCalendarSync
{
    public function __construct(private TradingCalendar $calendar, private MarketCalendar $sessions) {}

    public function sync(): array
    {
        $today = CarbonImmutable::now('America/New_York')->startOfDay();
        $start = $today->subDays(max(0, min(30, (int) config('alpaca_data.calendar_lookback_days'))));
        $end = $today->addDays(max(1, min(90, (int) config('alpaca_data.calendar_lookahead_days'))));
        $read = $this->calendar->read($start->toDateString(), $end->toDateString());
        $outcome = ['start' => $read['start'], 'end' => $read['end'], 'error' => $read['error'], 'stored' => 0, 'conflicts' => []];
        if ($read['complete'] !== true) {
            return $outcome; // An incomplete read stores nothing.
        }
        $sessions = array_column($read['sessions'], null, 'date');
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $desired = $sessions[$date] ?? ['status' => 'holiday', 'opens_at' => null, 'closes_at' => null];
            $existing = MarketSession::where('exchange', 'XNYS')->where('session_date', $date)->first();
            if ($existing && $existing->source !== TradingCalendar::CONTRACT && ! $this->same($existing, $desired)) {
                $outcome['conflicts'][] = $date;

                continue;
            }
            $this->sessions->store($date, $desired['status'], $desired['opens_at'], $desired['closes_at'], TradingCalendar::CONTRACT);
            $outcome['stored']++;
        }

        return $outcome;
    }

    private function same(MarketSession $existing, array $desired): bool
    {
        $time = fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->getTimestamp();

        return $existing->status === $desired['status'] && $time($existing->opens_at) === $time($desired['opens_at'])
            && $time($existing->closes_at) === $time($desired['closes_at']);
    }
}
