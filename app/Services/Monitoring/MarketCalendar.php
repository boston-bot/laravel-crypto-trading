<?php

namespace App\Services\Monitoring;

use App\Models\Monitoring\MarketSession;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class MarketCalendar
{
    public function at(CarbonImmutable $time, string $exchange = 'XNYS'): array
    {
        $date = $time->setTimezone('America/New_York')->toDateString();
        $session = MarketSession::where('exchange', $exchange)->where('session_date', $date)->first();
        if (! $session || $session->retrieved_at->isFuture() || $session->retrieved_at->lt(now()->subDays(7))) {
            return ['status' => 'unknown', 'date' => $date, 'source' => null];
        }
        if ($session->status === 'holiday') {
            return ['status' => 'holiday', 'date' => $date, 'source' => $session->source];
        }
        $status = $time->lt($session->opens_at) ? 'before_open' : ($time->gte($session->closes_at) ? 'after_close' : 'regular');

        return ['status' => $status, 'date' => $date, 'source' => $session->source, 'opens_at' => $session->opens_at->toIso8601String(), 'closes_at' => $session->closes_at->toIso8601String()];
    }

    // Trusted provider adapters may populate this contract. No inferred weekday calendar.
    public function store(string $date, string $status, ?string $opens, ?string $closes, string $source): MarketSession
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! in_array($status, ['regular', 'early_close', 'holiday'], true) || trim($source) === '' ||
            ($status === 'holiday' ? ($opens !== null || $closes !== null) : ($opens === null || $closes === null))) {
            throw new InvalidArgumentException('Invalid verified market session.');
        }
        if ($status !== 'holiday') {
            $open = CarbonImmutable::parse($opens);
            $close = CarbonImmutable::parse($closes);
            if ($open->gte($close) || $open->setTimezone('America/New_York')->toDateString() !== $date || $close->setTimezone('America/New_York')->toDateString() !== $date) {
                throw new InvalidArgumentException('Session times must match the exchange date.');
            }
        }

        return MarketSession::updateOrCreate(['exchange' => 'XNYS', 'session_date' => $date], ['status' => $status,
            'opens_at' => $opens, 'closes_at' => $closes, 'source' => $source, 'retrieved_at' => now()]);
    }
}
