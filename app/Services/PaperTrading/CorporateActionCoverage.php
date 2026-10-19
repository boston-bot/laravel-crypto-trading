<?php

namespace App\Services\PaperTrading;

use App\Services\MarketData\Alpaca\CorporateActions;
use Carbon\CarbonImmutable;

/** Provider coverage/known-event evidence only. Does not infer no-event clearance or post ledger adjustments. */
final class CorporateActionCoverage
{
    public function inspect(array $payload, CarbonImmutable $cutoff, int $maxAge): array
    {
        $entry = $payload['corporate_action_reads']['alpaca'] ?? null;
        $result = ['status' => 'not_collected', 'events' => 0, 'incomplete_events' => 0, 'corporate_actions_clear' => null];
        if (! is_array($entry)) {
            return $result;
        }
        $result['status'] = 'invalid_or_stale_evidence';
        try {
            if (($entry['provider'] ?? null) !== CorporateActions::URL || ($entry['contract'] ?? null) !== 'alpaca_actions_v1_process_date_all_quality' ||
                ($entry['symbol'] ?? null) !== ($payload['quote']['symbol'] ?? null) || ($entry['data_quality'] ?? null) !== 'all' || ($entry['types'] ?? null) !== 'all' ||
                ($entry['date_basis'] ?? null) !== 'process_date' || ! is_string($entry['retrieved_at'] ?? null) ||
                ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $entry['retrieved_at']) ||
                ! is_array($entry['events'] ?? null) || ! array_is_list($entry['events'])) {
                return $result;
            }
            $retrieved = CarbonImmutable::parse($entry['retrieved_at']);
            if ($retrieved->gt($cutoff) || $retrieved->lt($cutoff->subSeconds($maxAge))) {
                return $result;
            }
            $result['events'] = count($entry['events']);
            $result['incomplete_events'] = count(array_filter($entry['events'], fn ($e) => ! empty($e['missing_fields'])));
            $result['status'] = ($entry['complete'] ?? false) === true && ($entry['error'] ?? null) === null
                ? ($result['events'] > 0 ? 'process_window_complete_events_require_review' : 'process_window_complete_no_events_returned')
                : 'query_incomplete';
        } catch (\InvalidArgumentException) {
            return $result;
        }

        return $result;
    }
}
