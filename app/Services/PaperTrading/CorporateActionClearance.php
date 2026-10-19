<?php

namespace App\Services\PaperTrading;

use App\Models\PaperTrading\CorporateActionRecord;
use App\Models\PaperTrading\MarketInput;
use App\Models\PaperTrading\Portfolio;
use App\Models\PaperTrading\SimulationPosition;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;

/** Bounded observed-data policy, not a promise that the provider knows every event immediately. */
final class CorporateActionClearance
{
    public function inspect(MarketInput $input, CarbonImmutable $cutoff, int $maxAge): array
    {
        $result = ['status' => 'unavailable', 'reason' => 'observed_action_policy_disabled', 'clear' => null,
            'policy' => 'alpaca_observed_since_paper_inception_v1', 'publication_delay_possible' => true];
        if (! config('paper.corporate_actions_enabled')) {
            return $result;
        }
        $result['reason'] = 'action_query_incomplete_or_stale';
        if ($input->origin !== 'broker_read' || $input->recorded_at->gt($cutoff) || $input->retrieved_at->gt($cutoff) || ($input->payload['quote']['symbol'] ?? null) !== $input->symbol) {
            return $result;
        }
        $portfolio = Portfolio::where('mcp_connection_id', $input->mcp_connection_id)->where('scope', $input->scope)->first();
        $coverage = app(CorporateActionCoverage::class)->inspect($input->payload, $cutoff, $maxAge);
        if (! $portfolio || ! in_array($coverage['status'], ['process_window_complete_events_require_review', 'process_window_complete_no_events_returned'], true)) {
            return $result;
        }
        $read = $input->payload['corporate_action_reads']['alpaca'];
        if (CarbonImmutable::parse($read['retrieved_at'])->gt($input->retrieved_at) ||
            ($read['start'] ?? '9999') > $portfolio->created_at->setTimezone('America/New_York')->toDateString() ||
            ($read['end'] ?? '') < $cutoff->setTimezone('America/New_York')->toDateString()) {
            return array_replace($result, ['reason' => 'action_window_does_not_cover_paper_history']);
        }
        if (SimulationPosition::where('paper_portfolio_id', $portfolio->id)->whereNotNull('blocked_reason')->exists()) {
            return array_replace($result, ['reason' => 'action_review_required']);
        }
        $ids = array_column($read['events'], 'id');
        foreach (CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('symbol', $input->symbol)->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->get() as $record) {
            $process = $record->payload['event']['dates']['process_date'] ?? null;
            if ($record->phase === 'block' || ($process !== null && $process >= $read['start'] && $process <= $read['end'] && ! in_array($record->provider_event_id, $ids, true))) {
                return array_replace($result, ['reason' => 'action_history_changed_requires_review']);
            }
        }
        foreach ($read['events'] as $event) {
            if (! in_array($event['type'], ['forward_splits', 'reverse_splits', 'cash_dividends'], true) || ! empty($event['missing_fields']) || ! isset($event['dates']['ex_date'])) {
                return array_replace($result, ['reason' => 'action_terms_unverified']);
            }
            if ($event['type'] === 'cash_dividends' && (($event['flags']['special'] ?? null) !== false || ($event['flags']['foreign'] ?? null) !== false ||
                ! isset($event['dates']['record_date'], $event['dates']['payable_date']) || $event['dates']['record_date'] < $event['dates']['ex_date'] ||
                $event['dates']['payable_date'] < $event['dates']['record_date'] || ($event['dates']['due_bill_on_date'] ?? null) !== null ||
                ($event['dates']['due_bill_off_date'] ?? null) !== null || ($event['dates']['due_bill_redemption_date'] ?? null) !== null)) {
                return array_replace($result, ['reason' => 'action_terms_unverified']);
            }
            if (in_array($event['type'], ['forward_splits', 'reverse_splits'], true)) {
                try {
                    $new = Decimal::from($event['rates']['new_rate'] ?? null);
                    $old = Decimal::from($event['rates']['old_rate'] ?? null);
                    if ($new->compareTo(Decimal::from('0')) <= 0 || $old->compareTo(Decimal::from('0')) <= 0 ||
                        ($event['type'] === 'forward_splits' ? $new->compareTo($old) <= 0 : $new->compareTo($old) >= 0)) {
                        return array_replace($result, ['reason' => 'action_terms_unverified']);
                    }
                } catch (\InvalidArgumentException) {
                    return array_replace($result, ['reason' => 'action_terms_unverified']);
                }
            }
            $fingerprint = PaperCorporateActions::fingerprint($event);
            if (CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $event['id'])->where('fingerprint', '!=', $fingerprint)
                ->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->exists()) {
                return array_replace($result, ['reason' => 'action_history_changed_requires_review']);
            }
            $effective = CarbonImmutable::parse($event['dates']['ex_date'], 'America/New_York')->startOfDay()->utc();
            if ($effective->gt($cutoff)) {
                continue;
            }
            $phase = $event['type'] === 'cash_dividends' && CarbonImmutable::parse($event['dates']['payable_date'], 'America/New_York')->startOfDay()->utc()->gt($cutoff) ? 'entitlement' : 'application';
            if (! CorporateActionRecord::where('paper_portfolio_id', $portfolio->id)->where('provider_event_id', $event['id'])->where('fingerprint', $fingerprint)
                ->where('phase', $phase)->whereIn('status', ['captured', 'applied', 'no_effect'])->where('recorded_at', '<=', $cutoff->format('Y-m-d H:i:s.u'))->exists()) {
                return array_replace($result, ['reason' => 'known_action_not_reconciled']);
            }
        }

        return array_replace($result, ['status' => 'observed_window_reconciled', 'reason' => null, 'clear' => true,
            'start' => $read['start'], 'end' => $read['end'], 'retrieved_at' => $read['retrieved_at']]);
    }
}
