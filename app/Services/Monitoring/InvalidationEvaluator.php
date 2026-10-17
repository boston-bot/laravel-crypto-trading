<?php

namespace App\Services\Monitoring;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

final class InvalidationEvaluator
{
    public function __construct(private MarketCalendar $calendar) {}

    public function evaluate(array $rule, ?array $evidence, ?string $retrievedAt): string
    {
        $type = $rule['type'];
        if (in_array($type, ['fundamental_event', 'manual_review'], true)) {
            return 'review_required';
        }
        if (! $evidence || ! $retrievedAt || RiskObservations::freshness($evidence['source_as_of'] ?? null, $retrievedAt) !== 'fresh') {
            return 'unavailable';
        }
        if ($type === 'completed_session_close') {
            // A last quote labelled regular is NOT a verified daily closing bar.
            if (($evidence['kind'] ?? '') !== 'completed_daily_bar' || ($evidence['complete'] ?? false) !== true || ! isset($evidence['session_date'])) {
                return 'unavailable';
            }
            $session = $this->calendar->at(CarbonImmutable::parse($evidence['source_as_of']));
            if (($session['status'] ?? '') !== 'after_close' || $session['date'] !== $evidence['session_date'] ||
                CarbonImmutable::parse($session['closes_at'])->isFuture()) {
                return 'unavailable';
            }
            $price = $evidence['data']['close'] ?? null;
        } else {
            $price = $evidence['data']['last'] ?? null;
        }
        if ($price === null) {
            return 'unavailable';
        }
        $comparison = Decimal::from($price)->compareTo(Decimal::from($rule['threshold']));

        return ($rule['operator'] === 'lte' ? $comparison <= 0 : $comparison >= 0) ? 'breached' : 'clear';
    }
}
