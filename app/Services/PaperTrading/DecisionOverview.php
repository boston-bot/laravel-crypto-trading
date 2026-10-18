<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\DecisionRun;
use App\Services\Monitoring\AccountScope;
use App\Services\Monitoring\MarketCalendar;
use Carbon\CarbonImmutable;

final class DecisionOverview
{
    public function __construct(private DecisionScheduler $scheduler, private MarketCalendar $calendar) {}

    public function for(McpConnection $connection, ?array $paper): array
    {
        $query = DecisionRun::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection));
        $calls = (clone $query)->whereNotNull('call_started_at')->latest('call_started_at')->limit(2)->get();
        $lastResponse = (clone $query)->whereNotNull('response_received_at')->latest('response_received_at')->first();
        $window = $paper ? $this->scheduler->window($paper['application'], CarbonImmutable::now()) : null;
        $reason = DecisionScheduler::readiness() ?? (! $paper ? 'paper_plan_missing' : null);
        if ($reason === null && ! $window) {
            $reason = 'market_session_'.$this->calendar->at(CarbonImmutable::now())['status'];
        }
        $next = null;
        if ($reason === null && $window) {
            $key = 'scheduled:'.$paper['application']->id.':'.$window['key'];
            $next = (clone $query)->where('dedup_key', $key)->exists() ? $window['next_start'] : CarbonImmutable::now();
        }

        return ['reason' => $reason, 'interval_minutes' => $paper['application']->snapshot['rules']['decision_minutes'] ?? null,
            'next_eligible_at' => $next, 'last_call_at' => $calls->first()?->call_started_at,
            'last_response_at' => $lastResponse?->response_received_at,
            'observed_gap_seconds' => $calls->count() === 2 ? $calls[0]->call_started_at->getTimestamp() - $calls[1]->call_started_at->getTimestamp() : null,
            'runs' => (clone $query)->latest('created_at')->latest('id')->paginate(10, ['*'], 'decisions_page')->withQueryString()];
    }
}
